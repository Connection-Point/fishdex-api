# FishDex API — notes for AI assistants

- Source of truth for architecture: `docs/*.md` (decision log in `docs/00-README.md`). Keep code and docs in sync; add a Decision Log line when a decision changes.
- Domain code lives in `app/Domain/<Module>`; HTTP in `app/Http/Api/V1`; admin in `app/Filament`. Modules talk via Actions/Services/Events.
- Rules that must never be broken:
  - AI never assigns rarity or any game value (prompts and schema must not mention it).
  - Only `public_id` ULIDs leave the API; all JSON goes through `Http/Api/V1/Presenters/Presenter.php`.
  - One `user_species_collections` row per (user, species); it is always rebuilt from encounters via `CollectionAggregator::rebuild()`.
  - Low-confidence / manual / flagged encounters are not collect-eligible (`EligibilityPolicy`).
  - Precise locations are encrypted casts and never sent to AI, analytics or other users.
  - Free-tier AI (D-16): respect `AiCapacity`; never rotate keys/projects to evade limits.
- Tests: `php artisan test` must stay green; use `FakeProvider::push()` to script AI results. Prefer verifying migrations on MySQL/MariaDB as well as SQLite.
- Style: `vendor/bin/pint`.
