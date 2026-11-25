# 05 — Laravel Module Structure

Implemented with Laravel 13, PHP 8.3+, Filament 5 (see the repository README for the actual code map).

## 1. Approach

Domain modules live under `app/Domain/*` as plain PSR-4 namespaces — no third-party "modules" package. HTTP concerns live under `app/Http/Api/V1`. Filament lives under `app/Filament`. Modules talk to each other through **Actions** (public use-cases), **Contracts** and **domain events**, not by reaching into each other's models arbitrarily.

### Decision: plain namespaced domains (no nwidart/laravel-modules)
- **Why needed:** Clear boundaries for a growing commercial codebase without framework friction.
- **Advantages:** Works with every Laravel tool (Filament discovery, Pest, Larastan) without config hacks; easy to onboard developers.
- **Disadvantages:** Boundaries are by convention; enforce with Pest architecture tests (`arch()->expect('App\Domain\Catalogue')->not->toUse('App\Domain\Encounter\Models')`).
- **Scalability:** A module with clean Contracts can later be extracted into a package or service.

## 2. Folder tree

```
app/
├── Domain/
│   ├── Shared/
│   │   ├── Concerns/HasPublicId.php          # ULID trait
│   │   ├── Enums/                             # cross-module enums
│   │   ├── ValueObjects/Money.php, GeoPoint.php, Geohash.php
│   │   └── Support/NameNormalizer.php         # Malay/English name normalisation
│   │
│   ├── Identity/                              # users, auth, devices, account deletion
│   │   ├── Models/User.php, SocialAccount.php, Device.php
│   │   ├── Actions/RegisterUser.php, LoginWithGoogle.php, RequestAccountDeletion.php, PurgeUser.php
│   │   ├── Data/                              # spatie/laravel-data DTOs
│   │   ├── Events/UserRegistered.php
│   │   └── Policies/UserPolicy.php
│   │
│   ├── Catalogue/                             # species, names, taxonomy, rarity, conservation
│   │   ├── Models/FishSpecies.php, TaxonFamily.php, SpeciesName.php, SpeciesSynonym.php,
│   │   │          SpeciesCountryStatus.php, ConservationAssessment.php, RarityTier.php,
│   │   │          SpeciesRarity.php, Habitat.php, ReferenceImage.php
│   │   ├── Queries/SpeciesSearchQuery.php     # filters + FULLTEXT
│   │   ├── Services/RarityResolver.php        # current tier for (species, country)
│   │   ├── Services/CatalogueSnapshot.php     # cached, versioned payload for app sync
│   │   ├── Actions/PublishSpecies.php, AssignRarity.php
│   │   └── Contracts/SpeciesLookup.php        # used by Identification matcher
│   │
│   ├── Cards/                                 # card sets and cards (visual layer)
│   │   ├── Models/CardSet.php, FishCard.php
│   │   └── Services/CardResolver.php          # which card a user sees for a species
│   │
│   ├── Media/
│   │   ├── Models/Media.php
│   │   ├── Actions/StoreUpload.php            # validate → sanitise → store
│   │   ├── Jobs/ProcessImageVariants.php
│   │   ├── Services/ImageInspector.php        # magic bytes, dimensions, EXIF extract
│   │   ├── Services/ImageSanitizer.php        # re-encode, strip EXIF, orientation
│   │   ├── Services/PerceptualHasher.php
│   │   └── Services/SignedUrlFactory.php
│   │
│   ├── Identification/                        # AI pipeline (doc 08)
│   │   ├── Contracts/IdentificationProvider.php
│   │   ├── Providers/GeminiProvider.php, OpenAiProvider.php, FakeProvider.php (tests)
│   │   ├── Prompts/OpenIdentificationPrompt.php, ConstrainedRerankPrompt.php
│   │   ├── Schema/IdentificationResultSchema.php   # JSON schema for structured output
│   │   ├── Models/IdentificationRequest.php, IdentificationAttempt.php, IdentificationCandidate.php
│   │   ├── Jobs/RunIdentification.php
│   │   ├── Services/CandidateMatcher.php      # AI names → catalogue species
│   │   ├── Services/ConfidenceScorer.php      # composite score + band
│   │   ├── Services/GeoPlausibility.php
│   │   ├── Services/CostCalculator.php        # tokens → micro-USD from config price table
│   │   ├── Services/ProviderRouter.php        # primary/fallback + circuit breaker
│   │   ├── Guards/ScanQuota.php, AiBudgetGuard.php
│   │   └── Events/IdentificationCompleted.php
│   │
│   ├── Encounter/                             # journal, trips, collect/release
│   │   ├── Models/FishEncounter.php, FishingTrip.php
│   │   ├── Actions/ConfirmIdentification.php  # scan → encounter
│   │   ├── Actions/DecideEncounter.php        # collect | release
│   │   ├── Actions/CreateManualEncounter.php
│   │   ├── Services/EligibilityPolicy.php     # PRD 7.2 rules
│   │   ├── Events/EncounterConfirmed.php, EncounterDecided.php, EncounterCorrected.php
│   │   └── Policies/FishEncounterPolicy.php, FishingTripPolicy.php
│   │
│   ├── Collection/                            # per-user dex aggregate + stats
│   │   ├── Models/UserSpeciesCollection.php, UserStats.php
│   │   ├── Listeners/ApplyEncounterToCollection.php   # idempotent upsert
│   │   ├── Services/CollectionAggregator.php  # full rebuild for one user
│   │   ├── Services/StatsCalculator.php
│   │   └── Events/SpeciesCollected.php (first collect → reveal + push)
│   │
│   ├── Review/
│   │   ├── Models/ReviewItem.php
│   │   └── Actions/ResolveReview.php
│   │
│   ├── Notifications/
│   │   └── Channels/FcmChannel.php, Notifications/ScanCompleted.php
│   │
│   └── Billing/                               # deferred; skeleton only
│       └── Contracts/EntitlementService.php   # returns quota for user (free plan in MVP)
│
├── Http/
│   ├── Api/V1/
│   │   ├── Controllers/{Auth,Me,Species,Scan,Encounter,Trip,Collection,Card,Stats,AppConfig}Controller.php
│   │   ├── Requests/...                       # FormRequests (validation only)
│   │   └── Resources/...                      # JsonResources (public_id only, never internal ids)
│   └── Middleware/EnsureAppVersionSupported.php, AssignRequestId.php
│
├── Filament/
│   ├── Resources/FishSpeciesResource (relation managers: names, synonyms, rarity, conservation, images, presence)
│   ├── Resources/TaxonFamilyResource, CardSetResource, FishCardResource, UserResource, ReviewItemResource
│   ├── Pages/AiUsageDashboard.php, AppSettings.php
│   └── Widgets/ScanVolumeChart, CostToday, IdentificationAccuracy
│
└── Console/Commands/ImportCatalogueCsv.php, RebuildCollections.php, RunAiEvaluation.php

config/fishdex.php          # thresholds defaults, quotas, AI price table, prompt versions
database/seeders/Catalogue/ # MY families, habitats, rarity tiers, regions
tests/
├── Arch/                   # module boundary rules
├── Unit/Identification/    # matcher, scorer with fixtures
├── Feature/Api/V1/         # endpoint tests with FakeProvider
└── Evaluation/             # dataset manifests for RunAiEvaluation (not run in CI)
```

## 3. Request → domain flow example (confirm + collect)

```
POST /api/v1/scans/{scan}/confirmation
  → ConfirmationRequest (validate)
  → ScanController@confirm
      → authorize (IdentificationRequestPolicy: owner + completed)
      → Encounter\Actions\ConfirmIdentification
           → EligibilityPolicy
           → create FishEncounter (DB transaction)
           → dispatch EncounterConfirmed
  ← EncounterResource (201)

POST /api/v1/encounters/{encounter}/decision {collect}
  → Encounter\Actions\DecideEncounter
      → dispatch EncounterDecided
          → Collection\Listeners\ApplyEncounterToCollection (sync, same transaction)
              → upsert user_species_collections (row lock)
              → if first collect: SpeciesCollected → queued stats + analytics
  ← { encounter, collection_entry, is_new_species, reveal: { rarity, card } }
```

Collection updates run **synchronously inside the transaction** so the reveal response is authoritative; heavier stats recompute is queued.

## 4. Key packages

| Package | Purpose |
|---|---|
| laravel/sanctum | Mobile API tokens |
| laravel/socialite | Google token verification |
| laravel/horizon | Redis queue management |
| filament/filament | Admin |
| *(built-in)* `admins.role` + `AuthorizesByAdminRole` | Admin roles (replaced spatie/laravel-permission — 4 fixed roles don't need a package) |
| *(built-in)* `AdminAuditObserver` → `admin_activity_log` | Admin audit trail (replaced spatie/laravel-activitylog) |
| league/flysystem-aws-s3-v3 | R2 via S3 driver |
| PHP GD (built-in) | Re-encode, resize, EXIF strip, dHash (replaced intervention/image) |
| kreait/laravel-firebase | FCM |
| sentry/sentry-laravel | Error tracking (to add at M6) |
| PHPUnit + Pint | Tests + style (Pest/Larastan optional) |

The AI provider SDK is wrapped by our own `IdentificationProvider`; we call providers via Laravel's HTTP client to avoid SDK lock-in.
