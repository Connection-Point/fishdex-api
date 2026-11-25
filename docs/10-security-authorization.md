# 10 — Security & Authorization Design

## 1. Authentication

### Mobile users — Laravel Sanctum personal access tokens
- **Why needed:** Simple, first-party token auth for a single mobile client against our own API.
- **Advantages:** Native to Laravel; tokens stored hashed (SHA-256) server-side; per-device tokens allow "log out other devices"; abilities scope tokens if needed.
- **Disadvantages:** Opaque tokens require a DB/Redis lookup per request (cached); no built-in refresh-token rotation.
- **Scalability:** Token lookups cached; for third-party API access later, add Laravel Passport (OAuth2) alongside — no rewrite.

Rules:
- One token per device (`device_name` = device UUID). Token expiry 60 days, sliding: re-issued on `GET /me` when < 14 days remain.
- Token stored in Android Keystore via `flutter_secure_storage`.
- Google Sign-In: app obtains Google ID token → backend verifies signature, audience (our client ID) and `email_verified` → links via `social_accounts`.
- Passwords: bcrypt/argon2id (Laravel default), min 8 chars, checked against breached-password list (`Password::uncompromised()`).
- Email verification required before scans count toward collection? **No** — required before account recovery features; avoids friction. (Product decision, can be changed.)
- Apple Sign-In becomes mandatory if iOS launches with Google Sign-In.

### Admins — D-13 separate guard
- `admins` table, session guard `admin`, Filament panel bound to it. TOTP 2FA mandatory. Optional Cloudflare Access / IP allowlist in front of `/admin`.
- **Advantages:** A compromised user token can never reach admin; admin accounts are not in the public user table; simpler audit.
- **Disadvantages:** Staff who also play need two accounts.
- **Scalability:** Role/permission model (spatie) grows with the team.

## 2. Authorization

Laravel **Policies** for every user-owned model; controllers call `$this->authorize()`; Resources never expose other users' data.

| Resource | Rule |
|---|---|
| IdentificationRequest | Owner only; confirm only when `completed` and not already confirmed |
| FishEncounter | Owner only for read/update/delete; decision only when `confirmed` + `collect_eligible` for collect |
| FishingTrip | Owner only |
| UserSpeciesCollection | Owner only; cover media must belong to owner's encounter of the same species |
| Media | Signed URL issued only to owner (or admins) |
| Species / Cards | Any authenticated user; unpublished species hidden |

Admin roles:
| Role | Can |
|---|---|
| super_admin | Everything, manage admins, app settings, kill-switch |
| catalogue_editor | Species, names, images, rarity, conservation, card sets |
| reviewer | Review queue, view encounter photos for review |
| support | View users, resend verification, suspend; no catalogue edits |

All admin mutations logged to `activity_log` (who, what, before/after).

**IDOR protection:** only ULIDs in URLs; route model binding scoped (`$user->encounters()->wherePublicId()`); feature tests assert 404 for another user's IDs.

## 3. Privacy & data protection

Malaysia's Personal Data Protection Act 2010 applies, including its 2024 amendments (e.g. data-breach notification and data protection officer obligations — confirm current requirements with a legal adviser before launch).

| Data | Treatment |
|---|---|
| Precise catch location | Optional, opt-in per scan; encrypted at rest (`encrypted:array` cast, app key in env/secret manager); only coarse geohash5 + state queryable; never sent to AI, analytics or other users |
| Photos | Private bucket, signed URLs, EXIF stripped |
| Email | Never shown to other users; handle is the public identity |
| Analytics | No PII, no coordinates in Firebase events; Analytics collection toggle in settings |
| AI provider | Only image + country/state. **Closed beta (free tier):** Google may use submitted images to improve its products and human reviewers may see them — disclosed in a beta consent screen and the beta privacy notice; testers told to avoid faces/documents in photos. **Paid tier (production):** prompts/images not used for training. |
| Account deletion | In-app + web form (Google Play requirement), 30-day grace, then purge media prefix and anonymise rows |
| Data export | `GET /me/export` (later milestone) — JSON of profile, encounters, collection |

Privacy policy, terms and a data-processing record are launch blockers (M6).

## 4. Abuse & integrity

| Threat | Control |
|---|---|
| Credential stuffing | Throttle login per email+IP, generic error messages, breached-password check |
| Scan farming / cost attacks | Per-user rate limit + quota, global AI budget guard, new-account lower quota for first 24 h |
| Scripted clients | Google Play Integrity API verdict on scan submission (M6+); require for collect-eligible scans once enabled |
| Fake collections from internet images | sha256/pHash dedupe across users, screen/print flag, review sampling |
| Malicious uploads | Magic-byte checks, size limits, full re-encode, no original bytes ever served |
| Account enumeration | Uniform responses on forgot-password and register conflicts handled carefully |
| Mass assignment | FormRequests + DTOs; `$fillable` never `$guarded = []` |
| SQL injection / XSS | Eloquent bindings; Filament escapes; API returns JSON only |

## 5. Infrastructure security

- HTTPS everywhere (Cloudflare full-strict TLS); HSTS.
- Cloudflare WAF + rate rules on `/api/v1/auth/*` and `/api/v1/scans`.
- Secrets in environment / secret manager; never in the app binary. AI keys only on the server (backend-controlled AI requests).
- Separate R2 credentials per bucket/purpose with least privilege (app: read/write media; backup job: write-only backups).
- MySQL and Redis bound to private network only; no public ports.
- Automated encrypted backups + quarterly restore drill.
- Dependency scanning (Dependabot / `composer audit`, `flutter pub outdated`) in CI.
- App: obfuscated release build (`--obfuscate --split-debug-info`), certificate pinning considered after launch (adds rotation risk).

## 6. Security decisions summary

| Decision | Why | + | − | Scale |
|---|---|---|---|---|
| Sanctum tokens | First-party mobile API | Simple, revocable per device | No OAuth for 3rd parties | Add Passport later |
| Separate admin guard | Blast-radius isolation | Strong separation | Two accounts for staff | Roles grow with team |
| Encrypted precise location | Spot secrecy + PDPA | Leak-resistant | No spatial queries | Opt-in public spots table later |
| ULID public IDs | IDOR/enumeration | Safe URLs | Slightly bigger indexes | Globally unique, mergeable |
| Server-only AI keys | Cost and abuse control | Central quota/rate limits | Server must be up to scan | Workers scale horizontally |
