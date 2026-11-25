# 09 — Storage Strategy

## D-11 Cloudflare R2 via the S3 API

- **Why needed:** FishDex is image-heavy: every scan, every card, every species page. Image egress dominates storage cost on traditional S3.
- **Advantages:** S3-compatible (Laravel `s3` driver, no lock-in); R2 does not charge for egress, which matters most for card art served to every user; Cloudflare CDN + WAF in the same account; free monthly allowance covers early beta.
- **Disadvantages:** Fewer advanced features than AWS S3 (e.g. fewer event integrations); vendor concentration with Cloudflare for both CDN and storage.
- **Scalability:** Storage scales without operational work. If we ever leave, the S3 API lets us migrate with standard sync tools; keys and code don't change.

Local development uses **MinIO** with identical bucket names.

## 1. Buckets

| Bucket | Access | Contents |
|---|---|---|
| `fishdex-media-private` | Private; access only via short-lived signed URLs | User scan photos, encounter photos, avatars, variants |
| `fishdex-assets-public` | Public through CDN custom domain `cdn.fishdex.app` | Card art, verified reference images, silhouettes, card frame textures |
| `fishdex-backups` | Private, separate credentials, write-only key for the backup job | DB dumps, catalogue exports |

Separate buckets (not just prefixes) so a public-bucket misconfiguration can never expose user photos.

## 2. Key layout

```
fishdex-media-private/
  u/{user_ulid}/scans/{yyyy}/{mm}/{media_ulid}/original.jpg
                                               ai_1024.jpg
                                               card_1080.webp
                                               thumb_320.webp
  u/{user_ulid}/avatar/{media_ulid}/256.webp

fishdex-assets-public/
  species/{species_ulid}/ref/{image_ulid}_{w}.webp
  cards/{set_code}/{card_number}_{variant}_{w}.webp
  silhouettes/{species_ulid}.webp
```

- User-scoped prefix makes account deletion a single prefix purge.
- ULIDs in keys are unguessable; no personal data in object keys.
- Public assets are **immutable** (new file per change) → `Cache-Control: public, max-age=31536000, immutable`.

## 3. Image pipeline

```
upload → ImageInspector (type, size, dimensions)
       → ImageSanitizer (decode, auto-orient, strip ALL metadata, re-encode JPEG q85)
       → store original
       → queue ProcessImageVariants: ai_1024 (JPEG), card_1080 (WebP), thumb_320 (WebP), blurhash, pHash
```

- Re-encoding neutralises malicious payloads and removes GPS EXIF from stored files. Location is only kept if the user supplied it via the API (and then encrypted in the DB).
- HEIC is converted on upload (Imagick with libheif) or, simpler, the app always uploads JPEG.

### Upload path decision
- **MVP:** multipart upload through the Laravel API.
  - **Advantages:** validation before anything is stored, simple auth/quota enforcement, one request.
  - **Disadvantages:** PHP workers handle bytes (fine at ~0.5 MB compressed images).
- **Scale-up option:** presigned PUT to an `incoming/` prefix + `POST /scans` referencing the key; a worker validates and moves it. Switch when upload traffic noticeably loads web nodes. The API contract (`scan_id`, polling) doesn't change.

## 4. Serving

| Asset | How |
|---|---|
| User photos | API returns signed GET URLs (TTL 15 min for full, 6 h for thumbnails) — app caches by `media_ulid`, not by URL |
| Card art / reference images | Public CDN URLs, immutable, cached on device by `cached_network_image` |
| Catalogue snapshot | JSON from API with ETag; app stores in Drift |

## 5. Retention & lifecycle

| Data | Retention |
|---|---|
| Media of scans never confirmed (abandoned) | Purge after 14 days |
| Rejected uploads | Purge after 24 h |
| `identification_attempts.response_json` | Null after 90 days (metrics kept) |
| Encounter photos | Kept while account exists |
| Deleted account | Grace 30 days → purge prefix `u/{user_ulid}/`, anonymise rows (keep aggregated, non-personal stats) |
| DB backups | Daily, keep 14 daily + 8 weekly + 6 monthly; encrypted |

Lifecycle is executed by Laravel scheduler jobs using `media.purge_after` (portable across S3 providers) rather than provider-specific lifecycle rules.

## 6. Licensing register for public images

Every `reference_images` row records licence, attribution and source. Validation rejects non-commercial (`-NC`) and no-derivatives (`-ND`) licences for anything shown on cards. Attribution is displayed on species pages where the licence requires it.

## 7. Estimated storage growth

Per confirmed scan ≈ original 0.4 MB + variants 0.25 MB ≈ **0.65 MB**.
10k MAU × 8 scans/month ≈ 80k scans ≈ **52 GB/month** added → ~0.6 TB/year. At published R2 standard pricing (~USD 0.015/GB-month) that is under USD 10/month after a year — storage is not a meaningful cost driver; AI is (doc 11).
