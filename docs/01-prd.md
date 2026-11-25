# 01 — Product Requirements Document (PRD)

Version 0.1 · Status: Draft for approval

## 1. Vision

FishDex turns real fishing and fish-spotting into a premium collectible card experience. Every real fish you photograph becomes a discovery; every verified species becomes a beautifully designed card in your personal FishDex. It starts with Malaysian freshwater and marine species and grows to Southeast Asia.

## 2. Problem

- Malaysian anglers catch many species but often only know a local name (e.g. *toman*, *haruan*, *sebarau*), not the scientific identity, origin (native vs introduced) or protection status.
- Existing fish ID apps are generic, Western-focused, and look like utilities rather than something people want to open daily.
- Fishing logs are tedious; nothing rewards the habit of recording catches.

## 3. Target users

| Persona | Description | Primary need |
|---------|-------------|--------------|
| **Casual angler** (core) | Weekend freshwater/pond/kolam angler, 18–45, Android | "What fish is this?" + something fun to collect |
| **Sport angler** | Casting for toman, sebarau, kelah, siakap; tracks personal bests | Journal, size records, rarity bragging rights |
| **Naturalist / observer** | Snorkellers, aquarium hobbyists, nature walkers | Identify fish without catching (observation encounters) |
| **Family / young adults** | Parents fishing with kids | Simple, safe, visually rewarding |

## 4. Goals and success metrics (first 6 months after launch)

| Goal | Metric | Target |
|------|--------|--------|
| Accurate identification | Top-3 accuracy on internal evaluation set (catalogue species) | ≥ 90% |
| | Top-1 accuracy | ≥ 75% |
| Fast feedback | Scan → result p95 latency | ≤ 10 s |
| Engagement | D30 retention | ≥ 15% |
| Collection habit | Median species collected per active user (month 3) | ≥ 8 |
| Cost control | AI cost per monthly active user | ≤ USD 0.10 |
| Trust | % of confirmed IDs later corrected by expert review | ≤ 5% |

## 5. Core concepts (glossary — binding)

| Concept | Definition |
|---------|-----------|
| **Fish Species** | A biological species from the verified catalogue, identified canonically by its accepted scientific name. Owned by admins. |
| **Fish Card** | The visual collectible representing a species within a card set (artwork, number, rarity frame). One species can have multiple cards across sets/variants later. |
| **Fish Encounter** | One real-world event: a fish caught or observed by a user at a time and place, with photo(s). Many per user per species. |
| **Collect / Release** | The user's decision on a *confirmed* encounter. **Collect** adds/updates the species card in their FishDex. **Release** keeps the encounter in the journal only; the species is marked *seen*. This is a game decision, independent of what physically happened to the fish. |
| **Physical outcome** | Optional journal field: released alive / kept / unknown. Never required, never penalised. |
| **User Fish Collection entry** | Exactly one per user per species. State is *seen* or *collected*. Aggregates counts, first/best encounters and the chosen cover photo. |
| **Rarity** | Game tier assigned by the backend per country (Common → Legendary). Never produced by AI. |
| **Conservation status** | Biological/legal status (IUCN category, national protection). Displayed as information; completely separate from rarity. |

## 6. MVP scope

### In scope

1. **Authentication** — email + password, Google Sign-In, email verification, password reset, logout from all devices, in-app account deletion (Google Play requirement).
2. **User profile** — display name, handle, avatar, home state, preferred units, privacy defaults.
3. **Malaysian fish catalogue** — browse, search (English, Malay, local aliases, scientific), filter by habitat, water type, family, origin, rarity; species detail page.
4. **AI scanner** — camera or gallery, client-side compression, upload, async identification, offline capture queue.
5. **Fish identification** — candidate list with backend-computed confidence, user confirmation, "not listed / not sure" path.
6. **Collect / Release** — decision on confirmed encounters, card reveal animation on first collect.
7. **TCG FishDex collection** — grid by card number/set, silhouettes for unseen, seen vs collected, rarity filters, holographic treatments.
8. **Species details** — names, taxonomy, habitat, distribution, origin, conservation, rarity, reference images, the user's own encounters.
9. **Fishing journal** — list/timeline of encounters, edit length/weight/method/notes, optional trip grouping.
10. **Collection statistics** — completion %, by rarity, by family, by habitat, personal bests, recent activity.
11. **Admin (Filament)** — catalogue CRUD, names/aliases, rarity, conservation, reference images, review queue for flagged identifications, AI usage dashboard, user management.

### Explicitly deferred

Social feed, friends, trading, leaderboards, competitions, advanced monetization (subscription UI may be stubbed), public catch maps, iOS (pending decision), multi-country catalogues, AR, offline on-device AI.

## 7. Key user flows

### 7.1 Scan → Collect
1. User opens Scanner, takes a photo (or picks from gallery).
2. App compresses, attaches optional location and capture time, uploads → receives `scan_id`.
3. App shows "identifying…" animation, polls status.
4. Result:
   - **High confidence**: top species pre-selected; user taps *Confirm*.
   - **Medium**: top 3 shown; user must pick one or "None of these".
   - **Low / no match**: no pre-selection; user may search the catalogue manually (encounter becomes *user-selected, unverified*) or save as *unidentified*.
   - **No fish / unusable**: friendly retry guidance.
5. After confirmation the user chooses **Collect** or **Release**.
6. Collect on a new species → card reveal animation (rarity-specific). Collect on an owned species → counters update, maybe "New personal best!".

### 7.2 Eligibility rules (anti-cheat & trust)

| Identification source | Can be collected? |
|----------------------|-------------------|
| AI high/medium + user confirmed | Yes |
| User picked from catalogue after low-confidence AI | Yes, flagged `unverified`; sampled into review queue |
| AI low confidence, user did not pick | No — saved as *unidentified* |
| Manual journal entry without photo | No — journal only |
| Photo flagged as screen/print/duplicate of another user's image | No — sent to review |

### 7.3 Protected species
If a confirmed species has national legal protection in the user's country, the app shows an informational notice (e.g. "Protected species — please release") and never shows "Kept" as a celebrated outcome. Digital collection is still allowed: we reward the discovery, not the harvest.

## 8. Non-functional requirements

| Area | Requirement |
|------|-------------|
| Performance | API p95 < 300 ms (non-AI); scan p95 < 10 s; catalogue grid 60 fps on mid-range Android |
| Availability | 99.5% monthly for API during MVP |
| Offline | Capture and queue scans offline; browse cached catalogue offline |
| Localisation | English and Bahasa Malaysia UI at launch; schema supports any locale |
| Accessibility | Rarity never conveyed by colour alone (icon + label) |
| Privacy | PDPA 2010 (as amended) compliance; precise locations private by default |
| Devices | Android 8.0+ (API 26) at launch |

## 9. Risks

| Risk | Impact | Mitigation |
|------|--------|-----------|
| AI misidentifies similar species (e.g. Channa spp., groupers) | Trust loss | Composite confidence, top-3 UX, review queue, evaluation set, similar-species notes |
| AI cost grows faster than revenue | Business viability | Quotas, dedupe cache, model tiering, budget kill-switch |
| Data licensing (FishBase/IUCN/photos) | Legal | Licence register per image/record; curate own content |
| Cheating with internet photos | Collection integrity | Image hash dedupe, screen-photo detection, EXIF sanity, review |
| Encouraging harvest of protected species | Reputation / legal | Protected-species notices; rarity not based on endangerment |
| Location leaks of secret spots | User trust | Private encrypted coordinates, coarse public data only |
