-- Authenticated Unity trophy leaderboard scoped to the signed-in student's
-- grade. The caller identity comes only from auth.uid(); no client-supplied
-- student or grade identifier is accepted.

BEGIN;
SET LOCAL search_path = pg_catalog, public;
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '30s';

DO $preflight$
BEGIN
    IF to_regclass('public.mathverse_schema_migrations') IS NULL
       OR to_regclass('public.profiles') IS NULL THEN
        RAISE EXCEPTION 'The MathVerse profile schema is missing.';
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM public.mathverse_schema_migrations
        WHERE migration_key = '2026_09_13_recovery_and_incident_alerts.sql'
    ) THEN
        RAISE EXCEPTION 'Run 2026_09_13_recovery_and_incident_alerts.sql first.';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM (VALUES
            ('avatar_url'),
            ('deactivated_at'),
            ('first_name'),
            ('grade_level'),
            ('last_name'),
            ('leaderboard_alias'),
            ('level'),
            ('role'),
            ('show_on_leaderboard'),
            ('suspended_at'),
            ('trophies')
        ) AS required(column_name)
        WHERE NOT EXISTS (
            SELECT 1
            FROM information_schema.columns
            WHERE table_schema = 'public'
              AND table_name = 'profiles'
              AND columns.column_name = required.column_name
        )
    ) THEN
        RAISE EXCEPTION 'The MathVerse profiles table is missing Unity leaderboard fields.';
    END IF;
END
$preflight$;

-- Older MathVerse installs may still have a permissive profile SELECT policy
-- that was used by Unity's former client-side leaderboard. Keep authenticated
-- users able to load their own profile, but make every direct browser/game
-- profile read pass an own-row boundary. The SECURITY DEFINER leaderboard RPC
-- below remains the only client route to privacy-filtered peer rows.
ALTER TABLE public.profiles ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS mathverse_profiles_self_read_allow ON public.profiles;
CREATE POLICY mathverse_profiles_self_read_allow
ON public.profiles
AS PERMISSIVE
FOR SELECT
TO authenticated
USING (id = (SELECT auth.uid()));

DROP POLICY IF EXISTS mathverse_profiles_client_read_boundary ON public.profiles;
CREATE POLICY mathverse_profiles_client_read_boundary
ON public.profiles
AS RESTRICTIVE
FOR SELECT
TO anon, authenticated
USING (
    (SELECT auth.uid()) IS NOT NULL
    AND id = (SELECT auth.uid())
);

GRANT SELECT ON TABLE public.profiles TO authenticated;

CREATE OR REPLACE FUNCTION public.get_my_unity_trophy_leaderboard(
    p_limit integer DEFAULT 10
)
RETURNS TABLE (
    rank bigint,
    display_name text,
    trophies integer,
    level integer,
    avatar_url text,
    grade_level integer,
    is_current boolean
)
LANGUAGE sql
STABLE
SECURITY DEFINER
SET search_path = pg_catalog, public
AS $function$
    WITH current_student AS MATERIALIZED (
        SELECT profile.id, profile.grade_level
        FROM public.profiles AS profile
        WHERE profile.id = (SELECT auth.uid())
          AND profile.role = 'student'
          AND profile.grade_level BETWEEN 1 AND 6
          AND profile.suspended_at IS NULL
          AND profile.deactivated_at IS NULL
    ),
    ranked AS (
        SELECT
            profile.id,
            profile.first_name,
            profile.last_name,
            profile.leaderboard_alias,
            profile.show_on_leaderboard,
            profile.trophies,
            profile.level,
            profile.avatar_url,
            profile.grade_level,
            row_number() OVER (
                ORDER BY
                    profile.trophies DESC,
                    profile.level DESC,
                    profile.last_name ASC NULLS LAST,
                    profile.first_name ASC NULLS LAST,
                    profile.id ASC
            ) AS position
        FROM public.profiles AS profile
        CROSS JOIN current_student
        WHERE profile.role = 'student'
          AND profile.grade_level = current_student.grade_level
          AND profile.suspended_at IS NULL
          AND profile.deactivated_at IS NULL
    )
    SELECT
        ranked.position AS rank,
        CASE
            WHEN COALESCE(ranked.show_on_leaderboard, true) = false
                 AND ranked.id <> current_student.id
                THEN 'Anonymous Student'
            ELSE COALESCE(
                NULLIF(btrim(ranked.leaderboard_alias), ''),
                NULLIF(
                    btrim(concat_ws(
                        ' ',
                        COALESCE(NULLIF(btrim(ranked.first_name), ''), 'Student'),
                        CASE
                            WHEN NULLIF(btrim(ranked.last_name), '') IS NULL THEN NULL
                            ELSE left(btrim(ranked.last_name), 1) || '.'
                        END
                    )),
                    ''
                ),
                'Student'
            )
        END AS display_name,
        greatest(COALESCE(ranked.trophies, 0), 0)::integer AS trophies,
        greatest(COALESCE(ranked.level, 0), 0)::integer AS level,
        CASE
            WHEN COALESCE(ranked.show_on_leaderboard, true) = false
                 AND ranked.id <> current_student.id
                THEN NULL
            ELSE ranked.avatar_url
        END AS avatar_url,
        ranked.grade_level,
        ranked.id = current_student.id AS is_current
    FROM ranked
    CROSS JOIN current_student
    WHERE ranked.position <= greatest(3, least(COALESCE(p_limit, 10), 50))
       OR ranked.id = current_student.id
    ORDER BY ranked.position;
$function$;

REVOKE ALL ON FUNCTION public.get_my_unity_trophy_leaderboard(integer)
    FROM PUBLIC, anon, authenticated, service_role;
GRANT EXECUTE ON FUNCTION public.get_my_unity_trophy_leaderboard(integer)
    TO authenticated;

COMMENT ON FUNCTION public.get_my_unity_trophy_leaderboard(integer) IS
    'Returns a privacy-aware trophy leaderboard for the authenticated student grade, plus the current student when outside the requested top rows.';

INSERT INTO public.mathverse_schema_migrations (migration_key)
VALUES ('2026_10_20_unity_grade_leaderboard.sql')
ON CONFLICT (migration_key) DO NOTHING;

NOTIFY pgrst, 'reload schema';
COMMIT;

SELECT
    has_function_privilege(
        'authenticated',
        'public.get_my_unity_trophy_leaderboard(integer)',
        'EXECUTE'
    ) AS unity_grade_leaderboard_ready,
    NOT has_function_privilege(
        'anon',
        'public.get_my_unity_trophy_leaderboard(integer)',
        'EXECUTE'
    ) AS anonymous_grade_leaderboard_closed,
    NOT has_function_privilege(
        'service_role',
        'public.get_my_unity_trophy_leaderboard(integer)',
        'EXECUTE'
    ) AS service_role_direct_grade_leaderboard_closed;
