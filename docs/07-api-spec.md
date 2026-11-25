# 07 — REST API Endpoint Specification (v1)

Base URL: `https://api.fishdex.app/api/v1` (placeholder domain). JSON only. All IDs are `public_id` ULIDs.

## 1. Conventions

| Topic | Rule |
|---|---|
| Auth | `Authorization: Bearer <sanctum-token>` unless marked **public** |
| Headers | `Accept-Language: ms|en`, `X-App-Version: 1.0.0+12`, `X-Device-Id: <uuid>` |
| Idempotency | `Idempotency-Key: <uuid>` required on `POST /scans`, `POST /encounters`, `POST /scans/{id}/confirmation`, `POST /encounters/{id}/decision`. Replays within 24 h return the original response. |
| Pagination | Cursor-based: `?cursor=…&limit=20` (max 50). Response `meta.next_cursor`. |
| Success shape | `{ "data": …, "meta": { … } }` |
| Error shape | RFC 9457 `application/problem+json`: `{ type, title, status, detail, code, errors? }` |
| Rate limits | `429` with `Retry-After`; headers `X-RateLimit-Limit/Remaining` |
| Old clients | `426 Upgrade Required` with `code: app_version_unsupported` |

### Error codes (stable, machine-readable)
`validation_failed`, `unauthenticated`, `invalid_credentials`, `forbidden`, `not_found`, `rate_limited`, `scan_quota_exceeded`, `ai_temporarily_unavailable`, `ai_consent_required`, `image_rejected`, `scan_not_ready`, `already_confirmed`, `not_collect_eligible`, `decision_final`, `idempotency_key_required`, `request_in_progress`, `too_many_photos`, `app_version_unsupported`, `server_error`.

## 2. Auth & account

| Method | Path | Auth | Description |
|---|---|---|---|
| POST | `/auth/register` | public | `{email, password, display_name, handle, device_name}` → `201 {token, user}`; sends verification email |
| POST | `/auth/login` | public | `{email, password, device_name}` → `{token, user}`. Throttled 5/min per email+IP |
| POST | `/auth/google` | public | `{id_token, device_name}` → verifies with Google, creates/links → `{token, user, is_new}` |
| POST | `/auth/logout` | ✓ | Revokes current token |
| POST | `/auth/logout-all` | ✓ | Revokes all tokens |
| POST | `/auth/email/resend` | ✓ | Resend verification |
| POST | `/auth/password/forgot` | public | `{email}` → always `202` (no account enumeration) |
| POST | `/auth/password/reset` | public | `{token, email, password}` |
| DELETE | `/me` | ✓ | `{confirmation: "DELETE", password?}` → `202`, schedules purge after 30-day grace; tokens revoked |

## 3. Profile & devices

| Method | Path | Description |
|---|---|---|
| GET | `/me` | Profile, settings, plan, scan quota remaining |
| PATCH | `/me` | `display_name, handle, home_region_id, locale, timezone, units, default_location_visibility` |
| PUT | `/me/avatar` | multipart `image` |
| POST | `/me/ai-consent` | Closed beta (free-tier AI): records the tester's acceptance of the AI data notice. Required before `POST /scans` while `AI_TIER=free` (`403 ai_consent_required`) |
| POST | `/me/devices` | `{device_uuid, platform, fcm_token, app_version}` upsert |
| DELETE | `/me/devices/{device_uuid}` | Remove push target |

## 4. App config & catalogue (cacheable)

| Method | Path | Auth | Description |
|---|---|---|---|
| GET | `/app/config` | public | `min_supported_version`, feature flags, rarity tiers, habitats, regions, catalogue_version |
| GET | `/health` | public | Liveness |
| GET | `/species` | ✓ | Filters: `q`, `family`, `habitat`, `water_type`, `origin`, `rarity`, `region`, `country=MY`, `sort=card_number|name|rarity`. Returns list items: `public_id, scientific_name, primary names (en/ms), family, rarity, thumbnail_url, user_state (unseen|seen|collected)` |
| GET | `/species/{id}` | ✓ | Full detail: names & aliases, taxonomy, habitats, distribution regions, origin, conservation, legal notice, rarity, reference images (with attribution), lookalikes, card(s), user's collection summary |
| GET | `/families` | ✓ | List with species counts |
| GET | `/catalogue/snapshot` | ✓ | Compact full catalogue for offline cache. Supports `If-None-Match` (ETag = catalogue_version) → `304` |

## 5. Scans (AI identification)

### `POST /scans`
multipart/form-data:
| Field | Required | Notes |
|---|---|---|
| image | ✓ | JPEG/PNG/WebP/HEIC, ≤ 8 MB, ≥ 400 px short side |
| client_uuid | ✓ | Offline idempotency |
| captured_at | ✓ | ISO-8601 |
| lat, lng, accuracy_m | – | Optional; only if user allowed location |
| encounter_type | – | `catch` (default) / `observation` |
| trip_id | – | |

Responses:
- `202 { data: { scan_id, status: "queued", poll_after_ms: 1500 } }`
- `413/422 image_rejected`, `429 scan_quota_exceeded { quota: {limit, used, resets_at} }`, `503 ai_temporarily_unavailable` (kill-switch; app keeps scan queued locally)

### `GET /scans/{scan_id}`
```json
{
  "data": {
    "scan_id": "01JF…",
    "status": "completed",
    "outcome": "identified",
    "confidence_band": "medium",
    "candidates": [
      {
        "rank": 1,
        "species": { "public_id": "01H…", "scientific_name": "Channa micropeltes",
                     "names": { "en": "Giant snakehead", "ms": "Toman" },
                     "rarity": "rare", "thumbnail_url": "https://…" },
        "confidence": 0.71,
        "reasons": ["Dark lateral band", "Elongated cylindrical body", "Large mouth"],
        "lookalike_hint": "Juveniles resemble Channa striata (haruan)"
      }
    ],
    "preselected_species_id": null,
    "flags": [],
    "image": { "thumb_url": "https://signed…", "expires_at": "…" }
  }
}
```
`status ∈ queued|waiting_capacity|processing|completed|failed|timed_out|rejected`. When `waiting_capacity`, the response includes `expected_after` (ISO time of next AI quota reset) so the app can show "Results after 4:00 pm". `preselected_species_id` set only for `high`. Confidence is the backend composite score.

### `POST /scans/{scan_id}/confirmation`
```json
{ "choice": "species", "species_id": "01H…" }
// or { "choice": "not_listed" } | { "choice": "unsure" }
```
- Creates the encounter. `species` choice from candidates in high/medium → `ai_confirmed`; species picked via catalogue search → `user_selected` (unverified, may be sampled for review); `not_listed`/`unsure` → `unidentified`.
- `201 { data: { encounter, collect_eligible, legal_notice? } }`; `409 already_confirmed`.

### `GET /scans?status=…` — user's recent scans (to resume after app restart).

## 6. Encounters (journal)

| Method | Path | Description |
|---|---|---|
| GET | `/encounters` | Filters: `species`, `status`, `decision`, `trip`, `from`, `to`; cursor pagination |
| GET | `/encounters/{id}` | Detail, media (signed URLs), species, decision, location (own precise location returned only to owner) |
| POST | `/encounters` | Manual journal entry (no AI): `{client_uuid, species_id?, caught_at, length_cm?, …}` → journal only, `collect_eligible=false` |
| PATCH | `/encounters/{id}` | `length_cm, weight_kg, method, tackle_notes, physical_outcome, notes, trip_id, location_visibility` |
| POST | `/encounters/{id}/decision` | `{decision: "collect"|"release"}` → see below |
| POST | `/encounters/{id}/media` | Add extra photos (max 5) |
| POST | `/encounters/{id}/dispute` | User believes ID is wrong → review item |
| DELETE | `/encounters/{id}` | Soft delete; collection aggregate recomputed |

`POST /encounters/{id}/decision` response:
```json
{
  "data": {
    "encounter": { "...": "..." },
    "collection_entry": { "species_id": "01H…", "state": "collected",
                          "collected_count": 1, "encounter_count": 2 },
    "is_new_species": true,
    "is_personal_best": false,
    "reveal": { "rarity": "rare", "foil": "holo", "card": { "set": "MY-FW-S1", "number": "017", "art_url": "…" } }
  }
}
```
Errors: `422 not_collect_eligible`, `409` if decision already final (decision can be changed from release → collect once, never collect → release, to keep counts simple).

## 7. Trips

`GET /trips`, `POST /trips`, `GET /trips/{id}`, `PATCH /trips/{id}`, `DELETE /trips/{id}` — standard CRUD, owner-only.

## 8. Collection & cards

| Method | Path | Description |
|---|---|---|
| GET | `/collection` | Dex grid for country/set: every species with `state` (unseen/seen/collected), card number, rarity, thumbnail (silhouette for unseen). Filters `state`, `rarity`, `family`, `habitat`; `set=MY-FW-S1` |
| GET | `/collection/{species_id}` | User's entry: counts, first/best encounters, cover photo, encounter list link |
| PATCH | `/collection/{species_id}` | `{is_favorite, cover_media_id}` (cover must belong to user's encounter of that species) |
| GET | `/card-sets` | Sets with user completion |
| GET | `/cards/{id}` | Card detail for rendering |

## 9. Stats

`GET /me/stats` → `species_collected`, `species_seen`, `total_in_catalogue`, `completion_pct`, `by_rarity[]`, `by_family[]`, `by_habitat[]`, `encounters_total`, `release_rate`, `personal_bests[]`, `recent_activity[]`, `streaks` (later).

## 10. Rate limits (initial)

| Scope | Limit |
|---|---|
| Unauthenticated auth endpoints | 5/min per IP+identifier |
| General authenticated API | 120/min per user |
| `POST /scans` | 6/min per user + daily quota by plan (free: configurable, e.g. 10/day) |
| Media uploads | 30/hour per user |

## 11. Admin
Filament is server-rendered under `/admin` on the same domain (or `admin.` subdomain); it does not use this REST API.
