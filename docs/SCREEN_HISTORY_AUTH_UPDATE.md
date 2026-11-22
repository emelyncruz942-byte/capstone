# Android screens, reporting history and authentication

Updated on 22 November 2026.

## Android game

Screen-space canvases now fit their authored width and height into the usable display area. Safe-area anchors keep controls away from cutouts and system bars without changing their parent hierarchy. Fixed popup backdrops stretch when a window resizes. World-space VR room UI keeps its existing layout.

Single-player dialogue uses its actual usable root area and scales its measurements proportionally to the canvas height. The new cases include tablet 16:10, 3:2 and 5:4, foldable 21:18, a square window, portrait 3:4 and ultrawide 32:9. Native Android aspect mode, resizable activity, orientation and SDK settings are retained. See [Unity's Android settings](https://docs.unity.cn/6000.3/Documentation/Manual/class-PlayerSettingsAndroid.html).

The foreground game sets the screen timeout to NeverSleep. Backgrounding, losing focus or closing the game restores its previous timeout policy.

Include the following Unity files and their matching `.meta` files in the Unity check-in:

- `Assets/SCRIPTS/AndroidAdaptiveCanvas.cs` and `GameScreenAwake.cs`.
- Updated `SinglePlayerDialogueLayout.cs`, `QuestNavigationController.cs` and `NpcTrophyCelebration.cs`.
- `Assets/Editor/AndroidScreenLayoutTests.cs` and the expanded `SinglePlayerDialogueDisplayTests.cs`.
- `docs/data/singleplayer/android-screen-layout-audit.json` and the updated quest-book guide.

These behaviors install automatically when the game starts. Rebuild and install a new APK; no Inspector assignments are required.

## Website reports

Admin and teacher quiz charts cover the earliest available activity through today in Asia/Manila, including zero-activity dates. Reports, student history and embedded class analytics read every response page. Long session-ID lists are divided into validated queries. Provider errors or inconsistent pagination produce an error instead of silently displaying incomplete totals. Existing ownership and trash boundaries are retained.

Learning Hub defaults to All history, with 7/30/90/180-day filters still available. Both teacher and individual-student calendars fill missing dates with zeros through today, while keeping recorded answer totals, mastery values and active-day counts intact. Archived owned classes remain available; deleted or unauthorized classes remain excluded. Future-dated answers are outside current-period totals.

The Supabase update is already applied to the connected Math Metaverse project:

`supabase/migrations/20261122064342_learning_hub_full_history.sql`

Its server-generated migration version is `20261122064342`. The corresponding function source is `database/functions/learning_hub_history.sql`. The update changes two read-only analytics functions and preserves their server-only access. A live check found 3,098 authorized Learning Hub answers from September onward, compared with 900 in the former 30-day range. Quiz-result and practice-question row counts stayed unchanged.

The historical hardening script's allowlist now also preserves the newer Unity leaderboard/reward endpoints and release-deletion endpoints during a future complete setup. No full hardening rerun or additional database update is required for this deployment.

## Authentication submissions

Sign-in, registration, password recovery/reset, email confirmation, logout, password changes and email changes reject duplicate in-flight form submissions. Submit controls disable after the first valid submission. Client-side validation failures, AJAX completion and browser back/forward restoration release the controls appropriately. Saved-password sign-in continues to use the same guard.

Deploy the changed website files, including `public/js/auth-submission.js`, the updated shared layout/navigation code, the compiled stylesheet and `public/build/manifest.json`. The local production asset build has passed.

## Verification

- Unity: 42 distinct passing cases across two runs; 117,000 complete question/choice text checks over 13 layouts; 154 actual login/menu/gameplay control checks over seven screen/window shapes.
- Website: 392 PHP tests with 29,918 assertions; 108 JavaScript tests; 99 executable PostgreSQL tests.
- PHP static analysis and the production asset build pass.
- Unity reports: `tmp/android-screen-verified-tests.xml`, `tmp/android-screen-backdrop-tests.xml` and `docs/data/singleplayer/android-screen-layout-audit.json` in the Unity project.
- Website results: `tmp/website-final-phpunit.xml`, `tmp/all-website-javascript-tests.log` and `tmp/website-push-sql-tests.log` in the shared project.

No APK rebuild or physical Android-device test was performed. Recorded quiz scores, practice answers and activity logs are preserved.
