# MathVerse security guide

MathVerse keeps its Supabase service-role key on the Laravel server and treats
Laravel as the authorization boundary. Browser requests must never receive the
service key.

## Production checklist

- Set `APP_ENV=production`, `APP_DEBUG=false`, and an HTTPS root `APP_URL`.
  Production startup rejects plaintext, credential-bearing, or path-based
  application URLs so confirmation and recovery redirects stay on MathVerse.
- Set `TRUSTED_HOSTS` only when MathVerse intentionally serves additional exact
  hostnames. Avoid wildcards unless every matching subdomain is controlled.
- Generate a unique `APP_KEY`; set `SESSION_ENCRYPT=true`,
  `SESSION_SECURE_COOKIE=true`, `SESSION_HTTP_ONLY=true`, and
  `SESSION_SAME_SITE=lax`. Production startup fails closed if debug mode or
  these session protections are misconfigured.
- Use a shared database or Redis cache for rate limiting when multiple
  application instances are running. Login, registration, and recovery limits
  include both network and email/account budgets so rotating either one alone
  does not bypass throttling.
- If production is behind a reverse proxy, trust only the proxy addresses and
  forwarded-header set supplied by that platform. Verify `$request->ip()` is
  the real client address before relying on IP-based throttles or audit data.
- Keep `SUPABASE_SERVICE_KEY`, `ADMIN_PUSH_SECRET`, VAPID private keys, SMTP
  credentials, and `APP_KEY` in the deployment secret store. Never prefix a
  private value with `VITE_`, commit it, log it, or expose it in client code.
- Use only the exact HTTPS Supabase project URL in production. MathVerse
  rejects plaintext or credential-bearing endpoints and missing/equal public
  and service credentials before making a request.
- Rotate any secret immediately if it appears in a commit, log, screenshot, or
  browser response. Rotation is required even after the value is deleted.
- Run `composer install --no-dev --classmap-authoritative` and `npm ci && npm
  run build` from the committed lock files.
- Apply every SQL migration in `database/supabase` in date order, ending with
  `2026_09_13_recovery_and_incident_alerts.sql`. The September migrations retain
  the service-role-only function grant established by the hardening migration,
  route assignment/availability alerts to Web Push, and enable the protected
  immediate quiz-receipt callback.
- Keep public registration limited to `student` and `pending_teacher`; never
  authorize from editable Auth user metadata. The final hardening migration
  enforces this again at the profile-table boundary and removes direct profile
  mutations from browser/API roles.
- Keep Supabase's access-token lifetime at one hour or less. MathVerse rejects
  its older server-side sessions after a password change; Supabase access JWTs
  used outside the application remain valid until their configured expiry.
  Normal logout also requests global refresh-session revocation before the
  encrypted Laravel session is destroyed.
- Keep recovery tokens in the URL fragment exactly as documented in the
  committed email template. Legacy query-string tokens are accepted and scrubbed
  after capture, but new links must use fragments because request URLs can be
  retained by proxies and access logs. Redact recovery parameters from those logs.
  Validation retries retain the token only in the encrypted server session,
  and a successful recovery invalidates that entire browser session.
- Confirm Row Level Security is enabled on every Supabase API table and review
  every policy. Test with anon, student, teacher, and administrator accounts;
  never rely on the service-role behavior as an RLS test. Keep regular views in
  `security_invoker` mode and do not grant browser roles access to materialized
  views containing privileged snapshots.
- Keep class membership, notification, push-subscription, bookmark, rating,
  report, and quiz-eligibility mutations behind their validated Laravel
  routes. Do not restore direct authenticated table grants for these records.
- Keep `notification_deliveries` and its `dispatch_token` column inaccessible
  to `public`, `anon`, and `authenticated`. The public receipt callback accepts
  only an exact random row capability, can send only a stored
  `quiz_result_recorded` email, returns no delivery state, and is throttled.
  Never expose or log those callback tokens.
- Keep class/quiz deletion on the server-only `set_recovery_item` transaction.
  Original rows are retained and final audits commit with the mutation; do not
  restore physical-delete grants. The legacy `delete_teacher_class` function is
  also recoverable. Deactivate accounts before any separately confirmed purge.
- Set `WEB_PUSH_ALLOWED_HOSTS` to the same minimal browser-push provider list in
  Laravel and the `send-admin-push` Edge Function.
- Use a verified sending domain for Auth and Laravel mail, publish SPF, DKIM,
  and DMARC records, and monitor delivery failures so account-security emails
  are harder to spoof and do not silently disappear.
- Terminate TLS at a trusted proxy, redirect HTTP to HTTPS, and verify HSTS and
  the Content Security Policy on the public URL.
- Restrict production logs and backups, encrypt backups, test restores, and set
  retention periods for profiles, quiz results, audit events, and notification
  delivery records.

## Routine verification

Run these before deployment and after dependency updates:

```bash
composer audit --locked
npm audit --audit-level=high
npm run build
npm run test:javascript
npm run test:sql
php artisan test
npm run test:browser
```

The GitHub workflow repeats these checks for pushes, pull requests, and weekly
scheduled runs. A separate staging workflow exercises dedicated student,
teacher, and administrator accounts every day and on demand. It verifies
browser runtime errors, notifications, Web Push prerequisites, quizzes,
Learning Hub practice and analytics, all four active games, audit filters, delivery
health, deployed identity, and the mobile Join layout. Dependabot opens update
pull requests for Composer, npm, and workflow dependencies.

Privileged administrator actions must continue to use the durable audit-intent
lifecycle. Never replace it with a deferred best-effort log. Treat a stale
pending intent as an incident: preserve the row, determine whether the action
completed, and finalize or reconcile it with an explicit recorded outcome.
Recovery mutations commit their final audit in the same transaction instead.
The only authenticated-callable recovery helper is `recovery_account_active`,
a boolean RLS predicate; mutation, aggregation and incident RPCs remain server-only.

Configure **Independent incident monitor** and an independent alert destination
to detect scheduler stops and application/email outages. See
[`docs/recovery-and-alerts.md`](docs/recovery-and-alerts.md) for private token
setup, thresholds, acknowledgement, retry behaviour and safe staging checks.

Monitor failed logins, password recovery requests, permission failures,
suspensions, administrator actions, notification-delivery failures, and unusual
request rates. Alert on repeated failures instead of storing passwords, tokens,
email confirmation links, or request bodies in logs.

MathVerse currently tells a visitor when a password-recovery email is not
registered. This is an intentional product requirement, but it permits account
enumeration despite rate limiting. A generic success response for registered
and unregistered addresses is the safer option if that requirement changes.

## Remaining high-value improvements

- Require MFA for administrators and teachers once the chosen authentication
  flow is tested end to end.
- Add a bot challenge after repeated registration, login, recovery, or join-code
  failures without replacing the server-side rate limits.
- Run an authenticated dynamic security scan against a staging deployment and
  manually test horizontal access between two students and two teachers.
- Add CSP violation reporting after selecting a private reporting endpoint, then
  remove `style-src 'unsafe-inline'` as remaining inline styles are moved into
  classes or nonce-protected stylesheets.
- Schedule access reviews for administrator accounts, deployment users,
  Supabase members, SMTP users, and GitHub collaborators.

## Reporting a vulnerability

Do not include live credentials, reset links, student records, or other personal
data in a ticket. Privately notify the repository owner with the affected route,
impact, minimal reproduction steps, and whether the issue is already being
exploited. Rotate exposed secrets and preserve relevant audit logs before making
public changes.
