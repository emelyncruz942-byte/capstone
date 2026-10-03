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
- Set both `CACHE_STORE=redis` and `CACHE_LIMITER=redis` in production
  (or use another shared database, Memcached, or DynamoDB store). Production
  startup rejects file, array, null, failover, and other process-local
  limiters. Login, registration, recovery, API throttles, machine-request
  replay protection, application locks, and scheduled work can therefore
  share state across every application instance.
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
- Apply every forward SQL migration in `database/supabase` through
  `2026_10_02_vr_server_authority_and_request_guards.sql`. The same-day VR
  files have an intentional special order: legacy access, score submission,
  then authenticated client; continue with the September 23, September 24,
  and October 2 migrations. The latest migration removes legacy answer-key
  reads and makes score calculation and direct-Unity throttling server-owned.
- Keep public registration limited to `student` and `pending_teacher`; never
  authorize from editable Auth user metadata. The final hardening migration
  enforces this again at the profile-table boundary and removes direct profile
  mutations from browser/API roles.
- Keep Supabase's access-token lifetime at one hour or less. MathVerse rejects
  its older server-side sessions after a password change; Supabase access JWTs
  used outside the application remain valid until their configured expiry.
  Normal logout also requests global refresh-session revocation before the
  encrypted Laravel session is destroyed.
- Keep recovery and email-confirmation tokens in the URL fragment exactly as
  documented in the committed email templates. Query-string credentials are
  rejected because request URLs can be retained by proxies and access logs;
  merely scrubbing them in JavaScript would be too late. Validation retries
  retain the token only in the encrypted server session, and a successful
  recovery invalidates that entire browser session.
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
- Set `CORS_ALLOWED_ORIGINS` to comma-separated exact HTTPS browser origins.
  Production rejects wildcards, patterns, credentials, and URL paths. Native
  Unity clients do not use browser CORS and are unaffected. Machine requests
  use HMAC-SHA256 with the shared secret as the key and this exact v2 canonical
  message: `v2:<scope>:<UPPERCASE_METHOD>:<path>:<unix_timestamp>:<nonce>:`
  followed by the lowercase hexadecimal SHA-256 digest of the exact raw request
  body. Scope, method, path, body, timestamp, and nonce are therefore all bound
  to the signature; the secret itself is never transmitted. The Laravel
  monitor uses the shared limiter cache and the Edge Function uses the
  service-role-only durable nonce/rate claim RPC. Both fail closed if replay
  protection is unavailable.
- Use a verified sending domain for Auth and Laravel mail, publish SPF, DKIM,
  and DMARC records, and monitor delivery failures so account-security emails
  are harder to spoof and do not silently disappear.
- Terminate TLS at a trusted proxy, redirect HTTP to HTTPS, and verify HSTS and
  the Content Security Policy on the public URL.
- Restrict production logs and backups, encrypt backups, test restores, and set
  retention periods for profiles, quiz results, audit events, and notification
  delivery records.

## Coordinated security cutover

Treat `2026_10_02_vr_server_authority_and_request_guards.sql`, the matching
Unity build, this Laravel release, the `send-admin-push` Edge Function, and the
v2 incident-monitor workflow as one maintenance-window cutover. They are not a
rolling-compatible mixture:

- The legacy Unity build needs anonymous reads that the October 2 migration
  revokes, while the new Unity build needs RPCs that do not exist before that
  migration.
- The legacy Laravel push sender and Edge Function use the former static-secret
  request format. A v2 sender is rejected by the old receiver, and the v2 Edge
  Function rejects the old sender.
- The legacy incident observer sends a bearer credential. The v2 endpoint
  rejects it, and the v2 observer cannot authenticate to the old endpoint.

Before the window, take and verify a database backup and stage every matching
artifact. During the window, stop new VR joins, let active rooms finish or end
them, pause browser-push dispatch, and temporarily disable the included monitor
with `INCIDENT_MONITOR_ENABLED=false` while a separate uptime alert remains
active. Apply the October 2 migration, deploy the v2 Edge Function and Laravel
release, publish the matching Unity build and updated workflow, then smoke-test
an authenticated VR join/question/submission, one administrator push, and one
manual incident-monitor run before reopening traffic and re-enabling the
monitor. The migration is forward-only: on failure, keep the affected feature
offline and complete a forward repair; do not reapply legacy access or add
temporary anonymous grants.

## Routine verification

Run these before deployment and after dependency updates:

```bash
composer audit --locked
composer analyse
npm audit --audit-level=high
npm run build
npm run test:javascript
npm run test:sql
php artisan test
npm run test:browser
```

The GitHub workflow repeats these checks for pushes, pull requests, and weekly
scheduled runs. It also runs Larastan static analysis and a full-history secret
scan. Dependency review is deliberately opt-in because repository feature
availability varies. After enabling GitHub's dependency graph/dependency review
for the repository, create an Actions repository variable named
`DEPENDENCY_REVIEW_ENABLED` with the exact lowercase value `true` under
**Settings > Secrets and variables > Actions > Variables**. The job then runs
on pull requests and rejects newly introduced high-severity vulnerabilities;
without that variable it skips cleanly. Dependabot opens update pull requests
for Composer, npm, and workflow dependencies.

Set the repository variable `STAGING_BROWSER_ENABLED=true` and provide the
documented staging account secrets to enable the daily browser journeys. Set
`STAGING_SECURITY_ENABLED=true` and provide the exact HTTPS
`STAGING_BASE_URL` secret to enable the weekly unauthenticated OWASP ZAP
baseline. Both staging jobs skip cleanly until their opt-in variable is set, so
forked pull requests and new repositories do not fail because secrets are
unavailable. The browser workflow verifies runtime errors, notifications, Web
Push prerequisites, quizzes, Learning Hub practice and analytics, all four
active games, audit filters, delivery health, deployed identity, and the mobile
Join layout.

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

Password-recovery requests return the same success message for registered and
unregistered addresses. Keep that response generic, preserve the identity- and
network-scoped rate limits, and never add user-existence details to the page or
API response.

## Remaining high-value improvements

- Require MFA for administrators and teachers once the chosen authentication
  flow is tested end to end.
- Add a bot challenge after repeated registration, login, recovery, or join-code
  failures without replacing the server-side rate limits.
- Extend the automated unauthenticated ZAP baseline with authenticated staging
  scans, then manually test horizontal access between two students and two
  teachers before major releases.
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
