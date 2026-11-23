# Existing profile photo normalization

New uploads use the square crop editor and are saved as JPEGs of at most 512 × 512 pixels and 128 KiB. Existing stored photos need a one-time pass to give older Unity profile textures the same square dimensions and smaller downloads.

The November 24, 2026 read-only inventory found 13 stored photos: seven student, four teacher, and two administrator photos. Some were rectangular and some exceeded 1 MB. The local checkout has no Supabase server credentials, so this pass must run in the website's configured server environment. No credentials belong in command arguments or committed files.

## Run on the configured website server

1. Deploy the command, `SquareAvatarImage`, `SupabaseService`, and current Composer dependencies. PHP GD and EXIF must be enabled.
2. Inspect a dry run:

   ```sh
   php artisan avatars:normalize
   ```

3. Apply the inspected normalization:

   ```sh
   php artisan avatars:normalize --apply
   ```

The default limit is 100 existing photographs. `--limit=1000` raises the bounded limit when needed. Images already stored as square JPEGs within the size limits remain untouched on replay. Profiles without a photo remain unchanged and use initials in the website.

Only the configured project's public `avatars` bucket is accepted as a source. Redirects and external image hosts are not followed, downloads are capped at the existing 2 MB source limit, and the same image decoder/orientation/crop/compression helper used by normal profile uploads processes the result. Existing photos use their centered square frame; users can choose a different frame through the profile crop editor later.

## Preservation and concurrent edits

Every replacement receives a new versioned JPEG URL. Original Storage objects are never deleted. Before uploading, the command writes the original profile ID and URL to a private recovery manifest in `storage/app/private/avatar-normalization`. After upload, it records the replacement URL and outcome. This directory is outside the public document root and must remain private. Console output contains counts, not names, full image URLs, or credentials.

The profile update compares both the profile ID and its original `avatar_url`. If the student or staff member changes the image concurrently, the command leaves that change alone, preserves both Storage objects, records an unconfirmed result, and exits with a failure status for review. It never retries without the original-URL condition.

To reverse a confirmed replacement, use the private manifest to set `avatar_url` back to its `before` URL **only while the current URL still equals the recorded `after` URL**. This avoids overwriting any newer user-selected photo. Keep originals and private manifests until the replacements have been checked in both the website and Unity.

The command changes only photo URLs and adds Storage copies. It does not change names, anonymity settings, roles, scores, or access policies.
