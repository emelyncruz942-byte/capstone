# Staging browser verification

The Playwright suite exercises one deployed MathVerse environment through the
same browser and Laravel routes used by real users. It is deliberately serial
because the dedicated fixture accounts retain practice progress, game scores,
badges, and audit activity between runs.

## Required staging state

- Deploy the commit being tested to a valid HTTPS origin.
- Apply every forward SQL migration through
  `2026_09_13_portal_timezone_and_arcade_updates.sql`.
- Run `php artisan schedule:run` every minute. A queue worker is needed for
  worker-backed drivers such as `database` or `redis`, not `sync`, `deferred`,
  or `background`. MathVerse's event-delivery retries use the Supabase outbox.
- Configure Laravel mail, the Web Push public key and Edge Function, and the
  deployment commit identifier.
- Create dedicated, approved, non-suspended student, teacher, and administrator
  test accounts. Do not use personal accounts.
- Put the student in at least one active class. The class may have no active
  quiz, but its class detail and analytics page must remain available.
- Give the teacher at least one active class so class-wide Learning Hub
  analytics can exercise real membership boundaries.

The tests answer one Learning Hub problem, reveal or confirm an existing hint,
complete one turn in each arcade game, and finish each game run. These are
expected, idempotent staging mutations. They do not join a new class, create or
delete a quiz, approve or suspend a user, change credentials, or submit a VR
quiz attempt.

## Environment variables

```dotenv
STAGING_BASE_URL=https://staging.example.com
STAGING_STUDENT_EMAIL=browser-student@example.com
STAGING_STUDENT_PASSWORD=...
STAGING_TEACHER_EMAIL=browser-teacher@example.com
STAGING_TEACHER_PASSWORD=...
STAGING_ADMIN_EMAIL=browser-admin@example.com
STAGING_ADMIN_PASSWORD=...
```

Use the same names as encrypted GitHub Actions secrets. Passwords and service
credentials must never be placed in a repository variable, workflow log,
screenshot, trace title, or test source.

## Run locally

```bash
npm ci --ignore-scripts
npx playwright install chromium
npm run test:browser
```

Run only the mobile regression with:

```bash
npx playwright test --project=mobile-chromium
```

## Coverage matrix

| Surface | Browser evidence |
| --- | --- |
| Student/classroom | Login, enrolled-class fixture, assigned quiz surface, and class analytics |
| Notifications/data service | Authenticated notification snapshot returns renderable notification HTML |
| Learning Hub | Configured dashboard, private initial question, hint flow, answer submission, and result feedback |
| Games | Four-card hub; server start, answer, score, finish, and leaderboard path for every active game |
| Rewards | Shared arcade badges are visible and explicitly separate from Learning Hub XP/trophies |
| Teacher | Quiz management, shared library, class mastery, weak topics, activity, hints, and improvement |
| Administrator | Quiz moderation, security audit filters, migration/scheduler/data/email/push/audit health, and deployed commit |
| Web Push | Secure context, browser APIs, configured toggle, and registered MathVerse service worker |
| Mobile | The six-character class code input and complete `Join` button remain inside a Pixel 5 viewport |

System Health verifies that the mail and browser-alert delivery outbox can be
read and has no critical stuck/exhausted work. The automated browser job does
not open a third-party mailbox or create a real push subscription because those
providers are outside the application boundary. Before a production launch,
also perform one controlled end-to-end receipt test in each supported mailbox
and browser/operating-system combination.

On failure, download `staging-browser-diagnostics` from the workflow run. It
contains the HTML report plus retained screenshots, videos, and traces. The
suite also fails on uncaught page errors, console errors, or HTTP 5xx responses.
