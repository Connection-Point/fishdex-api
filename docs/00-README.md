# FishDex — Architecture & Technical Planning Pack

Status: **v0.3 — backend (this repo) complete through M3; Android app shell (M2) in the fishdex-app repo (2026-11-25).**

These docs live in the **fishdex-api** repository and cover both the backend and the app.
Date: 2026-11-25

This folder is the source of truth for FishDex architecture. Every later implementation step must trace back to a decision recorded here. Change a decision by editing the relevant document and adding a line to the Decision Log below.

## Contents

| # | Document | What it answers |
|---|----------|-----------------|
| 01 | [PRD](01-prd.md) | What we are building, for whom, and what "done" means for the MVP |
| 02 | [System Architecture](02-system-architecture.md) | How the pieces fit together and why |
| 03 | [Database ERD](03-database-erd.md) | Entity relationships (Mermaid diagrams) |
| 04 | [Database Schema](04-database-schema.md) | Every table, column, index and constraint |
| 05 | [Laravel Module Structure](05-laravel-modules.md) | Backend code organisation |
| 06 | [Flutter Folder Structure](06-flutter-structure.md) | Mobile code organisation |
| 07 | [REST API Specification](07-api-spec.md) | Endpoints, payloads, errors |
| 08 | [AI Identification Workflow](08-ai-identification-workflow.md) | Scan → candidates → match → confidence → confirm |
| 09 | [Storage Strategy](09-storage-strategy.md) | Buckets, keys, image pipeline, retention |
| 10 | [Security & Authorization](10-security-authorization.md) | Auth, policies, privacy, PDPA, abuse prevention |
| 11 | [Operational Cost Model](11-cost-model.md) | What it costs to run, per tier, and unit economics |
| 12 | [Development Milestones](12-milestones.md) | Build order, estimates, acceptance criteria |

## How decisions are documented

Every important decision is written as a short ADR block:

> **Decision** — what we chose
> **Why needed** · **Advantages** · **Disadvantages** · **Scalability implications**

## Decision Log (summary)

| ID | Decision | Doc |
|----|----------|-----|
| D-01 | Laravel **modular monolith** (not microservices) | 02 |
| D-02 | Surrogate numeric PK + public **ULID**; scientific name is the canonical *business* identifier, with a synonyms table | 04 |
| D-03 | Country-scoped tables for names, origin status, conservation and **rarity** so SEA expansion = data, not migrations | 04 |
| D-04 | Encounter (event) ≠ Collection entry (unique per user+species) ≠ Species ≠ Card | 03/04 |
| D-05 | Collection entry has **seen / collected** states; Release still marks the species as *seen* | 01/04 |
| D-06 | Rarity is versioned admin data per country; AI prompt never asks for or returns rarity | 04/08 |
| D-07 | Asynchronous scan pipeline on Redis queues (Horizon); client polls, FCM as a nudge | 02/08 |
| D-08 | Two-pass AI: open-vocabulary identification, then constrained re-rank against the verified catalogue when needed | 08 |
| D-09 | Composite confidence score computed by the backend (model confidence is only one input) with admin-tunable bands | 08 |
| D-10 | Low confidence → *unidentified* encounter; never a collected card | 08 |
| D-11 | Cloudflare R2 (S3 API): private bucket for user media, public bucket + CDN for card art | 09 |
| D-12 | Precise catch coordinates are private and encrypted; only coarse geohash/state is queryable | 10 |
| D-13 | Separate `admin` guard + table for Filament with 2FA | 10 |
| D-14 | Feature-first Flutter architecture with Riverpod + offline capture queue (Drift) | 06 |
| D-15 | Per-user scan quotas + global AI budget kill-switch from day one | 08/11 |
| D-17 | Implementation choices (backend build): Laravel 13 + Filament 5; admin roles as a `role` column (no permission package); image processing with PHP GD (no Intervention); per-user scan quota counted on the user's local day (AI capacity still resets on Pacific time); `species_country_status` uses a surrogate id | 05 |
| D-19 | Flutter implementation choices: Flutter 3.50 with `material_ui` (Material moved out of the SDK); models are plain Dart `fromJson` (no freezed/codegen) verified against real API fixtures + a live contract test; router refreshes only on sign-in status changes | 06 |
| D-18 | Closed beta is **Android-only**; iOS (Apple Sign-In, App Store billing) follows after beta | 01/12 |
| D-16 | Closed beta on **Gemini API free tier**; capacity-aware queue (`waiting_capacity`), pass 2 off, consent disclosure; paid tier before open beta/production (config switch only) | 08/11/12 |

## Open questions for the product owner

The backend was built using the defaults below (Q1 and Q2 as written). Change them before launch if needed.

1. **Collect vs Release meaning.** This plan assumes *Collect = add the card to the FishDex* and *Release = log in journal only, species shown as "seen"*. The physical fate of the fish (kept / released alive) is recorded separately. Confirm.
2. **Manual entries.** This plan allows journal entries without a photo but they can never produce a collected card (anti-cheat). Confirm.
3. **Data licensing budget.** FishBase and the IUCN Red List have restrictions on commercial reuse (verify current terms). Are we willing to license, or should we compile from openly licensed sources + our own curation?
4. ~~iOS at launch?~~ **Decided (D-18):** Android-only for the beta; iOS after.
5. **Monetization intent for v1.** Deferred, but a free-tier scan limit is needed for cost control. Is a subscription the intended model?
6. **Name/brand check.** Run a trademark and Play Store search on "FishDex" before investing in branding.
