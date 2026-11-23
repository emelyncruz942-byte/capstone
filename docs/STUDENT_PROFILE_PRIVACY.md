# Student profile privacy

Student names and photos are anonymous to other students on leaderboards by default. This is the existing `profiles.show_on_leaderboard = false` setting; it does not make the account itself anonymous. Students still see their own account details, and teachers and administrators retain access to the student records they manage.

Students can turn **Anonymous profile** off in **Profile** to show their chosen leaderboard name. The website translates this positive privacy control into the existing database flag. Old open forms using `show_on_leaderboard` remain supported. Profile edits that omit both controls leave the saved database preference unchanged and refresh the session from the returned profile.

## Database update

Applied to MathMetaverse on November 23, 2026:

- `supabase/migrations/20261123114822_student_anonymous_profiles_default.sql`
- SQL Editor copy: `database/supabase/2026_11_23_student_anonymous_profiles_default.sql`

The update changed 66 previously public student profiles; one was already anonymous. Verification found all 67 students anonymous, with the existing 12 teacher and three administrator rows unchanged. New profiles now default to `show_on_leaderboard = false`, including profiles created by the existing website/Unity Auth trigger.

The backfill is protected by an atomic application marker. Keep the marker string unchanged: replaying the migration restores the column default without overriding a student's later opt-out. No leaderboard rows, scores, rewards, login settings, or RLS policies are rewritten.

Existing peer-facing website and Unity leaderboard functions already mask anonymous names and, where returned, their avatar URLs. Live verification confirmed anonymous peer names on the website and null peer avatar URLs in Unity. The current student's own row remains available. Teacher/admin rosters continue to use the real profile information available to those roles.

## Verification

- SQL tests cover existing students, new defaults, teacher/admin preservation, and opt-outs surviving replay.
- Existing Unity leaderboard SQL tests cover authenticated grade restrictions, anonymous names and avatar redaction, and current-user rows.
- Website profile tests cover enabling/disabling anonymity, legacy inputs, unrelated edits, and stale session preferences.
- Supabase security advisory counts were unchanged by this migration.
