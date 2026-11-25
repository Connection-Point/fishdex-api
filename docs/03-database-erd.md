# 03 — Database ERD

Three diagrams keep this readable: **Catalogue**, **Player data**, **AI & media**. Full column definitions are in [04-database-schema.md](04-database-schema.md).

## 3.1 Catalogue (admin-owned, country-aware)

```mermaid
erDiagram
  countries ||--o{ regions : has
  taxon_families ||--o{ fish_species : groups
  fish_species ||--o{ species_synonyms : "also known as (scientific)"
  fish_species ||--o{ species_names : "common/local names"
  countries ||--o{ species_names : "scoped to (optional)"
  fish_species ||--o{ species_country_status : "origin per country"
  countries ||--o{ species_country_status : ""
  fish_species ||--o{ species_region_presence : "distribution"
  regions ||--o{ species_region_presence : ""
  fish_species }o--o{ habitats : "species_habitats"
  fish_species ||--o{ conservation_assessments : "IUCN / national"
  fish_species ||--o{ species_rarities : "rarity history"
  countries ||--o{ species_rarities : ""
  rarity_tiers ||--o{ species_rarities : ""
  fish_species ||--o{ reference_images : "verified photos/art"
  card_sets ||--o{ fish_cards : contains
  fish_species ||--o{ fish_cards : "represented by"
  countries ||--o{ card_sets : "scoped to"
  reference_images ||--o{ fish_cards : "artwork"

  fish_species {
    bigint id PK
    char26 public_id UK
    string scientific_name UK
    bigint family_id FK
    set water_types
    bool is_published
  }
  species_rarities {
    bigint species_id FK
    bigint country_id FK
    bigint rarity_tier_id FK
    date effective_from
    date effective_to
  }
  fish_cards {
    bigint id PK
    bigint card_set_id FK
    bigint species_id FK
    string card_number
    string variant
  }
```

## 3.2 Player data

```mermaid
erDiagram
  users ||--o{ devices : registers
  users ||--o{ fishing_trips : logs
  users ||--o{ fish_encounters : records
  fishing_trips ||--o{ fish_encounters : groups
  fish_species ||--o{ fish_encounters : "identified as (nullable)"
  fish_encounters ||--o{ encounter_media : has
  media ||--o{ encounter_media : ""
  users ||--o{ user_species_collections : owns
  fish_species ||--o{ user_species_collections : ""
  fish_encounters ||--o| user_species_collections : "first / best / latest"
  fish_cards ||--o{ user_species_collections : "displayed card"
  users ||--|| user_stats : "cached stats"

  fish_encounters {
    bigint id PK
    char26 public_id UK
    bigint user_id FK
    bigint species_id FK "null until confirmed"
    enum status "pending|confirmed|unidentified|rejected"
    enum identification_source
    enum decision "undecided|collect|release"
    enum physical_outcome
    datetime caught_at
  }
  user_species_collections {
    bigint user_id FK
    bigint species_id FK
    enum state "seen|collected"
    int encounter_count
    int collected_count
  }
```

**Key rule:** `user_species_collections` has `UNIQUE(user_id, species_id)`. Catching the same fish 50 times creates 50 `fish_encounters` and exactly one collection row. Species rows are never duplicated.

## 3.3 AI identification & media

```mermaid
erDiagram
  users ||--o{ media : uploads
  media ||--o{ identification_requests : "analysed by"
  identification_requests ||--o{ identification_attempts : "provider calls"
  identification_requests ||--o{ identification_candidates : yields
  fish_species ||--o{ identification_candidates : "matched to (nullable)"
  identification_requests ||--o| fish_encounters : "creates"
  fish_encounters ||--o{ review_items : "flagged for"
  admins ||--o{ review_items : resolves

  identification_requests {
    bigint id PK
    char26 public_id UK
    enum status
    enum confidence_band
    bigint top_species_id FK
    decimal top_confidence
  }
  identification_attempts {
    bigint id PK
    string provider
    string model
    string prompt_version
    int input_tokens
    int output_tokens
    bigint cost_usd_micros
    int latency_ms
  }
  identification_candidates {
    tinyint rank
    string raw_scientific_name
    bigint matched_species_id FK
    enum match_method
    decimal final_confidence
  }
```

## 3.4 Why these separations (D-04)

| Separation | Why needed | Advantage | Disadvantage | Scalability |
|-----------|-----------|-----------|--------------|-------------|
| Species vs Card | A species is biology; a card is a design artefact that can be re-released in new sets, variants or countries | Card redesigns/new seasons never touch biology data | One extra join for card views (cached) | New sets = new rows; no migration |
| Encounter vs Collection | Encounters are an append-only event log; the collection is a per-user aggregate | No duplicate species records; fast dex grid (one row per species); full history preserved for journal and stats | Aggregate must be kept in sync (event listener + nightly reconciliation) | Collection table grows with users × species collected (bounded, e.g. 100k users × 60 avg = 6M rows — fine for MySQL) |
| Identification request vs Encounter | Many scans are abandoned, failed or "no fish" | Encounter table stays clean; AI cost/quality analytics independent of gameplay | Two tables to inspect when debugging | Identification data can be archived/partitioned by month without touching gameplay |
| Rarity vs Conservation | Rarity is a game mechanic; conservation is a scientific/legal fact | Avoids incentivising hunting of endangered fish; each can change independently | Two concepts to explain in UI | Both are country-scoped |
