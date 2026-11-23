# Website themes, profile photos, privacy, and Android releases

The website keeps the layout and gamified presentation from `1f1e041`: the same backgrounds, fonts, panels, clipped corners, navigation, buttons, and modals. The lower-left theme switch changes only the palette. Dark is the default; the browser remembers the selected mode before loading styles and retains it through dashboard navigation and reloads. Existing charts repaint their colors without fetching or changing their data.

Both themes share the same readability improvements: 17px body text, at least 14px small labels, 16px action labels, visible keyboard focus, and 44px minimum button targets. Theme-specific styles cannot change layout, typography, geometry, spacing, or animation; an automated CSS check enforces that rule. Existing reduced-motion, safe-area, native form, and no-backdrop-filter fallbacks remain supported.

## Photos

Profile boxes are square. Missing and failed photos use local initials, with safe escaping and no external avatar service. Anonymous peer profiles show a neutral icon without identifying initials or a real photo. Authorized teachers and administrators retain access to real names and photos.

Registration and profile uploads open a square crop editor. Users can move the image, zoom, reset, cancel, or use the keyboard. The crop chosen by the user is the submitted image. Saved header photos change only after a successful save.

The server validates and decodes JPEG, PNG, and WebP sources up to 2 MB, corrects JPEG orientation, and saves a square JPEG no larger than **512 × 512 pixels and 128 KiB**. Small sources are not enlarged. Embedded metadata is removed. New photo URLs are versioned; cached, lazy-loaded images use fixed display dimensions. Existing stored photos remain intact and display inside square boxes.

Thirteen legacy stored photos still need a one-time normalization on the configured website server. The local checkout has no server credentials, and the database connector cannot upload Storage objects. Run `php artisan avatars:normalize` to inspect, then `php artisan avatars:normalize --apply` to create square compressed copies while preserving originals and concurrent user edits. See `EXISTING_AVATAR_NORMALIZATION.md` for recovery details.

## Privacy

Student anonymity is the default, and students can turn it off in their profile. The database default and one-time update were already applied before the website rollback. The migration is restored in source for deployment history; its marker preserves later opt-outs when replayed. Teachers and administrators retain their existing settings and authorized access. See `STUDENT_PROFILE_PRIVACY.md`.

## Android releases

Game-release pages, upload validation, and download routes accept Android APKs only. Version history, rollback, deletion, and private R2 multipart upload remain available. Historical database migrations and general website support for Apple browsers are retained.

## Deployment

Deploy the changed source, public CSS/JavaScript, and matching `public/build` assets together. PHP **GD** and **EXIF** must be enabled. Install the locked Composer dependencies with `composer install --no-dev --optimize-autoloader`, then rebuild normal Laravel deployment caches. If building assets on the server, use `npm ci --ignore-scripts` followed by `npm run build`. The student privacy migration is already applied to MathMetaverse.

Browser verification covers representative phone, tablet, and desktop layouts; it does not claim physical testing on every operating system or browser.
