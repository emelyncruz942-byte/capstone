# Cloudflare R2 app-release storage

MathVerse stores only Android APK and iOS IPA build files in a private Cloudflare R2 bucket. Supabase remains the source of truth for users, permissions, version metadata, and which release is active.

## One-time Cloudflare setup

1. In **Cloudflare > R2**, create a private bucket such as `unity-releases`. Do not enable a public `r2.dev` address.
2. Create an R2 API token limited to **Object Read & Write** for that bucket. Add its Account ID, Access Key ID, and Secret Access Key to the deployment's secret environment variables. Never add real credentials to `.env.example`, Git, browser JavaScript, or Supabase tables.
3. Apply this bucket CORS policy, replacing the origin only if the production hostname changes:

```json
[
  {
    "AllowedOrigins": ["https://mathmetaverse.space"],
    "AllowedMethods": ["PUT"],
    "AllowedHeaders": ["Content-Type"],
    "ExposeHeaders": ["ETag"],
    "MaxAgeSeconds": 3600
  }
]
```

4. Configure the deployment variables shown below and redeploy. Run `php artisan config:clear` after changing a non-containerized deployment.

```dotenv
R2_ACCOUNT_ID=your_32_character_cloudflare_account_id
R2_ACCESS_KEY_ID=your_bucket_scoped_access_key_id
R2_SECRET_ACCESS_KEY=your_bucket_scoped_secret_access_key
R2_BUCKET=unity-releases
R2_PART_SIZE=16777216
R2_UPLOAD_URL_TTL=900
R2_SIGNED_DOWNLOAD_TTL=300
```

5. Run `database/supabase/2026_10_12_cloudflare_r2_app_releases.sql` in the Supabase SQL editor after `2026_10_11_versioned_unity_app_releases.sql`. Then run `database/supabase/2026_10_19_deletable_unity_app_releases.sql`. The first migration keeps existing Supabase-hosted builds available and sends only new builds to R2; the second enables protected, retry-safe deletion of inactive versions.
6. Deploy the matching application code with production dependencies and assets (`composer install --no-dev --optimize-autoloader`, then `npm ci && npm run build`). Do not enable the upload form before both the migration and R2 secrets are deployed.

`R2_PART_SIZE` is 16 MiB by default and must remain between 5 MiB and 512 MiB in MathVerse. Its value is saved when each release begins, so that release can finish safely even if a later deployment changes the default. Upload and download URLs are short-lived. The Laravel server signs them, but build bytes travel directly between the administrator's browser and R2 instead of passing through PHP or Supabase Storage.

When an upload fails, MathVerse first marks the release failed in Supabase. The row deliberately retains its opaque `provider_upload_id` as a durable cleanup marker while the server aborts the multipart upload and deletes any completed orphan. Only after both R2 operations succeed does the server call `finish_unity_app_release_cleanup_v2`, which clears that marker.

Failed releases with an uncleared marker keep a **Retry storage cleanup** action in the administrator's version history. Use it after a temporary R2 error. The retry is safe because abort/delete cleanup is idempotent, the stored upload ID is matched before it can be cleared, and the finish RPC itself is retry-safe after cleanup was already recorded. Do not clear `provider_upload_id` manually: leaving it in place is what makes interrupted cleanup recoverable.

Deleting an old version is also a two-step server workflow. The database first
claims an inactive release and locks it against activation or publication.
Laravel then removes the exact private R2 or legacy Supabase Storage object and
finishes the metadata deletion with the claim's opaque token. The current
version cannot be deleted; make another ready version current first. If either
provider cleanup or the final database call is interrupted, use **Retry delete**
on the **Deletion pending** version. Never delete a release row or a
`storage.objects` row manually.

The rollback migration archives outstanding `provider_upload_id` values and never deletes R2 objects or multipart uploads. Review those archived markers before performing any later manual cleanup.

For local browser testing, add the exact local origin as another `AllowedOrigins` entry. Never use `*` for the production bucket.
