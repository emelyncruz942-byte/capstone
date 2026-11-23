# Website themes, profile photos, and Android releases

The original gamified presentation is the default: the starfield, mathematics particles, grid, angular panels, Orbitron headings, and themed modals are preserved. The shared **Light mode / Dark mode** button at the lower left changes the palette across public pages, account forms, and student, teacher, and administrator workspaces. Light mode keeps the same layout and game identity with softer backgrounds and dark text.

The choice is saved in the browser as the ordinary `mathverse.theme` preference and applied before styles load. It remains selected after page changes and reloads, and changes synchronize between tabs. If browser storage is unavailable, the toggle still works for the current page. Charts update their labels, grids, and tooltips without changing or reloading the data.

Both modes use larger text and stronger contrast. Button labels are at least 16px with a 44px minimum target height, body text is 17px, and small legacy labels are at least 14px. Reduced-motion preferences stop background animation, and keyboard focus is visible. Existing form actions, loading, validation, photo handling, and security controls are preserved.

Missing or failed photographs use local initials. Anonymous peer views show a neutral icon without a real photograph, name, or identifying initials. Authorized teachers and administrators still see students' real details and photos. The default student privacy update is already applied; see `STUDENT_PROFILE_PRIVACY.md`.

## Photo uploads

Registration and profile forms open a square crop editor before accepting a photo. Users can drag, zoom, reset, use arrow keys, or cancel. The chosen crop replaces the submitted file; preview changes do not change saved header photographs before successful submission.

The server validates and decodes the image, corrects JPEG orientation, and saves a square JPEG no larger than **512 × 512 pixels and 128 KiB**. Small images are not enlarged. Quality is reduced only when needed to meet the size limit. JPEG is compatible with Unity's texture downloader and the encoding removes embedded metadata. Existing storage objects are preserved. New photo URLs are versioned and cached; browser images load lazily with fixed dimensions.

Source files remain limited to 2 MB in JPEG, PNG, or WebP format. PHP GD and EXIF are explicit Composer requirements. Existing small logo variants and the compressed social preview are retained.

## Deployment

1. Deploy all changed files, including the new public CSS/JS, avatar partial/helpers, and rebuilt `public/build` assets.
2. Ensure PHP **GD** and **EXIF** are enabled, then run `composer install --no-dev --optimize-autoloader` with the updated lock file.
3. Run `npm ci --ignore-scripts` and `npm run build` if assets are built during deployment.
4. Rebuild normal Laravel deployment caches. The privacy migration is already applied to MathMetaverse; its marker protects subsequent student opt-outs if replayed.

Game releases accept and display Android APKs only. Existing Android history, rollback, deletion, and private R2 multipart uploads remain available. Historical database migrations are preserved.

## Verification

Automated checks cover theme defaults, saved preferences, switching, disabled browser storage, cross-tab synchronization, chart recoloring, upload compression, cropping, privacy, role views, and existing workflows. The browser preview covers login and rendered student, teacher, and admin shells at phone, tablet, and desktop widths in both themes. This does not claim hardware testing on every browser or OS.

The Unity file list is in `C:/Users/Brito/MATHVERSE V1/docs/UNITY_LEADERBOARD_AVATARS.md`. Rebuild the APK for game changes.
