# Supabase migrations

Run SQL files manually in the Supabase SQL Editor after backing up the database.

## Trash, account recovery and incident alerts

Apply `2026_09_13_recovery_and_incident_alerts.sql` after every earlier forward
migration and **before deploying its matching application changes**. It retains
deleted classes/quizzes in place, adds account deactivation and durable incident
state, protects old JWTs and blocks physical class/quiz deletes. It can be rerun
without resetting Trash. Do not remove its columns or guards to roll back an
application release; that would make old physical-delete code unsafe. Use a
reviewed forward correction instead.

See [`../../docs/recovery-and-alerts.md`](../../docs/recovery-and-alerts.md) for
Cloud configuration, the independent observer's GitHub secrets, retention and
safe staging tests. Mailbox receipt and actual deployed RLS still need live checks.

## Reusable quizzes and class pages

1. Back up the Supabase project.
2. Run `2026_08_27_reusable_quizzes_and_class_pages.sql` once.
3. Deploy the application commit that contains the matching code.
4. Confirm a teacher can open **My Quizzes**, **Quiz Library**, and a classroom.

The migration copies existing `quiz_sessions` and `questions` into the new reusable
quiz tables. Existing session rows and results are retained.

To undo the database portion, first restore application code from before this
feature, then run `2026_08_27_reusable_quizzes_and_class_pages_rollback.sql`.
The rollback archives newly created quiz/customization records in tables whose
names start with `rollback_` before removing the new schema.

## Archived classes and single attempts

After the reusable-quiz migration, run
`2026_08_28_archived_classes_and_single_attempts.sql`. It adds reversible class
archiving and guarantees one stored result per student per class assignment.
Any older duplicate results are copied to
`rollback_duplicate_quiz_results_20260828` before they are removed.

To undo it, run
`2026_08_28_archived_classes_and_single_attempts_rollback.sql` before rolling
back the reusable-quiz migration. The duplicate-result backup table is retained
for verification and may be dropped manually after the rollback is confirmed.

## Scheduling, retakes, quiz governance, version restore, and scale

After the August 28 migration, run
`2026_08_29_scheduling_governance_and_scale.sql` before deploying the matching
application code. The application reads the new lifecycle, eligibility,
moderation, privacy, and suspension columns during normal requests, so the SQL
migration must be applied first.

This migration adds automatic assignment start/end dates, explicit per-student
eligibility, retained retake history, class-based quiz usage counts, verified
quiz prioritization, atomic version restoration, shared-library governance,
account suspension, audit events, and query indexes. Existing class members are
made eligible for open assignments. Existing results remain counted, while
completed assignments are frozen at their current attempt count. The earlier
draft’s per-student accommodation fields are removed when this migration is
rerun.

The migration does not require the older `rollback_*_20260827` or
`rollback_*_20260828` archive tables to still exist. If those archives were
already removed after verification, their final permission cleanup is skipped.
The file is safe to rerun when upgrading an earlier August 29 installation; the
rerun installs the current restore wrapper and reports the actual reason when a
quiz version cannot be restored.

To undo it, first restore the application code from before this feature, then
run `2026_08_29_scheduling_governance_and_scale_rollback.sql`. The rollback
keeps the currently counted result for each student and assignment, archives
additional retake results and governance records in tables whose names start
with `rollback_`, and restores the August 28 one-result behavior. Verify those
archive tables and a separate database backup before deleting either one.

## Quiz regression and browser-push hotfix

For databases where the August 29 migration was already run, execute
`2026_08_30_quiz_regression_and_push_hotfix.sql` once. It is a small,
standalone migration that reinstalls version restoration, adds shared-library
ranking indexes, and creates the protected browser-push subscription table. It
does not read any `rollback_*` archive table and is safe to rerun.

Use `2026_08_30_quiz_regression_and_push_hotfix_rollback.sql` to remove only
the push-subscription table and ranking indexes. Quiz version restoration is
retained because it belongs to the August 29 governance feature.

Then run `2026_08_30_assignment_usage_and_attempt_integrity.sql`. It refreshes
quiz usage from shared-library assignment events, repairs unapproved repeat
results so only the first is counted, ignores later unapproved inserts, and
prevents client upserts from overwriting a stored attempt. The paired
`2026_08_30_assignment_usage_and_attempt_integrity_rollback.sql` restores the
former usage and result-selection behavior without deleting result rows.

Then run `2026_08_30_shared_assignment_and_quiz_reports.sql`. It makes a
multi-class shared-quiz assignment one atomic database operation, records the
customized assignment grade on the session, and recomputes popularity from
shared-library assignment events. Assignment grades must match
the selected classes and never modify class grade levels. It also preserves
quiz and question snapshots for the dedicated Active, Reviewed, and Dismissed
report queues, including when an administrator later edits or deletes the quiz.

Use `2026_08_30_shared_assignment_and_quiz_reports_rollback.sql` only after
rolling the application back. The rollback stops if a preserved report points
to a quiz that has since been deleted, preventing accidental report loss.

If assigning a quiz or joining a class reports that
`class_member_accommodations` does not exist, run
`2026_08_30_remove_stale_accommodation_triggers.sql`. An early
August 29 draft installed assignment and class-join trigger functions that
read the former accommodations table. This standalone hotfix replaces both
function bodies, removes the obsolete eligibility column if it remains, and
backfills missing eligibility without deleting quizzes, classes, or results.

Then run
`2026_08_30_repeated_shared_class_uses_and_assignment_delete.sql`. Class Uses
will count each shared-library assignment event, so assigning the same source
quiz to the same class again after its earlier assignment ends adds another
use. Assignments made by the source quiz's own creator do not count. The file
also installs the atomic deletion used for waiting and active assignments;
deleting one shared-library assignment removes one use, while ended assignment
history remains protected. The migration corrects existing counters and is
safe to rerun.

## Notifications and account security

After all August 30 files above, run
`2026_08_31_notifications_and_account_security.sql`. It creates the in-app
notification center and event triggers for teacher verification, class roster
changes, quiz assignments and scheduling, retakes and excuses, submissions,
shared-quiz use, moderation, quiz verification, and completed Auth security
changes. It also installs idempotent 5-minute quiz-start and 30-minute quiz-due
reminders and keeps `profiles.email` synchronized after Supabase confirms an
Auth email change.

The application remains usable before this migration is applied, but the bell
will stay empty and email-change completion will not synchronize the profile
email. Use `2026_08_31_notifications_and_account_security_rollback.sql` to
remove only this notification/security layer.

Copy the hosted Auth email templates and enable the two security notification
emails by following `supabase/email-templates/README.md`.

Then run `2026_08_31_notifications_delivery_channels.sql`. It adds a protected,
retryable delivery outbox. The requested application events are sent as
designed Laravel emails: teacher application receipt and decision, account
suspension/restoration, retake, excuse,
submission receipts for the initial attempt and each teacher-authorized retake,
and removal from a class. Quiz assignments, quiz availability, and other bell
events are routed to targeted Web Push after the September 7 delivery-policy
migrations.
Completed password and email-address changes stay in the bell but do not create
Web Push because Supabase Auth already sends their security emails. The original
all-admin teacher-registration and quiz-report pushes are deliberately excluded
from the outbox because their existing immediate broadcasts remain in place.

Unapproved repeat result inserts are still ignored by
`2026_08_30_assignment_usage_and_attempt_integrity.sql`. A submission-receipt
email is queued for every result row the database successfully accepts. The
initial attempt receives one receipt, and each teacher grant permits exactly one
additional immutable retake result and receipt. The final September 7 migration
also asks Laravel to send each accepted receipt immediately, while this queue
remains the fallback if the callback or mail server is unavailable. Use
`2026_08_31_notifications_delivery_channels_rollback.sql` to remove only the
delivery outbox and restore the prior notification function bodies.

Then run `2026_08_31_notification_delivery_policy_followup.sql`. This small
idempotent follow-up also upgrades projects that already installed the first two
August 31 migrations: it removes queued password/email security pushes,
reclassifies unsent authorized-retake receipts as email, and rearms premature
due reminders for the 30-minute window. Its paired `_rollback.sql` file restores
the former delivery policy but cannot retract an alert that was already sent.

Then run `2026_08_31_quiz_starting_soon_5_minutes.sql`. It upgrades existing
installations to the five-minute quiz-start reminder window and removes earlier
start reminders so eligible quizzes can be rearmed at the correct time. Alerts
already delivered by the browser cannot be retracted.

## Autonomous Learning Hub

After all August 31 files, run
`2026_09_01_autonomous_learning_hub.sql`. It creates the server-only Practice
Arena session, mastery, and protected question-instance tables. It also installs
transactional functions for staged hints and answer submission, adaptive
difficulty, spaced review, combos, XP, levels, and trophies. The application
generates reviewed Grade 1–6 problem variations on the server and never sends a
correct answer or full solution to the browser before submission.

Students can then open **Learning Hub** and use Endless Adventure, Daily Quest,
or Weak Skill Rescue without a teacher assignment. Every answer is saved, and
an unanswered problem survives a refresh.

Next run `2026_09_01_curriculum_topic_focus.sql`. It adds focused-topic
sessions for the complete Grade 1-6 three-term curriculum map. Students can
click any displayed curriculum topic and receive only questions for that topic
while retaining adaptive difficulty, mastery, hints, XP, and saved progress.
Use its paired `_rollback.sql` file to remove focused sessions and restore the
original three practice modes.

Use
`2026_09_01_autonomous_learning_hub_rollback.sql` to remove this practice data
and its scoring functions. Existing profile XP, points, levels, and trophies
are retained by the rollback.

## Security hardening

After every feature migration above, run
`2026_09_05_security_hardening.sql`. It removes schema-creation access from API
roles, removes PostgreSQL's default public function execution privilege, and
limits MathVerse `security definer` functions to the server-side service role.
It is idempotent and intentionally has no rollback because restoring public
execution would weaken the database boundary.

The same migration enables Row Level Security and removes anonymous/public
table privileges for every non-extension table currently present in the live
`public` schema. This includes original MathVerse tables that existed before
the dated migration set; existing authenticated RLS policies are preserved.
Anonymous/public privileges are also removed from non-extension views and
materialized views in that schema. Regular views are changed to
`security_invoker` so their callers remain subject to base-table RLS;
materialized views cannot provide that guarantee and are therefore denied to
all browser-facing Data API roles.

Existing non-extension functions lose implicit `PUBLIC` and anonymous
execution as well. A function intended for a direct authenticated client must
therefore receive an explicit, narrow `authenticated` grant; MathVerse's
allowlisted privileged functions are callable only through the server role.
Any unrecognized `security definer` function is denied to every Data API role
until it is deliberately reviewed and added to the allowlist.
Default table and sequence access for future anonymous/authenticated objects is
also removed, so every new direct-client capability requires an explicit grant
and RLS policy in its own reviewed migration.

Teacher class deletion uses the allowlisted `delete_teacher_class` function.
It rechecks both the class and teacher IDs and removes the class, assignments,
and dependent gameplay rows in one transaction, so a failed delete cannot
leave a partially removed classroom.

It also treats sign-up metadata as untrusted: public registration may create
only student or pending-teacher profiles, and only the server/owner may change
a profile role. Direct profile inserts, updates, and deletes are removed from
browser/API roles; validated profile forms, avatars, email, role, suspension,
class, XP, points, level, and trophy changes remain server-controlled.
Password changes also set a server-owned invalidation timestamp so Laravel
rejects older signed-in sessions on every protected request.

Class membership, notifications, push subscriptions, bookmarks, ratings,
reports, and per-student quiz eligibility are also mutated only through
validated Laravel actions. This prevents direct Data API calls from bypassing
join codes or the application workflows that enforce ownership and audit
requirements.

The hardening migration also makes execution of future functions opt-in and
removes anonymous access from existing application tables, views, and
sequences. Future tables and sequences start without Data API mutation grants.
When adding a new server RPC, grant it only to `service_role` and add its name
to the hardening allowlist; do not grant it to `public`, `anon`, or
`authenticated`. It also removes every Data API grant from retained
`rollback_*` archive tables, which remain accessible only to the database owner
for a deliberate rollback.

Before deploying, confirm Row Level Security is enabled on every table exposed
through the Supabase API and review each policy in the Supabase dashboard. The
service-role key bypasses RLS and therefore belongs only in Laravel's private
server environment; it must never use a `VITE_` prefix or appear in browser
JavaScript.

### Quiz assignments through Web Push

After the security hardening migration, run
`2026_09_07_quiz_assignment_web_push.sql`. It converts unsent quiz-assignment
emails to Web Push and routes future `quiz_assigned` events to Web Push while
leaving quiz-availability, retake, excuse, and submission delivery unchanged. The
migration explicitly restores the service-role-only grant on the replaced
delivery function. Its paired rollback restores assignment emails but cannot
retract a push alert that was already delivered.

Then run `2026_09_09_immediate_event_delivery.sql`. It routes both manually and
automatically opened `quiz_started` events to Web Push and converts their unsent
email rows. Teacher application receipt/decision, suspension/restoration,
retake, excuse, and class-removal actions contact SMTP during their Laravel
request. Failed attempts stay in the outbox for retry.

VR quiz results can be inserted directly into Supabase without a Laravel
request, so the migration enables `pg_net` and gives each protected outbox row
a random, row-scoped dispatch token. After an accepted result commits, Supabase
asynchronously calls the fixed HTTPS MathVerse receipt endpoint with that row's
ID and token. The endpoint can only claim the exact stored
`quiz_result_recorded` email; callers cannot choose an address or message, and
the outbox and tokens remain inaccessible to browser roles. If the callback
fails, the normal minute worker sends the receipt instead. The paired rollback
removes the callback and token column and restores quiz-availability email; it
retains `pg_net` in case another database feature uses it.

## Number Guess game

After the security-hardening migration, run
`2026_09_11_number_guess_game.sql` before enabling the student game page. It
creates private, server-authoritative game sessions and a persistent
grade-level leaderboard. The target number and countdown never leave the
database through direct browser access; Laravel receives only safe session
fields from the service-role-only functions. A run starts with 60 seconds and
a 1–100 range.
Each correct answer adds 15 seconds and increases the upper limit by 50.

Use `2026_09_11_number_guess_game_rollback.sql` only after rolling back the
matching application code. It permanently removes Number Guess sessions and
leaderboard scores without changing Learning Hub mastery, XP, or trophies.

## Platform insights, durable audits, and bounded ranks

After Number Guess, run
`2026_09_12_platform_insights_and_durable_audit.sql`. It creates the migration
registry and scheduler heartbeat used by **Admin → System Health**, adds
server-only bounded queries for the trophy leaderboard and teacher Learning
Hub analytics, and separates high-volume page activity from security events.

Administrator deletion, suspension/restoration, and teacher approval/denial
now create a privileged audit intent before the protected action begins. The
intent and its pending security event are inserted in one database transaction,
then finalized to either `succeeded` or `failed`. A protected action is blocked
if its durable audit intent cannot be created. Stale intents and recorded
failures are visible on System Health instead of disappearing in a deferred
queue.

The migration also registers every earlier forward SQL file. After deployment,
run Laravel's scheduler every minute so the `laravel_scheduler` heartbeat stays
current, and set `MATHVERSE_COMMIT` (or one of the supported platform commit
variables) to the deployed Git SHA. Use the paired rollback only after rolling
back the matching application code; it archives the durable outbox before
removing this layer.

## Shared Math Arcade

Next run `2026_09_12_shared_math_arcade.sql`. It adds Mental Arithmetic,
Equation Balance, Fraction Comparison, and Pattern Pulse beside Number Guess.
All questions, expected answers, countdowns, score changes, and grade-level
ranks remain server-authoritative. A question's answer and explanation are
removed before its challenge reaches the browser and are revealed only after
that question is submitted. Question families rotate through eight variants
before repeating while operands remain randomized for the student's grade.
Each answer also carries the server question sequence (and each Number Guess
request carries its seen guess count), so a network retry or second tab cannot
score the same action twice or apply it to the next hidden challenge.

This initial migration installs a shared 12-badge achievement system across
five games; the September 13 update below reduces the active catalog to four.
Its scores, badges, and leaderboards are intentionally separate from Learning
Hub mastery, XP, levels, points, and trophies. The leaderboard returns only the
configured top ranks plus the current student rather than downloading an entire
grade.

Use `2026_09_12_shared_math_arcade_rollback.sql` only after rolling back the
matching application code. It archives arcade scores and badges, removes active
sessions and server functions, and does not alter Learning Hub progress or the
existing Number Guess leaderboard.

## Philippine-day analytics and Fraction Photon retirement

After Shared Math Arcade, run
`2026_09_13_portal_timezone_and_arcade_updates.sql` before deploying the portal
update. It replaces the teacher analytics function with explicit `Asia/Manila`
calendar-day grouping and a period starting at local midnight. Database
timestamps remain UTC; no existing registration or practice timestamp is
rewritten. Ownership checks and service-role-only execution remain enforced.

Fraction Photon is no longer an active game. The remaining hub contains Number
Guess, Mental Meteor, Equation Engineer, and Pattern Pulse with 11 visible
badges. Arcade Master requires a score in all four. Retired-game sessions,
scores, and previously earned badges stay stored; new cross-game progress
counts only active games. The SQL update can be reapplied safely and registers
itself for System Health.

Laravel queue/cache tables are separate from this Supabase HTTP schema. A Cloud
environment without a configured Laravel SQL database should use
`QUEUE_CONNECTION=deferred` and working cache/rate-limit/scheduler-lock stores
(`file` on one instance, shared Redis across replicas). Explicit Cloud values
must be updated and redeployed; changing repository defaults does not replace
them. Do not create an ephemeral SQLite file just to suppress queue warnings.
See the root README for the deployment checklist and run `npm run test:sql`
for an isolated PostgreSQL verification of the update.

### Configure application email delivery

Supabase Auth continues to send sign-up, recovery, change-email, password
changed, and email-address-changed messages. The new event emails are not
Supabase Auth templates, so Laravel sends them through `MAIL_*`. Configure the
same custom SMTP provider credentials in both Supabase Auth and the deployed
Laravel environment when one sender/provider should handle all mail. Saving the
credentials in Supabase Auth does not copy them into Laravel Cloud:

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=null
MAIL_HOST=smtp.provider.example
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_TIMEOUT=10
MAIL_FROM_ADDRESS=notifications@your-domain.example
MAIL_FROM_NAME="Math MetaVerse"
```

Keep `APP_URL` equal to the deployed HTTPS root URL; email buttons are built
from it. Laravel Cloud runs the schedule declared in `routes/console.php`
automatically. For a self-hosted server, invoke Laravel's scheduler every minute:

```bash
php artisan schedule:run
```

To verify the outbox manually after deployment, run:

```bash
php artisan notifications:deliver --limit=50
```

The administrator dashboard now stops teacher approval/rejection when the
outbox or a real production mail transport is unavailable, rather than silently
changing the account while losing its decision email. Approval now claims its
exact outbox entry and contacts the mail server during the administrator
request. The success toast says the email was sent only after the mail server
accepts it; a failed immediate attempt remains queued for automatic retry. The
same immediate-attempt and durable-retry behavior applies to teacher application
receipts/denials, suspension/restoration, retakes, excuses, submission receipts,
and class removal.

### Enable browser push alerts

The push alert appears through the browser/operating system even when the
MathVerse tab is closed. Students and teachers enable it in **Account
Security**; administrators enable it on the Mainframe. Permission is per
browser/device. Production must use HTTPS. On iPhone/iPad, install MathVerse to
the Home Screen before enabling alerts; the included web manifest supports that
browser requirement.

1. Generate one VAPID key pair from the project root:

   ```bash
   node scripts/generate-vapid-keys.mjs
   ```

2. Generate a separate server-to-server secret:

   ```bash
   node scripts/generate-admin-push-secret.mjs
   ```

3. Set `WEB_PUSH_PUBLIC_KEY` and `ADMIN_PUSH_SECRET` in the Laravel environment.
   Use the generated VAPID public key and random server secret respectively.
   `WEB_PUSH_FUNCTION_URL` is optional; when omitted, Laravel uses
   `{SUPABASE_URL}/functions/v1/send-admin-push`.
4. Set the Supabase Edge Function secrets. `ADMIN_PUSH_SECRET` must exactly
   match the Laravel value. Set the endpoint allowlist to the same value used
   by Laravel (the defaults cover the major browser push providers):

   ```bash
   supabase secrets set VAPID_PUBLIC_KEY="..." VAPID_PRIVATE_KEY="..." VAPID_SUBJECT="mailto:admin@example.com" ADMIN_PUSH_SECRET="..." WEB_PUSH_ALLOWED_HOSTS="fcm.googleapis.com,updates.push.services.mozilla.com,push.services.mozilla.com,web.push.apple.com,*.notify.windows.com"
   ```

5. Deploy the included Edge Function. The Supabase legacy JWT check is disabled
   because this is a service-to-service call; the function validates the
   dedicated `ADMIN_PUSH_SECRET` before doing any work:

   ```bash
   supabase functions deploy send-admin-push --no-verify-jwt
   ```

The VAPID private key stays only in Supabase secrets. Both private values stay
out of browser JavaScript and Git, and `ADMIN_PUSH_SECRET` must never be shown
to users or included in screenshots.
