# 12 — Development Milestones

Estimates assume **1 full-stack developer (Laravel + Flutter) with AI assistance** plus a part-time designer/illustrator. Weeks are calendar weeks of focused work; parallelise backend/mobile with a second developer to compress by ~35%.

Every milestone ends with: tests green in CI, staging deployed, demo, and docs in this folder updated.

| # | Milestone | Duration | Depends on |
|---|---|---|---|
| M0 | Foundations | 1.5 wk | Approval of this pack |
| M1 | Catalogue & Admin | 3 wk | M0 |
| M2 | Identity, Profile & App Shell | 2.5 wk | M0 |
| M3 | AI Identification Pipeline | 3.5 wk | M1 |
| M4 | Scanner → Confirm → Collect/Release → Reveal | 3 wk | M2, M3 |
| M5 | FishDex Collection, Journal & Stats | 3 wk | M4 |
| M6 | Hardening, Compliance & Store Readiness | 2.5 wk | M5 |
| M7 | Closed Beta → Production Launch | 3–4 wk | M6 |
| | **Total** | **≈ 22–23 weeks** | |

---

### M0 — Foundations
- Repos (`fishdex-api`, `fishdex-app`), branch protection, CI (Pest, Larastan, Pint; `flutter analyze`, tests).
- Docker dev env (MySQL, Redis, MinIO, Mailpit); staging VPS; Cloudflare DNS; R2 buckets; Sentry; Firebase projects (dev/staging/prod).
- Laravel skeleton with module folders, ULID trait, problem+json error handler, request-id middleware, `/health`, `/app/config`.
- Flutter skeleton: flavours, router, Dio client, Drift DB, design tokens stub.
- **Exit:** both apps build in CI; staging reachable; arch tests enforce module boundaries.

### M1 — Catalogue & Admin
- Migrations for all catalogue tables (doc 04 §A–B), seeders (MY states, habitats, rarity tiers, families).
- Filament: species with relation managers, card sets/cards, rarity assignment with history, reference images with licence validation, CSV import command.
- Catalogue API: `/species`, `/species/{id}`, `/families`, `/catalogue/snapshot` (ETag).
- **Content track (parallel, product owner):** first **100–150 species** with Malay/English names, aliases, origin, habitat, conservation, rarity, ≥ 3 verified reference images each; data-licensing decision made.
- **Exit:** curator can publish a species end-to-end; API returns localized names; search finds "toman", "giant snakehead" and "Channa micropeltes" as the same species.

### M2 — Identity, Profile & App Shell
- Register/login/Google, verification, password reset, logout-all, account deletion (grace + purge job), devices/FCM registration.
- Flutter: onboarding, auth screens, bottom-nav shell, profile/settings, BM + EN localisation, dark ocean theme, first version of the **FishCard** widget with rarity frames (static).
- **Exit:** a new user can sign up with Google on a real Android device, set home state, see the catalogue, delete their account.

### M3 — AI Identification Pipeline
- Media upload, sanitiser, variants, pHash; `identification_*` tables.
- `IdentificationProvider` + 2 provider implementations + `FakeProvider`; structured output schema; matcher; scorer; router with circuit breaker; quotas; budget guard; cost logging; `ai_usage_daily`.
- **Evaluation set** (≥ 2,000 labelled images incl. look-alikes and negatives) and `fishdex:ai-eval` command; provider bake-off; calibrate thresholds.
- Filament AI usage dashboard.
- **Exit:** top-3 ≥ 90% and top-1 ≥ 75% on eval set; High band precision ≥ 95%; p95 ≤ 10 s on staging; blended cost per scan measured and within cost model.

### M4 — Scanner → Confirm → Collect/Release → Reveal
- Flutter camera/gallery, compression, upload, polling + FCM, result screens per band, candidate picker, catalogue search fallback, "not sure".
- Confirmation + decision endpoints, eligibility policy, collection upsert, protected-species notice.
- Card reveal animation (rarity-scaled), haptics, holo shader v1 with gyroscope.
- Offline scan queue (Drift + workmanager) with idempotent sync.
- **Exit:** happy path and all edge paths (no fish, low confidence, offline, quota exceeded, kill-switch) pass integration tests; catching the same species twice creates two encounters and one collection row.

### M5 — FishDex Collection, Journal & Stats
- Dex grid (silhouette/seen/collected), filters, set completion, collection entry detail with cover photo and personal bests.
- Journal timeline, encounter edit, extra photos, trips, dispute → review queue; Filament review workflow with correction re-aggregation.
- Stats endpoint + screens; nightly reconciliation job.
- **Exit:** 60 fps grid on a mid-range device with 150 species; stats match a full recompute in tests.

### M6 — Hardening, Compliance & Store Readiness
- Security review against doc 10, rate-limit tuning, Play Integrity (optional gate), backups + restore drill, load test of scan pipeline (e.g. 20 scans/s burst).
- Privacy policy, ToS, PDPA review, in-app + web account deletion page, data safety form, content rating.
- Crashlytics/Analytics events, Sentry alerts, uptime checks, runbooks.
- **Exit:** no open high/critical findings; restore drill passed; store listing assets ready.

### M7 — Closed Beta (free-tier AI) → Launch
- Closed beta runs on Gemini free tier (D-16): Malaysia-only track, beta consent screen, tester count sized to the free RPD limit, `waiting_capacity` queue live.
- Before open beta/production: switch `AI_TIER=paid`, run full evaluation, set paid budget guard.
- Google Play closed testing track. Note: new **personal** developer accounts must run a closed test with a minimum number of testers for a minimum period before production access (currently 12 testers / 14 days — verify); an **organisation** account avoids this.
- Recruit Malaysian anglers (fishing groups/communities); collect misidentifications into review queue; recalibrate thresholds; validate the 8 scans/MAU assumption and cost per MAU.
- **Exit / launch criteria:** crash-free users ≥ 99.5%; D7 retention ≥ 25% in beta cohort; cost/MAU ≤ target; no P1 bugs.

---

## Post-MVP backlog (ordered suggestion)
1. Monetization: Google Play Billing subscription, server-side verification + RTDN, entitlements.
2. Achievements & streaks (cheap engagement, no AI cost).
3. iOS release (Apple Sign-In, App Store billing).
4. Second catalogue country (Singapore or Indonesia) — data-only thanks to D-03.
5. Social: friends, public profiles, shared catches (coarse location only).
6. Trading / leaderboards / competitions.
7. On-device pre-filter model ("is there a fish?") to cut AI spend.
