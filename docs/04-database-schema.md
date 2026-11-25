# 04 — Complete Proposed Database Schema

MySQL 8.0+, InnoDB, `utf8mb4_0900_ai_ci` (accent- and case-insensitive collation helps Malay/English name search). All tables have `created_at`, `updated_at` unless noted. `public_id` = ULID `CHAR(26)` unique, used in all API URLs.

Legend: **PK** primary key · **FK** foreign key · **UK** unique · **IX** index · `?` nullable

---

## A. Reference & geography

### `countries`
| Column | Type | Notes |
|---|---|---|
| id | SMALLINT UNSIGNED PK | |
| iso2 | CHAR(2) UK | `MY`, `ID`, `TH`, `SG`… |
| name | VARCHAR(100) | |
| default_locale | VARCHAR(10) | `ms`, `id`, `th` |
| is_launched | BOOL | Whether catalogue is live for users |
| sort_order | SMALLINT | |

### `regions`
States/provinces. Used for distribution and coarse location.
| Column | Type | Notes |
|---|---|---|
| id | INT UNSIGNED PK | |
| country_id | FK countries | |
| code | VARCHAR(10) | ISO 3166-2 e.g. `MY-01` (Johor) |
| name | VARCHAR(100) | |
| type | ENUM('state','federal_territory','province') | |
| UK | (country_id, code) | |

### `locales` — not a table. Locale codes are BCP-47 strings validated against config (`en`, `ms`, later `zh`, `ta`, `id`, `th`, `vi`).

---

## B. Taxonomy & catalogue

### `taxon_families`
| Column | Type | Notes |
|---|---|---|
| id | INT UNSIGNED PK | |
| scientific_name | VARCHAR(120) UK | `Channidae` |
| order_name | VARCHAR(120) | `Anabantiformes` |
| common_name_en | VARCHAR(120)? | "Snakeheads" |
| common_name_ms | VARCHAR(120)? | "Ikan haruan / toman" |

### `fish_species`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| public_id | CHAR(26) UK | ULID |
| scientific_name | VARCHAR(160) UK | **Canonical accepted name**, e.g. `Channa micropeltes` |
| authority | VARCHAR(120)? | `(Cuvier, 1831)` |
| genus | VARCHAR(80) | IX |
| family_id | FK taxon_families | IX |
| slug | VARCHAR(180) UK | `channa-micropeltes` |
| water_types | SET('freshwater','brackish','marine') | |
| max_length_cm | DECIMAL(6,1)? | |
| max_weight_kg | DECIMAL(7,2)? | |
| description | TEXT? | Original FishDex copy (translatable via `species_translations`) |
| identification_notes | TEXT? | Key features vs look-alikes; also fed to AI re-rank |
| lookalike_species_ids | JSON? | Array of species ids commonly confused with this one |
| taxonomy_status | ENUM('accepted','provisional','under_review') | |
| external_refs | JSON? | `{gbif_key, fishbase_id, worms_aphia_id}` |
| is_published | BOOL | Only published species visible to users / used in matching |
| published_at | TIMESTAMP? | |
| deleted_at | TIMESTAMP? | Soft delete; never hard delete species that have encounters |

### `species_translations`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| species_id | FK | |
| locale | VARCHAR(10) | |
| description | TEXT? | |
| identification_notes | TEXT? | |
| fun_fact | VARCHAR(500)? | Card flavour text |
| UK | (species_id, locale) | |

### `species_synonyms`
Scientific names change (revisions, misspellings, older names AI may return).
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| species_id | FK | |
| scientific_name | VARCHAR(160) UK | e.g. `Ophicephalus micropeltes` |
| normalized_name | VARCHAR(160) IX | lowercase, no authority |
| kind | ENUM('synonym','misspelling','former_name') | |
| source | VARCHAR(255)? | |

### `species_names`
All common / local names, per locale and optionally per country/region.
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| species_id | FK | IX |
| name | VARCHAR(160) | `Toman`, `Giant snakehead`, `Ikan toman` |
| normalized_name | VARCHAR(160) | IX; lowercase, stripped of "ikan " prefix, diacritics |
| locale | VARCHAR(10) | `en`, `ms` |
| country_id | FK? | null = applies everywhere |
| region_id | FK? | State-level dialect names |
| name_type | ENUM('common','local_alias','trade','juvenile') | e.g. juvenile toman "anak toman" |
| is_primary | BOOL | One primary per (species, locale, country) |
| is_ambiguous | BOOL | Name shared by several species (e.g. "baung") → matcher never auto-resolves on it |
| FULLTEXT | (name) | catalogue search |

### `habitats` / `species_habitats`
`habitats`: id, code UK (`river`, `lake_reservoir`, `paddy_canal`, `swamp_peat`, `mangrove_estuary`, `coastal_reef`, `open_sea`, `pond_kolam`), name_en, name_ms.
`species_habitats`: species_id FK, habitat_id FK, PK(species_id, habitat_id).

### `species_country_status`
| Column | Type | Notes |
|---|---|---|
| species_id | FK | PK part |
| country_id | FK | PK part |
| origin | ENUM('native','endemic','introduced','invasive','uncertain') | |
| is_present | BOOL | |
| distribution_notes | TEXT? | |
| legal_protection | ENUM('none','protected','restricted','sanctuary_only')? | National/state fisheries rules |
| legal_notes | VARCHAR(500)? | Shown in app |
| source | VARCHAR(255)? | |

### `species_region_presence`
species_id FK, region_id FK, confidence ENUM('confirmed','probable'), source VARCHAR? — PK(species_id, region_id). Used by AI geo-plausibility check and species detail map.

### `conservation_assessments`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| species_id | FK | IX |
| authority | ENUM('iucn','national','state') | |
| country_id | FK? | null for IUCN global |
| status_code | VARCHAR(10) | IUCN: `NE DD LC NT VU EN CR EW EX`; national codes free-form |
| assessed_year | SMALLINT? | |
| source_url | VARCHAR(500)? | |
| is_current | BOOL | One current per (species, authority, country) |

### `rarity_tiers`
| Column | Type | Notes |
|---|---|---|
| id | TINYINT PK | |
| code | VARCHAR(20) UK | `common`, `uncommon`, `rare`, `epic`, `legendary` |
| sort_order | TINYINT | |
| name_en / name_ms | VARCHAR(40) | |
| color_hex | CHAR(7) | Design token reference |
| foil_effect | VARCHAR(30) | `none`, `sheen`, `holo`, `prism`, `aurora` |
| reveal_animation | VARCHAR(30) | Client animation key |

### `species_rarities` (versioned, per country) — D-06
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| species_id | FK | |
| country_id | FK | |
| rarity_tier_id | FK | |
| basis | ENUM('editorial','computed','event') | |
| rationale | VARCHAR(500)? | Why this tier (catch difficulty, range, season) |
| effective_from | DATE | |
| effective_to | DATE? | null = current |
| set_by_admin_id | FK admins? | |
| IX | (species_id, country_id, effective_to) | Current lookup |

> Rarity reflects how hard a species is to **encounter** (range, habitat accessibility, season, catch difficulty). It must never be derived from endangered status. Collection rows snapshot the tier at collection time so later rebalancing doesn't silently change a user's history.

### `reference_images`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| public_id | CHAR(26) UK | |
| species_id | FK | |
| kind | ENUM('reference_photo','card_art','silhouette','diagram') | |
| disk / storage_key | VARCHAR | Public bucket |
| width, height | INT | |
| blurhash | VARCHAR(40)? | Placeholder |
| license | VARCHAR(50) | `owned`, `cc-by-4.0`, `cc-by-sa-4.0`, `licensed` … **NC licences rejected by validation** |
| attribution | VARCHAR(255)? | Required for CC-BY* |
| source_url | VARCHAR(500)? | |
| is_verified | BOOL | Verified correct species by curator |
| verified_by / verified_at | FK admins? / TIMESTAMP? | |
| is_primary | BOOL | |
| sort_order | SMALLINT | |

### `card_sets`
id, public_id, code UK (`MY-FW-S1`), name, country_id FK?, theme VARCHAR?, released_at DATE?, is_active BOOL, total_cards SMALLINT (cached).

### `fish_cards`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| public_id | CHAR(26) UK | |
| card_set_id | FK | |
| species_id | FK | |
| card_number | VARCHAR(10) | `017/150` displayed; UK(card_set_id, card_number) |
| variant | ENUM('standard','holo','alt_art','event') | MVP uses `standard`; holo treatment derives from rarity |
| artwork_image_id | FK reference_images | |
| flavor_text_key | VARCHAR(100)? | Pulls from species_translations by default |
| display_attributes | JSON? | e.g. habitat icon, water type badge |
| is_active | BOOL | |
| UK | (card_set_id, species_id, variant) | |

---

## C. Users & access

### `users`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| public_id | CHAR(26) UK | |
| handle | VARCHAR(30) UK | Public username |
| display_name | VARCHAR(60) | |
| email | VARCHAR(191) UK | |
| email_verified_at | TIMESTAMP? | |
| password | VARCHAR(255)? | null for social-only accounts |
| avatar_media_id | FK media? | |
| country_id | FK | default MY |
| home_region_id | FK regions? | |
| locale | VARCHAR(10) | |
| timezone | VARCHAR(40) | `Asia/Kuala_Lumpur` |
| units | ENUM('metric','imperial') | |
| default_location_visibility | ENUM('private','region') | default `private` |
| plan | VARCHAR(20) | `free` (entitlements later) |
| status | ENUM('active','suspended','pending_deletion') | |
| last_active_at | TIMESTAMP? | |
| deletion_requested_at | TIMESTAMP? | 30-day grace then purge job |
| deleted_at | TIMESTAMP? | |

### `social_accounts`
id, user_id FK, provider ENUM('google','apple'), provider_user_id VARCHAR(191), email VARCHAR?, UK(provider, provider_user_id).

### `personal_access_tokens` — Laravel Sanctum default, plus `expires_at` used.

### `devices`
id, user_id FK, device_uuid VARCHAR(64), platform ENUM('android','ios'), fcm_token VARCHAR(255)?, app_version VARCHAR(20), os_version VARCHAR(20)?, last_seen_at, UK(user_id, device_uuid).

### `admins`
Separate from `users` (D-13). id, name, email UK, password, two_factor_secret (encrypted), two_factor_recovery_codes (encrypted), two_factor_confirmed_at, last_login_at, is_active. Roles via `spatie/laravel-permission` tables with guard `admin`: `super_admin`, `catalogue_editor`, `reviewer`, `support`.

---

## D. Media & AI identification

### `media`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| public_id | CHAR(26) UK | |
| user_id | FK? | null for system media |
| purpose | ENUM('scan','encounter','avatar') | |
| disk | VARCHAR(30) | `r2_private` |
| storage_key | VARCHAR(255) | original (sanitised) |
| variants | JSON | `{card: key, thumb: key, ai: key}` |
| mime | VARCHAR(50) | verified by magic bytes |
| bytes | INT UNSIGNED | |
| width, height | SMALLINT UNSIGNED | |
| sha256 | CHAR(64) | IX — exact dedupe |
| phash | BIGINT UNSIGNED? | IX — perceptual hash, near-duplicate / stolen photo detection |
| exif_taken_at | DATETIME? | Extracted before stripping |
| exif_has_gps | BOOL | |
| status | ENUM('uploaded','processing','ready','rejected','purged') | |
| reject_reason | VARCHAR(50)? | |
| purge_after | TIMESTAMP? | Retention (doc 09) |

### `identification_requests` (one per scan)
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| public_id | CHAR(26) UK | = `scan_id` in API |
| user_id | FK | IX(user_id, created_at) |
| media_id | FK | |
| client_uuid | CHAR(36)? | Offline idempotency; UK(user_id, client_uuid) |
| country_id | FK | Catalogue context |
| region_id | FK? | From coarse location (geo plausibility) |
| status | ENUM('queued','waiting_capacity','processing','completed','failed','timed_out','rejected') | `waiting_capacity` = free-tier daily limit reached; processed after reset |
| outcome | ENUM('identified','no_fish','multiple_fish','unsupported_image','not_in_catalogue')? | |
| confidence_band | ENUM('high','medium','low')? | |
| top_species_id | FK fish_species? | |
| top_confidence | DECIMAL(5,4)? | Backend composite score 0–1 |
| flags | JSON? | `["possible_screen_photo","duplicate_image","exif_mismatch"]` |
| total_cost_usd_micros | BIGINT UNSIGNED | Sum of attempts |
| queued_at / started_at / completed_at | TIMESTAMP | |
| error_code | VARCHAR(50)? | |

### `identification_attempts` (one per provider call — retries, second pass, fallback)
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| identification_request_id | FK | |
| pass | ENUM('open','constrained') | D-08 |
| provider | VARCHAR(30) | `gemini`, `openai`, `anthropic` … |
| model | VARCHAR(60) | |
| prompt_version | VARCHAR(20) | e.g. `id-open-v3` |
| status | ENUM('success','error','timeout','invalid_output') | |
| http_status | SMALLINT? | |
| input_tokens / output_tokens | INT UNSIGNED | |
| cost_usd_micros | BIGINT UNSIGNED | From price table in config at call time |
| latency_ms | INT UNSIGNED | |
| response_json | JSON? | Parsed structured output (kept 90 days, then nulled) |
| error_message | VARCHAR(500)? | |

### `identification_candidates`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| identification_request_id | FK | |
| rank | TINYINT | 1..5 after re-ranking |
| raw_label | VARCHAR(160)? | Name returned by AI |
| raw_scientific_name | VARCHAR(160)? | |
| model_confidence | DECIMAL(5,4)? | Self-reported, *input only* |
| matched_species_id | FK? | |
| match_method | ENUM('scientific_exact','synonym','common_name','fuzzy','constrained_pick','none') | |
| match_score | DECIMAL(5,4) | |
| geo_plausible | BOOL? | |
| final_confidence | DECIMAL(5,4) | |
| visible_features | JSON? | AI explanation of features seen (shown as "why") |

### `ai_usage_daily` (roll-up, scheduler)
date, provider, model, requests, successes, failures, timeouts, input_tokens, output_tokens, cost_usd_micros, p50_latency_ms, p95_latency_ms — PK(date, provider, model).

### `user_scan_usage`
user_id, period_date DATE, scans_count, PK(user_id, period_date). Redis is the live counter; this is the durable copy for quotas and analytics.

---

## E. Gameplay & journal

### `fishing_trips`
id, public_id, user_id FK, title VARCHAR?, started_at, ended_at?, water_body_type ENUM('river','lake','pond','sea','estuary','other')?, region_id FK?, notes TEXT?, client_uuid?, deleted_at?.

### `fish_encounters`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| public_id | CHAR(26) UK | |
| user_id | FK | IX(user_id, caught_at) |
| trip_id | FK? | |
| species_id | FK? | null while pending / unidentified; IX(user_id, species_id) |
| identification_request_id | FK? UK | One encounter per scan |
| primary_media_id | FK media? | |
| status | ENUM('pending_confirmation','confirmed','unidentified','rejected') | |
| identification_source | ENUM('ai_confirmed','user_selected','manual','expert_corrected') | |
| confidence_band | ENUM('high','medium','low')? | Snapshot |
| is_verified | BOOL | true for ai_confirmed high/medium or expert |
| decision | ENUM('undecided','collect','release') | |
| decision_at | TIMESTAMP? | |
| collect_eligible | BOOL | Computed by eligibility rules (PRD 7.2) |
| encounter_type | ENUM('catch','observation') | |
| physical_outcome | ENUM('released_alive','kept','unknown')? | |
| caught_at | DATETIME | UTC |
| length_cm | DECIMAL(6,1)? | |
| weight_kg | DECIMAL(7,3)? | |
| method | ENUM('casting_lure','bait','fly','jigging','trolling','net','handline','observation','other')? | |
| tackle_notes | VARCHAR(255)? | |
| location_precise | TEXT? | **Encrypted** `{lat,lng,accuracy}` (Laravel `encrypted:array` cast) |
| geohash5 | CHAR(5)? | ~5 km cell; IX — queryable |
| region_id | FK? | |
| country_id | FK | |
| location_visibility | ENUM('private','region') | |
| notes | TEXT? | |
| weather | JSON? | Later |
| client_uuid | CHAR(36)? | UK(user_id, client_uuid) |
| deleted_at | TIMESTAMP? | |

### `encounter_media`
encounter_id FK, media_id FK, sort_order TINYINT, PK(encounter_id, media_id).

### `user_species_collections` — D-04 / D-05
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| user_id | FK | |
| species_id | FK | |
| **UK** | (user_id, species_id) | Never duplicated |
| state | ENUM('seen','collected') | Only upgrades seen → collected |
| card_id | FK fish_cards? | Card displayed in dex |
| rarity_tier_id_snapshot | FK rarity_tiers? | Tier when first collected |
| first_seen_at | DATETIME | |
| first_collected_at | DATETIME? | |
| first_encounter_id | FK? | |
| first_collected_encounter_id | FK? | |
| latest_encounter_id | FK? | |
| encounter_count | INT UNSIGNED | All confirmed encounters |
| collected_count | INT UNSIGNED | Encounters where decision = collect |
| released_count | INT UNSIGNED | |
| best_length_cm / best_length_encounter_id | DECIMAL / FK? | Personal best |
| best_weight_kg / best_weight_encounter_id | DECIMAL / FK? | |
| cover_media_id | FK media? | User-chosen photo for their copy |
| is_favorite | BOOL | |
| IX | (user_id, state) | |

### `user_stats` (denormalised cache, recomputed by listeners + nightly reconciliation)
user_id PK, species_seen, species_collected, encounters_total, encounters_released, by_rarity JSON, by_family JSON, by_habitat JSON, completion_by_set JSON, last_encounter_at, recalculated_at.

---

## F. Moderation, config & audit

### `review_items`
id, public_id, encounter_id FK?, identification_request_id FK?, reason ENUM('low_confidence_user_pick','random_sample','duplicate_image','possible_screen_photo','user_dispute','report'), status ENUM('open','confirmed','corrected','rejected'), resolved_species_id FK?, assigned_admin_id FK?, resolution_notes, resolved_at.
Resolution `corrected` updates the encounter species (source = `expert_corrected`) and re-runs collection aggregation for that user.

### `app_settings`
key VARCHAR(100) PK, value JSON, updated_by_admin_id. Holds AI thresholds, quotas, kill-switch, min app version. Cached in Redis.

### `activity_log`
`spatie/laravel-activitylog` — every admin change to catalogue, rarity, review outcomes.

### Deferred (create when needed)
`entitlements`, `purchases`, `play_rtdn_events` (Google Play billing); `achievements`, `user_achievements`; `friendships`, `trades`.

---

## G. Schema decisions

### D-02 Surrogate keys + ULID; scientific name as canonical business key
- **Why needed:** Spec requires scientific names as the canonical identifier, but scientific names get revised by taxonomists.
- **Advantages:** `scientific_name` is UNIQUE and is what the matcher and admins use; renames are an `UPDATE` + new synonym row, with zero FK churn. ULIDs prevent ID enumeration and are sortable.
- **Disadvantages:** Two identifiers to understand; slightly larger indexes for ULID columns.
- **Scalability:** Safe for multi-country merges — the same species in Malaysia and Thailand is one row.

### D-03 Country-scoped satellite tables
- **Why needed:** SEA expansion must not require restructuring.
- **Advantages:** Adding Indonesia = insert a `countries` row, names (`locale=id`), origin status, rarities, a card set. No migrations. Rarity can differ per country (a common Thai fish may be rare in Malaysia).
- **Disadvantages:** Queries need a country context; more joins (mitigated by Redis-cached catalogue payloads).
- **Scalability:** Linear in data; species shared across countries are stored once.

### D-05 Seen vs Collected in one row
- **Why needed:** Release must not erase the discovery, and the dex should show progress (silhouette → seen → collected), a proven collection mechanic.
- **Advantages:** Encourages catch-and-release without penalty; single row per user+species.
- **Disadvantages:** Product must explain the difference clearly.
- **Scalability:** No impact.

### Precise location encrypted, coarse location indexed (D-12)
- **Why needed:** Anglers treat spots as secrets; leaked spots get overfished.
- **Advantages:** Even a DB leak doesn't reveal spots; geohash5 still allows regional stats.
- **Disadvantages:** Cannot run spatial queries on precise points.
- **Scalability:** If a future feature needs spatial search, add an opt-in `public_spot` table with fuzzed `POINT` + SPATIAL index.

### Indicative volumes (Year 1, 50k users)
| Table | Rows |
|---|---|
| fish_species | 300–800 (MY) |
| fish_encounters | ~2–4M |
| identification_requests | ~3–5M (partition by month if > 20M) |
| identification_candidates | ~12–20M (prune to top 3 after 180 days) |
| user_species_collections | ~1–2M |
