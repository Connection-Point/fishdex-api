# FishDex API

Backend for **FishDex** — a premium, trading-card-style app where anglers photograph real fish, identify them with AI, and collect them in their personal FishDex. Malaysian freshwater and marine species first; Southeast Asia next.

This repository contains the REST API used by the Android app, the Filament staff panel, and the AI identification pipeline.

> **Status:** closed-beta backend (milestones M0–M3 complete). The Android app lives in a separate repository: **fishdex-app**.

---

## Contents

- [Features](#features)
- [Tech stack](#tech-stack)
- [Architecture](#architecture)
- [Getting started](#getting-started)
- [Configuration](#configuration)
- [AI identification (free tier for beta)](#ai-identification-free-tier-for-beta)
- [Running in development](#running-in-development)
- [Testing](#testing)
- [Artisan commands](#artisan-commands)
- [Project structure](#project-structure)
- [Deploying to production](#deploying-to-production)
- [Security](#security)
- [License](#license)

---

## Features

**For anglers (REST API, `/api/v1`)**
- Email/password and Google sign-in, email verification, password reset, in-app account deletion with a 30-day grace period
- Malaysian fish catalogue with search in English, Bahasa Malaysia, local aliases and scientific names; filters by water type, origin, habitat, state and rarity; offline catalogue snapshot with ETag
- AI fish scanning: upload → validation and EXIF stripping → Gemini identification → match against the verified catalogue → backend-computed confidence (high / medium / low)
- Confirm the species, then **Collect** (adds the card) or **Release** (journal only, species marked *seen*); one collection entry per species no matter how many times it's caught
- Fishing journal, trips, the FishDex card grid (unseen cards show only a silhouette), personal bests and collection stats
- Anti-cheat: duplicate-photo detection, screen/print-photo flags, low-confidence and manual entries can't become cards, expert review queue

**For staff (Filament panel, `/admin`)**
- Species editor: names and aliases, scientific synonyms, versioned rarity history, conservation status, origin and legal protection by country, licensed reference images
- Card sets and cards, review queue (confirm / correct / reject), angler support tools
- AI usage dashboard and runtime settings (quotas, confidence thresholds, free-tier limits, kill switch)
- Role-based access (super admin, catalogue editor, reviewer, support), mandatory authenticator-app 2FA, full audit log

**Platform**
- Asynchronous scans on Redis queues; free-tier AI capacity is respected and scans wait instead of failing
- Per-user daily scan quotas, global AI budget guard, idempotent POSTs for flaky mobile networks
- Precise catch locations encrypted at rest; only coarse location is queryable

## Tech stack

| Area | Choice |
|---|---|
| Framework | Laravel 13 · PHP 8.3+ |
| Database | MySQL 8 / MariaDB 10.11+ (SQLite for tests) |
| Cache, queues, rate limits | Redis · Laravel Horizon |
| Auth | Laravel Sanctum (mobile tokens) · separate session guard for staff |
| Admin | Filament 5 |
| Object storage | Cloudflare R2 via the S3 API (MinIO locally) |
| AI | Google Gemini (free tier for closed beta), behind a provider interface |
| Push | Firebase Cloud Messaging |
| Images | PHP GD (validation, re-encoding, variants, perceptual hash) |

## Architecture

The full design is in [`docs/`](docs/00-README.md):

| Doc | Topic |
|---|---|
| [01 PRD](docs/01-prd.md) | Product requirements and MVP scope |
| [02 System architecture](docs/02-system-architecture.md) | Components, deployment, observability |
| [03 ERD](docs/03-database-erd.md) · [04 Schema](docs/04-database-schema.md) | Data model |
| [05 Laravel modules](docs/05-laravel-modules.md) | Code organisation |
| [07 API spec](docs/07-api-spec.md) | Endpoints, payloads, error codes |
| [08 AI workflow](docs/08-ai-identification-workflow.md) | Identification pipeline, confidence, free tier |
| [09 Storage](docs/09-storage-strategy.md) · [10 Security](docs/10-security-authorization.md) | Storage, auth, privacy (PDPA) |
| [11 Cost model](docs/11-cost-model.md) · [12 Milestones](docs/12-milestones.md) | Costs and roadmap |

Every architectural decision is listed in the decision log in [`docs/00-README.md`](docs/00-README.md).

## Getting started

### Prerequisites

- PHP **8.3+** with these extensions enabled: `gd`, `exif`, `intl`, `fileinfo`, `zip`, `sodium`, `pdo_mysql` (and `redis` if available)
- Composer 2
- MySQL 8 / MariaDB and Redis — or Docker Desktop to run them with `compose.yaml`

> **Windows:** Horizon needs `pcntl`/`posix`, which don't exist on Windows. `composer.json` declares them under `config.platform` so installation works; locally you run `php artisan queue:work`, and Horizon runs on the Linux server. On Laragon, enable `sodium` via **PHP → Extensions**.

### Install

```bash
git clone <this-repo> fishdex-api
cd fishdex-api
composer install
cp .env.example .env            # Windows: copy .env.example .env
php artisan key:generate

# MySQL, Redis, MinIO and Mailpit via Docker (optional)
docker compose up -d

php artisan migrate --seed      # reference data + 20-species DEVELOPMENT sample catalogue
php artisan storage:link
php artisan fishdex:create-admin you@example.com --name="Your Name"
```

**No MySQL/Redis yet?** In `.env` set `DB_CONNECTION=sqlite`, `CACHE_STORE=database`, `QUEUE_CONNECTION=database`, create an empty `database/database.sqlite`, then run the migrate step.

> Back up `APP_KEY`. It encrypts catch locations and staff 2FA secrets; changing it makes that data unreadable. Use a different key in every environment.

## Configuration

All FishDex settings have defaults in [`config/fishdex.php`](config/fishdex.php). The main `.env` keys:

| Key | Purpose |
|---|---|
| `AI_TIER` | `free` (closed beta, Gemini unpaid quota) or `paid` (production) |
| `AI_PROVIDER` | `gemini`, or `fake` for local development without any AI calls |
| `GEMINI_API_KEY`, `GEMINI_MODEL` | Key from Google AI Studio and the **exact** model ID shown there |
| `AI_FREE_RPM`, `AI_FREE_RPD` | Your project's free-tier limits (AI Studio → rate limits) |
| `AI_DAILY_BUDGET_USD` | Daily spend cap on the paid tier |
| `FISHDEX_FREE_DAILY_SCANS` | Scans per user per day on the free plan |
| `MEDIA_DRIVER`, `ASSETS_DRIVER`, `R2_*` | `local` in development, `s3` + Cloudflare R2 credentials in staging/production |
| `GOOGLE_CLIENT_IDS` | Comma-separated OAuth client IDs allowed for Google sign-in |
| `FIREBASE_CREDENTIALS` | Path to the FCM service-account JSON (leave empty to disable push) |
| `ADMIN_REQUIRE_MFA` | Staff must enrol an authenticator app (keep `true` outside local dev) |

Many of these can also be changed at runtime by a super admin in **Admin → AI & app settings**; those overrides are stored in the `app_settings` table.

## AI identification (free tier for beta)

1. Create a key in [Google AI Studio](https://aistudio.google.com/) → `GEMINI_API_KEY`.
2. Copy the exact model ID → `GEMINI_MODEL`.
3. Copy your project's requests/minute and requests/day → `AI_FREE_RPM` / `AI_FREE_RPD`.
4. Keep `AI_TIER=free`.

Testers must accept the beta AI notice (`POST /api/v1/me/ai-consent`) before scanning, because Google may use free-tier inputs to improve its products. When the daily free quota runs out, scans move to `waiting_capacity` and are processed automatically after the reset (midnight Pacific ≈ 3–4 pm Malaysia time). Moving to paid is a config change: `AI_TIER=paid`.

The AI never assigns rarity or any game value; rarity is staff-curated per country.

## Running in development

```bash
php artisan serve                                  # API: http://localhost:8000/api/v1 · Admin: /admin
php artisan queue:work --queue=scans,default       # Linux/macOS can use: php artisan horizon
php artisan schedule:work                          # waiting-scan release, cleanup, AI usage roll-ups
```

Quick checks: `GET /api/v1/health` and `GET /api/v1/app/config`.

## Testing

```bash
php artisan test          # SQLite in-memory
vendor/bin/pint           # code style (CI runs pint --test)
```

The suite covers authentication, catalogue search, the full scan → confirm → collect/release flow, collection integrity, anti-cheat rules, free-tier capacity handling, quotas, idempotency, provider failures, the Gemini adapter, persistent-cache safety, and the admin panel. CI runs it on PHP 8.3/8.4 with SQLite and on MySQL 8.

To script AI results in tests, use `FakeProvider::push(...)`.

## Artisan commands

| Command | Purpose |
|---|---|
| `fishdex:create-admin {email} --role=` | Create or update a staff account (`super_admin`, `catalogue_editor`, `reviewer`, `support`) |
| `fishdex:import-catalogue {file.csv} [--dry-run]` | Bulk import or update species (column list in the command's docblock) |
| `fishdex:ai-eval {dir} [--provider=] [--limit=] [--skip=]` | Accuracy benchmark on labelled photos: `dir/Genus species/*.jpg` |
| `fishdex:release-waiting-scans` | *(scheduled)* Re-queue scans parked for AI capacity |
| `fishdex:sweep-stuck-scans` | *(scheduled)* Recover or time out stuck scans |
| `fishdex:rollup-ai-usage` | *(scheduled)* Cost and latency roll-up for the dashboard |
| `fishdex:purge` | *(scheduled)* Retention: abandoned photos, old AI payloads, deleted accounts |

## Project structure

```
app/
  Domain/
    Identity/        users, Google sign-in, account deletion
    Catalogue/       species, names, synonyms, rarity (versioned), conservation, search, snapshot
    Cards/           card sets and cards
    Media/           upload validation, re-encoding, variants, signed URLs
    Identification/  provider contract, Gemini + fake providers, prompts, matcher, confidence scorer,
                     free-tier capacity, budget guard, pipeline and queue job
    Encounter/       confirm, collect/release, eligibility rules
    Collection/      per-user FishDex aggregate and stats
    Review/          expert review outcomes
    Settings/        runtime overrides on top of config/fishdex.php
  Http/Api/V1/       controllers and the Presenter (all JSON shaping; only ULIDs leave the API)
  Filament/          staff panel
config/fishdex.php   every tunable with its default
database/            migrations, factories, seeders (sample catalogue is dev-only)
docs/                architecture and product docs
tests/               feature + unit tests
```

## Deploying to production

- Replace the sample catalogue (`MalaysiaSampleCatalogueSeeder` is dev-only and marked *provisional*) with curated data via the admin panel or CSV import. Conservation status needs a cited source.
- `AI_TIER=paid`, run `fishdex:ai-eval`, then calibrate confidence thresholds in the admin settings.
- `MEDIA_DRIVER=s3` and `ASSETS_DRIVER=s3` with Cloudflare R2 credentials.
- `QUEUE_CONNECTION=redis` with Horizon under a process manager (Supervisor/systemd) and `* * * * * php artisan schedule:run` in cron.
- `APP_ENV=production`, `APP_DEBUG=false`, real mail driver, `GOOGLE_CLIENT_IDS`, `FIREBASE_CREDENTIALS`.
- `php artisan optimize` and `php artisan filament:optimize` on deploy.

## Security

- Never commit `.env`, service-account JSON files or R2 keys (covered by `.gitignore`).
- API keys for AI providers live only on the server; the app never calls the AI directly.
- Report vulnerabilities privately to the maintainers rather than opening a public issue.

## License

Proprietary. Copyright © 2026 FishDex. All rights reserved. No permission is granted to use, copy, modify or distribute this software without written consent.
