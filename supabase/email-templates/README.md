# MathVerse Supabase email setup

These templates are designed for Supabase Auth. For the hosted project, open
**Supabase Dashboard → Authentication → Email Templates** and copy the matching
HTML file into each template.

| Supabase template | Suggested subject | File |
| --- | --- | --- |
| Confirm sign up | `Confirm your Math MetaVerse account` | `confirm-signup.html` |
| Reset password | `Reset your Math MetaVerse password` | `reset-password.html` |
| Change email address | `Confirm your new Math MetaVerse email` | `change-email-address.html` |
| Password changed notification | `Your Math MetaVerse password was changed` | `password-changed.html` |
| Email address changed notification | `Your Math MetaVerse email was changed` | `email-address-changed.html` |

In **Authentication → URL Configuration**:

1. Set the Site URL to `https://mathmetaverse.space`.
2. Add `https://mathmetaverse.space`, `/reset-password`, and `/auth/confirm`
   on that host to the Redirect URLs.
3. Keep `APP_URL` set to `https://mathmetaverse.space` in Laravel.

Every user-visible template link is pinned to the canonical MathVerse domain.
Recovery and confirmation credentials are placed in URL fragments so they are
not sent in HTTP request lines or referrers. The recovery template uses:

```html
https://mathmetaverse.space/reset-password#token_hash={{ .TokenHash }}&amp;type=recovery
```

To use email changes and security messages:

1. Enable email changes and email confirmations in Supabase Auth.
2. Keep secure/double email-change confirmation disabled so confirmation is
   required only from the new address, matching the MathVerse interface.
3. Enable the **Password changed** and **Email address changed** security
   notifications. Editing their HTML alone does not enable delivery.

The matching local Supabase CLI settings are included in `supabase/config.toml`.

## Application-event emails

Teacher decisions, account-status changes, assignments, quiz availability,
retakes, excuses, initial-attempt and authorized-retake receipts, and class
removal are normal application events rather than Supabase Auth events. Do not
paste their shared Blade design into the Supabase Auth template editor. Laravel renders
`resources/views/emails/mathverse-event.blade.php` and sends those messages
from the notification delivery outbox.

Configure Laravel's `MAIL_*` variables with a production SMTP service. If
Supabase Auth already uses Custom SMTP, the same provider credentials and
verified sender can be used so both kinds of email have one sender identity.
Laravel Cloud runs the schedule automatically. On other hosts, run Laravel's
scheduler every minute so queued messages are delivered.
