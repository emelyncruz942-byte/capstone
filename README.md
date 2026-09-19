# MathVerse

MathVerse is a Laravel and Supabase mathematics platform for students,
teachers, and administrators. It includes classroom quiz assignment, reports,
notifications, account workflows, and an autonomous adaptive Learning Hub.

Deployment and database instructions live in
[`database/supabase/README.md`](database/supabase/README.md). Review
[`SECURITY.md`](SECURITY.md) before any production deployment.
Trash/account recovery and proactive incident monitoring setup are documented
in [`docs/recovery-and-alerts.md`](docs/recovery-and-alerts.md). Apply
`2026_09_13_recovery_and_incident_alerts.sql` **before deploying these changes**;
scheduler-stop alerts need the independent monitor configured, not just a
Laravel schedule. Registration photo uploads handle both signup response formats.

## Local setup

```bash
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate
npm ci
npm run build
php artisan serve
```

Configure the private Supabase values in `.env`, then run the Supabase SQL files
in date order. Never expose `SUPABASE_SERVICE_KEY` in browser code.

## Verification

```bash
composer audit --locked
npm audit --audit-level=high
npm run build
npm run test:javascript
npm run test:sql
php artisan test
npm run test:browser
```

The browser suite runs against a deployed HTTPS staging environment and needs
dedicated student, teacher, and administrator accounts. See
[`docs/staging-browser.md`](docs/staging-browser.md) for fixture requirements,
the seven required environment variables, and the coverage matrix. GitHub's
`staging-browser.yml` workflow runs the same journeys every day and on demand.

## September 13 portal update

Apply `database/supabase/2026_09_13_portal_timezone_and_arcade_updates.sql`
after the earlier migrations and before deploying this update. It groups
Learning Hub analytics by Philippine calendar day and retires Fraction Photon
without deleting its stored scores, sessions, or already-earned badges. Arcade
Master now requires scores in all four remaining games.

Keep Supabase's database timezone at UTC. MathVerse converts timestamps and
audit date-filter boundaries to/from `Asia/Manila`; do not manually shift
stored registration dates.

For a single-instance, Supabase-over-HTTP Cloud environment without a separate
Laravel SQL database, verify these Cloud environment variables and redeploy:

```dotenv
QUEUE_CONNECTION=deferred
CACHE_STORE=file
CACHE_LIMITER=file
SCHEDULE_CACHE_DRIVER=file
```

Explicit Cloud values override repository defaults. If you intentionally use
a persistent SQL queue, keep it configured and migrate its jobs/failed-jobs
tables and inspect worker failures in Cloud or Laravel's configured failure
storage. The health page monitors the Supabase delivery outbox, not Laravel's
optional queue. Multiple
application replicas need shared cache, rate-limit, and scheduler-lock storage
(for example Redis), not per-instance files. Do not switch a working Redis
configuration to files. Sessions also need a working backend: an encrypted
cookie driver or shared Redis is appropriate when there is no Laravel SQL
database; `SESSION_DRIVER=database` still requires its own SQL session table.
Remove a Cloud worker explicitly pinned to the `database` connection when no
SQL queue is intended; a deferred-only deployment does not need that worker.

The browser-side regression tests run locally with `npm run test:javascript`
and in CI. They cover form actions across roles, submit-button overrides,
Enter-key submissions, stuck requests, uncertain POST outcomes, legacy reset
links, and unsaved avatar previews without sending real application actions.
Public authentication forms use native browser submissions to preserve
redirects and one-time session feedback.

Teacher class deletion additionally requires the explicit class-deletion
confirmation field. A misdirected student or assignment removal request cannot
delete the class, including requests from old open tabs.

The reset page accepts existing `?token_hash=...&type=recovery` links as well as
the recommended fragment-based links and clears credentials from the address
bar after capture. Keep new Supabase email templates fragment-based to avoid
placing credentials in query logs, and request a fresh link if an old one has
expired or already been used.

---

<details>
<summary>Laravel framework reference</summary>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

</details>
