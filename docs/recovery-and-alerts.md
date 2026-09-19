# Recovery and proactive alerts

## Deployment

Back up the database, apply all earlier forward migrations, then run
`database/supabase/2026_09_13_recovery_and_incident_alerts.sql` in Supabase's SQL
editor **before deploying this application version**. Its transaction registers
the migration only on success. Rerunning it does not reset Trash or incident state.
Deploy the code and rebuilt assets, and refresh Laravel's configuration cache
through your existing Cloud deployment steps. Do not regenerate `APP_KEY`.
Ordinary class/quiz reads have narrow, read-only compatibility for an unapplied
trash column during a rolling deployment. New Trash/account actions still fail
closed until the migration is applied; permission errors and writes never use
this fallback. Apply SQL first rather than relying on compatibility mode.

Keep `php artisan schedule:run` running every minute. It now also runs
`incidents:check`. No SQLite database or Laravel queue worker is needed. Incident
emails use a real Laravel `MAIL_MAILER` and its existing `MAIL_*` settings;
Supabase Auth SMTP alone does not configure Laravel mail.

## Trash and Recovery

Teachers and administrators have **Trash and Recovery** in navigation.

| Action | Result |
| --- | --- |
| Delete class | End waiting/active quizzes, archive and hide the class; retain roster, design, assignments and results. |
| Restore class | Restore the same records as an archived class. Its teacher reactivates it from Class Settings after checking member grades. Timers never resume. |
| Delete/restore quiz | Hide/restore the same reusable quiz, retaining questions, versions, bookmarks, ratings and existing assignments/results. No assignments are duplicated. |
| Administrator removes shared quiz | Review pending reports atomically. Only an administrator can undo that removal; reviewed reports remain reviewed after restoration. |
| Delete account from registry | Deactivate and block dashboard/old authenticated Data API access, preserving all data. |
| Reactivate account | Require new sign-in; retain any earlier suspension and pending-teacher approval requirement. |
| Permanently delete account from Trash | Require seven full days of deactivation, typed `DELETE` and exact target confirmation. Block if it owns any class or quiz, including Trash. Auth deletion can permanently remove linked student records. |

Nothing is automatically purged from Trash. This is recoverable application
state, **not a database backup**. Avatars still need separate Storage-object
backups. Data permanently deleted before deployment cannot be recovered here.

Class confirmation fields stay mandatory. Ordinary service-role reads exclude
trashed classes/quizzes; restrictive RLS additionally hides them from browser
roles. Content/session guards prevent stale requests from editing trashed quiz
content or restarting quizzes in trashed classes. Recovery changes commit with
their final security audit. The legacy `delete_teacher_class` RPC is recoverable
too. Do not remove recovery columns/guards or regrant physical DELETE permissions
as an application rollback; use a reviewed forward correction.
Reactivation records a dedicated JWT issuance cutoff, so an older token cannot
regain Data API access. A fresh token must be issued after that cutoff's second;
allow at least one second before testing a new sign-in. The dedicated cutoff
does not alter the existing password-change session flow.

Permanent Auth deletion crosses two systems: persist a durable intent and purge
marker first, block concurrent reactivation, then delete Auth and finalize the
audit. Failed Auth deletion cancels the marker/finalizes failure while keeping
the account deactivated. An uncertain operation or failed finalization can leave
a pending intent. Inspect and reconcile it; do not repeat an irreversible action.
The UI blocks reactivation/purge while a purge marker is pending. Teacher
ownership transfer remains a separate administrator maintenance task; this
release does not automate it.

## Independent scheduler-stop observer

A stopped scheduler cannot run its own checks. Generate a private token, e.g.:

```bash
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

Set in **Laravel Cloud environment variables**, then redeploy:

```dotenv
INCIDENT_ALERTS_ENABLED=true
INCIDENT_MONITOR_TOKEN=<private generated value of at least 32 characters>
INCIDENT_WEBHOOK_URL=<optional independent HTTPS webhook>
```

For the included **Independent incident monitor** GitHub workflow, configure:

| Repository setting | Value |
| --- | --- |
| Actions variable `INCIDENT_MONITOR_ENABLED` | `true`; otherwise the job is deliberately skipped. |
| Actions secret `INCIDENT_MONITOR_URL` | `https://mathmetaverse.space/api/operations/monitor`, or the corresponding staging URL. No query string. |
| Actions secret `INCIDENT_MONITOR_TOKEN` | The exact private token in that deployment. |
| Actions secret `INCIDENT_WEBHOOK_URL` | Optional independent fallback destination; configure here **and** Cloud to cover an unreachable site/database and failed application email. |

The endpoint accepts its token **only** as `Authorization: Bearer`, fails closed
when unconfigured and returns no private operational rows. The observer refuses
redirects. Do not commit credentials, put them in URLs or expose them in screenshots.

Run the workflow manually once. It then runs approximately every five minutes
outside Laravel. GitHub scheduled jobs can be delayed/skipped under load: this
is an included observer, **not a strict uptime guarantee**. A dedicated external
cron or uptime service can run the same Node script or authenticated POST at a
reliable interval. Keep its credentials server-side. Enable GitHub failure
notifications as another fallback and verify actual email/webhook receipt.

The external check attempts administrator emails immediately through the durable
Supabase outbox, even with `QUEUE_CONNECTION=deferred` and a stopped scheduler.
The bell is durable; OS push additionally needs subscriptions and a functioning
dispatcher, so email/webhook are the independent scheduler-stop paths. An
unreachable site/database triggers the observer's optional webhook without the
app/mail pipeline. JSON includes `text`/`content` for Slack/Discord compatibility
and sanitized incident metadata, never emails, credentials or request bodies.
Use a private administrator-only destination. SMTP acceptance is not proof of
mailbox receipt; failed transports remain visible on the incident page.

## Signals and controls

| Signal | Default trigger |
| --- | --- |
| Scheduler | Heartbeat older than 180 seconds; critical at 600 seconds or unverifiable. |
| Stale privileged audit | Pending intent older than 120 seconds, or unavailable audit counts. |
| Server errors | 10 HTTP server errors within 10 minutes. |
| Handled action failures | 12 form/action error responses within 10 minutes. |
| Repeated security failures | 12 login/security failures, denied or throttled requests for one keyed identity/network hash within 10 minutes. |
| Administrator activity | One admin performs 6 high-impact security actions within 10 minutes. Review heuristic, not proof of compromise or automatic suspension. |
| Delivery failures | 5 failed non-incident email/push deliveries, or a sending lease older than 10 minutes. Incident deliveries are excluded to prevent recursive alerts. |
| Primary database | Critical health-probe latency/unreachability; independent fallback required when incident storage is also unavailable. |
| Signal pipeline | The aggregate query cannot be verified; unknown signals are not falsely marked recovered. |

Thresholds/window/cooldown are configurable with the `INCIDENT_*` variables in
the environment example. One persisted incident per signal is shared across
replicas. Notification leases prevent concurrent duplicate batches. Successful
channels and email delivery keys survive restarts/partial failures. Failed
batches retry after two minutes, slowing to hourly after five attempts;
successful batches repeat at most hourly by default. **Acknowledge** pauses
reminders. Recovery resolves the incident; recurrence or critical escalation can
alert again. External delivery is at-least-once: a crash after provider acceptance
but before receipt persistence can still cause a duplicate.

Failed requests get an `MV-...` reference in feedback/headers and sanitized
diagnostics. Administrators search it in **Incident Alerts** and deployment logs.
Diagnostics store static route patterns, HTTP status, actor IDs and keyed
identity/network hashes, not query strings, raw IPs, passwords, reset links or
request bodies. Security audits remain separate from page views. Diagnostic
events expire after 30 days through `incidents:prune`; that command never removes
audits, incident records or Trash.
Diagnostic database writes are limited to 60 per network/minute and 500
globally/minute through the configured rate-limit cache. Excess failures retain
log references but are not inserted into the diagnostic table; signal counts
are then a lower bound. Use shared cache/limiter storage for multiple replicas.

## Staging checks

Use disposable fixtures and an isolated database. Verify original IDs/child
counts, results, versions and memberships survive trash/restore. Test two
unrelated teachers, admin removals and old JWTs directly against the Data API.
Test pending/suspended reactivation, early/mismatched purge requests and failed
Auth deletion. Never permanently delete real accounts as a smoke test.

Run `php artisan incidents:check` and the independent observer against test alert
destinations. In isolated staging, pause only the heartbeat schedule until the
threshold is crossed, leaving the observer active. Verify actual mailbox/webhook
receipt, acknowledgement, recovery and recurrence. Use synthetic failures, not
production attack traffic. Mocked PHP/JS and PGlite tests do not confirm deployed
permissions or actual external receipt.
