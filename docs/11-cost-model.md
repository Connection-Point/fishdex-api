# 11 — Operational Cost Model

All figures USD/month unless stated. Prices checked 2026-11-25; provider prices change often — re-verify before budgeting. Infrastructure figures are planning estimates, not quotes.

## 1. Cost drivers

| Driver | Share at scale | Notes |
|---|---|---|
| **AI identification** | 70–85% | Scales linearly with scans |
| Compute (web, workers) | 10–20% | Steps up in tiers |
| Database / Redis | 5–10% | Managed services from growth tier |
| Object storage (R2) | < 2% | Zero egress; storage cheap |
| Firebase (FCM, Crashlytics, Analytics) | ~0 | Free products at our usage |
| Email (transactional) | < 2% | Verification, reset |

**Conclusion:** the business lives or dies on *AI cost per scan × scans per user*. That is why quotas, dedupe, model tiering and the budget kill-switch are in the MVP.

## 2. AI cost per scan (reference: Gemini Flash-class model)

Reference pricing (Gemini 3.6 Flash, Google): introductory USD 0.75 / 3.75 per 1M input/output tokens until 31 Dec 2026, then **USD 1.50 / 7.50 from 1 Jan 2027**. We model on the 2027 price.

| Component | Tokens | Cost |
|---|---|---|
| Pass 1 input (1024 px image + prompt) | ~1,500 | $0.00225 |
| Pass 1 output (JSON, minimal reasoning) | ~400 | $0.00300 |
| Pass 2 (≈25% of scans): ~4,000 in / 300 out | — | $0.00825 × 0.25 = $0.00206 |
| Retries / invalid output (~5%) | — | ~$0.00037 |
| **Blended per scan** | | **≈ $0.0077 → model as $0.008** |

Sensitivities:
- Reasoning/"thinking" tokens are billed as output — keep them off or budgeted for pass 1.
- A cheaper "lite" model for pass 1 could cut this to roughly $0.003; only adopt if the evaluation set shows acceptable accuracy.
- Dedupe cache and failed-scan non-charging reduce real volume ~5–10%.

## 2a. Closed beta on free-tier AI (D-16)

Closed beta uses the Gemini API free tier: **AI cost = USD 0**. The trade-off is capacity, not money: the number of scans per day is capped by the project's free requests-per-day limit (shown in AI Studio), so the beta size is set by that number. Example: 250 RPD × 80% usable ≈ 200 scans/day ≈ 40 testers at 5 scans/day. If Google lowers the free limit, the beta shrinks or we switch to paid — design doc 08 §11 lists the triggers.

Closed beta total ≈ **USD 30–60/month** (one VPS, domain, everything else on free tiers).

## 3. Monthly cost by tier (paid AI)

Assumption: **8 scans per MAU per month** (to validate in beta).

| Item | Beta (1k MAU) | Growth (10k MAU) | Scale (100k MAU) |
|---|---|---|---|
| Scans / month | 8,000 | 80,000 | 800,000 |
| AI @ $0.008 | $64 | $640 | $6,400 |
| Web / app servers | $25–40 (1 VPS, Singapore) | $60–90 (2 VPS + LB) | $300–500 |
| Queue workers | (same VPS) | $25–40 | $150–250 |
| MySQL | (same VPS, self-managed backups) | $60–100 managed | $250–450 managed + replica |
| Redis | (same VPS) | $15–30 | $60–120 |
| R2 storage | $0 (free tier) | ~$1–5 | ~$20–60 |
| Email (transactional) | $0–15 | $15–25 | $50–100 |
| Error tracking / monitoring | $0 (free tiers) | ~$26 | $80–150 |
| Domain, misc | ~$5 | ~$10 | ~$30 |
| **Total** | **≈ $95–170** | **≈ $850–970** | **≈ $7,350–8,100** |
| **Cost per MAU** | ~$0.10–0.17 | ~$0.09 | ~$0.075 |

With AI optimisation (lite pass-1 model + free-tier scan cap lowering average usage to ~5 scans/MAU) the Growth tier drops to roughly **$500–600/month**.

## 4. One-off and annual costs

| Item | Estimate |
|---|---|
| Google Play developer account | USD 25 one-time |
| Apple Developer Program (if iOS) | USD 99/year |
| Card artwork for launch set (e.g. 150 species) | Largest one-off: commissioned illustration varies widely (budget per piece × 150); alternative: curated, licensed photography with a consistent treatment |
| Reference image / data licensing | Depends on sources (see doc 01 open question 3) |
| Legal: privacy policy, ToS, PDPA review | One-time adviser fee |
| Trademark search/registration (MyIPO) | One-time |
| AI evaluation runs | ~USD 20–50 per full provider bake-off (2,000 images × 3 providers) |

## 5. Unit economics (illustrative — monetization is deferred)

Assume a premium subscription of **RM 9.90/month** (≈ USD 2.20 at an assumed ~RM 4.4/USD — verify the rate).

- Google Play service fee on subscriptions: 15% (fee programmes and regional rules are changing; confirm what applies to Malaysia when billing is built).
- Net per subscriber ≈ USD 1.87/month.

| Scenario (Growth tier) | Monthly cost | Subscribers to break even | Conversion needed (of 10k MAU) |
|---|---|---|---|
| Unoptimised | ~$910 | ~487 | ~4.9% |
| Optimised (lite model + free cap) | ~$550 | ~295 | ~3.0% |

Freemium apps commonly convert low single-digit percentages, so **the free tier must be capped** (e.g. N free scans per day/month) and premium should unlock things that don't cost AI money (holo variants, advanced stats, unlimited journal photos, export) as well as more scans.

## 6. Cost guardrails built into the design

1. Per-user daily/monthly scan quota (`app_settings`, by plan).
2. Global daily AI budget with alert at 80% and kill-switch at 100%.
3. `ai_usage_daily` roll-up + Filament dashboard: cost/day, cost/scan, cost/MAU.
4. Provider abstraction allows switching to a cheaper provider without app changes.
5. No AI call for duplicate images or manual entries.

## 7. References

- Gemini pricing: https://ai.google.dev/gemini-api/docs/pricing and https://cloud.google.com/vertex-ai/generative-ai/pricing
- Cloudflare R2 pricing: https://developers.cloudflare.com/r2/pricing
- Google Play service fees: https://support.google.com/googleplay/android-developer/answer/112622
