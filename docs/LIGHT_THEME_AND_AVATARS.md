# Light theme, profile photos, and Android releases

The website uses a shared light theme for public pages, account forms, and student, teacher, and administrator workspaces. Native system fonts, white cards, readable charts, consistent buttons, light form controls, and responsive tables replace the dark neon presentation. Existing form actions, loading, validation, and security controls are preserved.

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

Login and actual rendered student, teacher, and admin shells were checked in the available browser at phone, tablet, and desktop widths. A local photo crop was opened, zoomed, accepted, and shown in the form preview. This does not claim hardware testing on every browser or OS. Automated checks cover upload compression, cropping, privacy, role views, and existing workflows.

The Unity file list is in `C:/Users/Brito/MATHVERSE V1/docs/UNITY_LEADERBOARD_AVATARS.md`. Rebuild the APK for game changes.
