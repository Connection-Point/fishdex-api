# 06 — Flutter Folder Structure

Implemented with Flutter 3.50 / Dart 3.14, Riverpod 3 (without code generation), go_router 18, Material 3 via `material_ui`. See the README of the **fishdex-app** repository for the actual code map — Drift/offline queue, camera and FCM arrive with M4–M5.

## 1. Architecture

**Feature-first, three layers per feature:** `data` (DTOs, API/DB sources, repository implementations) → `domain` (entities, repository interfaces, small use-cases) → `presentation` (screens, widgets, Riverpod controllers).

### D-14 Feature-first + Riverpod + Drift offline queue
- **Why needed:** The app has distinct features (scanner, dex, journal) that will grow independently; anglers are often offline at the water.
- **Advantages:** A feature can be built/tested in isolation; Riverpod gives compile-safe DI and testable async state; Drift (SQLite) gives a durable queue for scans captured without signal and a cached catalogue.
- **Disadvantages:** More boilerplate (freezed, generators) than a flat structure; offline sync adds conflict cases.
- **Scalability:** New features (trading, social) slot in as new folders; the design system is shared so visual consistency holds as the team grows.

## 2. Folder tree

```
lib/
├── main.dart                         # flavour entry → bootstrap()
├── bootstrap.dart                    # Firebase, Crashlytics, ProviderScope, error zones
├── app/
│   ├── app.dart                      # MaterialApp.router
│   ├── router/app_router.dart        # GoRouter + auth redirect + StatefulShellRoute (tabs)
│   ├── router/routes.dart            # typed route definitions
│   └── flavors.dart                  # dev / staging / prod config
│
├── core/
│   ├── network/
│   │   ├── dio_client.dart           # base URL, timeouts, interceptors
│   │   ├── auth_interceptor.dart     # bearer token, 401 → logout
│   │   ├── idempotency_interceptor.dart
│   │   ├── api_exception.dart        # maps problem+json → typed failures
│   │   └── connectivity_provider.dart
│   ├── storage/
│   │   ├── secure_storage.dart       # token (flutter_secure_storage)
│   │   └── database/app_database.dart# Drift: scan_queue, catalogue_cache, encounter_drafts
│   ├── sync/
│   │   ├── scan_queue_worker.dart    # uploads queued scans when online (workmanager)
│   │   └── catalogue_sync.dart       # ETag/version-based catalogue refresh
│   ├── analytics/analytics_service.dart  # typed event wrappers over Firebase Analytics
│   ├── l10n/                         # ARB files: app_en.arb, app_ms.arb
│   ├── utils/ (formatters, units, debounce)
│   └── result.dart                   # Result<T, Failure>
│
├── design_system/
│   ├── tokens/colors.dart            # ocean palette + rarity colours
│   ├── tokens/typography.dart, spacing.dart, radii.dart, motion.dart
│   ├── theme/fishdex_theme.dart      # M3 ColorScheme (dark-first) + extensions
│   ├── theme/rarity_theme.dart       # ThemeExtension: frame, glow, foil per tier
│   ├── components/ (buttons, chips, sheets, empty states, shimmer)
│   └── card/
│       ├── fish_card.dart            # the card widget (front/back)
│       ├── card_frame_painter.dart   # original FishDex frame (CustomPainter)
│       ├── holo_foil_layer.dart      # FragmentShader + gyroscope tilt
│       ├── card_reveal_animation.dart
│       └── shaders/holo_foil.frag, prism.frag
│
├── features/
│   ├── auth/            {data, domain, presentation}  login, register, Google, forgot password
│   ├── onboarding/      presentation                  permissions, intro, home state pick
│   ├── profile/         {data, domain, presentation}  profile, settings, delete account
│   ├── catalogue/       {data, domain, presentation}  browse, search, filters, species detail
│   ├── scanner/         {data, domain, presentation}  camera, gallery, compression, upload, queue status
│   ├── identification/  {data, domain, presentation}  result screen, candidate picker, "not sure"
│   ├── encounter/       {data, domain, presentation}  collect/release, edit details, journal, trips
│   ├── collection/      {data, domain, presentation}  FishDex grid, filters, collection entry detail
│   ├── stats/           {data, domain, presentation}  completion, rarity breakdown, personal bests
│   └── shell/           presentation                  bottom nav: Dex · Scan · Journal · Catalogue · Profile
│
└── gen/                              # generated assets (flutter_gen)

assets/ (fonts, lottie/rive reveal files, card frame textures, silhouettes)
shaders/ (registered in pubspec)
test/ (mirrors lib/features; widget + provider tests)
integration_test/ (scan → collect happy path with mocked API)
```

Each feature's `data/` contains e.g. `species_api.dart` (Dio), `species_dto.dart` (freezed + json_serializable), `species_repository_impl.dart`; `domain/` holds `species.dart` (entity) and `species_repository.dart` (abstract); `presentation/` holds `species_list_screen.dart`, `species_detail_screen.dart`, `species_list_controller.dart` (`@riverpod` AsyncNotifier).

## 3. Key packages

| Package | Use |
|---|---|
| flutter_riverpod, riverpod_annotation, riverpod_generator | State + DI |
| go_router | Navigation, deep links |
| dio | HTTP |
| freezed, json_serializable | Immutable models |
| drift + sqlite3_flutter_libs | Offline queue and cache |
| flutter_secure_storage | Token |
| camera, image_picker | Capture |
| flutter_image_compress | Resize to ≤ 2048 px, JPEG q≈85 before upload |
| geolocator, permission_handler | Optional location |
| workmanager | Background upload of queued scans |
| cached_network_image | Card art / thumbnails |
| sensors_plus | Gyroscope for holo tilt |
| rive (or lottie) | Card reveal sequences |
| firebase_core, firebase_messaging, firebase_crashlytics, firebase_analytics | Firebase |
| google_sign_in | Google auth |
| in_app_purchase | Later (billing) |

## 4. Visual system (TCG direction)

- **Dark ocean theme:** abyss navy background, deep-teal surfaces, bioluminescent accent; light theme optional later.
- **Rarity (always colour + icon + label):** Common – slate silver · Uncommon – reef green · Rare – deep blue · Epic – violet · Legendary – gold with aurora foil. Final hex values set by the designer in `tokens/colors.dart`.
- **Original card frame:** FishDex's own layout — species art window, Malay + English name band, scientific name in italics, habitat/water-type badges, set code and number, rarity gem. No resemblance to Pokémon card templates, energy symbols or layout.
- **Holo:** GLSL fragment shader blended over artwork, driven by gyroscope tilt; disabled under "reduce motion".
- **Reveal:** card flips from silhouette; animation intensity scales with rarity; haptics on reveal.
- **Performance:** grid uses thumbnails (≤ 320 px), shaders only on the focused card, `RepaintBoundary` per tile.

## 5. Analytics event taxonomy (initial)

`scan_started`, `scan_uploaded`, `scan_result {band, outcome}`, `identification_confirmed {source}`, `encounter_decided {decision}`, `card_revealed {rarity, is_new}`, `species_viewed`, `dex_filtered`, `offline_scan_queued`, `offline_scan_synced`.
No personal data or coordinates in analytics events.
