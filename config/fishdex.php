<?php

/*
|--------------------------------------------------------------------------
| FishDex configuration
|--------------------------------------------------------------------------
| Defaults only. Values marked "tunable" can be overridden at runtime from
| the admin panel via the app_settings table (see SettingsService).
*/

return [

    'default_country' => env('FISHDEX_DEFAULT_COUNTRY', 'MY'),

    'supported_locales' => ['en', 'ms'],

    'min_supported_app_version' => env('FISHDEX_MIN_APP_VERSION', '1.0.0'),

    // Hosting mode. On serverless hosts (Vercel) there is no queue worker or scheduler:
    // scans run during the app's status poll and maintenance runs from a daily cron URL.
    'runtime' => [
        'inline_scans' => (bool) env('FISHDEX_INLINE_SCANS', false),
        'cron_secret' => env('CRON_SECRET'),
    ],

    'catalogue' => [
        'default_card_set' => env('FISHDEX_DEFAULT_CARD_SET', 'MY-S1'),
        'cache_ttl' => 3600,
    ],

    'media' => [
        'disk' => env('MEDIA_DISK', 'media'),          // private user photos (R2 in prod)
        'assets_disk' => env('ASSETS_DISK', 'assets'), // public card art (R2 public + CDN)
        'max_bytes' => 8 * 1024 * 1024,
        'min_short_side' => 400,
        'max_long_side' => 4096,
        'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
        'variants' => [
            'ai' => ['long_side' => 1024, 'format' => 'jpg', 'quality' => 85],
            'card' => ['long_side' => 1080, 'format' => 'webp', 'quality' => 82],
            'thumb' => ['long_side' => 320, 'format' => 'webp', 'quality' => 75],
        ],
        // Card art imported with fishdex:import-card-art. The FishCardView art window is ~0.9:1 (w:h).
        'card_art' => [
            'width' => 1152,
            'height' => 1280,
            'crop_bottom' => 0.09, // fraction of height removed first (generator watermark strip)
            'quality' => 88,
        ],
        'signed_url_ttl_minutes' => ['full' => 15, 'thumb' => 360],
        'abandoned_scan_purge_days' => 14,
        'rejected_purge_hours' => 24,
    ],

    'ai' => [
        // free = Gemini unpaid quota (closed beta, D-16). paid = production.
        'tier' => env('AI_TIER', 'free'),
        'provider' => env('AI_PROVIDER', 'gemini'),
        'fallback_provider' => env('AI_FALLBACK_PROVIDER'),

        'providers' => [
            'gemini' => [
                'api_key' => env('GEMINI_API_KEY'),
                'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
                // Copy the exact model ID from AI Studio; IDs change often.
                'model' => env('GEMINI_MODEL', 'gemini-4-hydrogen'),
                // Tried in order when the model above is overloaded (5xx/timeout). Comma-separated; empty to disable.
                'fallback_models' => array_values(array_filter(array_map('trim', explode(',', (string) env('GEMINI_FALLBACK_MODELS', 'gemini-3.8-flash'))))),
                'rerank_model' => env('GEMINI_RERANK_MODEL'),
                'timeout' => (int) env('GEMINI_TIMEOUT', 30),
                'temperature' => 0.2,
                // Thinking models count their reasoning here too; too low cuts the JSON answer off (finishReason=MAX_TOKENS).
                'max_output_tokens' => (int) env('GEMINI_MAX_OUTPUT_TOKENS', 4096),
                // Optional passthrough, e.g. {"thinkingLevel":"low"}. Thinking tokens bill as output.
                'thinking_config' => json_decode((string) env('GEMINI_THINKING_CONFIG', 'null'), true),
            ],
            'fake' => [],
        ],

        // USD per 1M tokens. Free tier is always 0. Update when prices change.
        'prices' => [
            'gemini-3.6-flash' => ['input' => 1.50, 'output' => 7.50],
            'gemini-4-hydrogen' => ['input' => 1.50, 'output' => 7.50],
        ],

        'free_tier' => [
            // Enter the numbers shown on AI Studio's rate-limit page for your project (tunable).
            'rpm' => (int) env('AI_FREE_RPM', 10),
            'rpd' => (int) env('AI_FREE_RPD', 250),
            'usable_ratio' => 0.8,      // 20% reserved for dev/admin testing
            'reset_timezone' => 'America/Los_Angeles', // RPD resets at midnight Pacific
            'max_retries' => 1,
        ],

        'paid' => [
            'rpm' => (int) env('AI_PAID_RPM', 500),
            'daily_budget_usd' => (float) env('AI_DAILY_BUDGET_USD', 20),
            'budget_alert_ratio' => 0.8,
        ],

        'pass2_enabled' => [
            'free' => false,
            'paid' => true,
        ],
        'pass2_shortlist_size' => 25,

        'kill_switch' => false, // tunable

        'dedupe_days' => 30,

        'circuit_breaker' => ['failures' => 5, 'window_seconds' => 60, 'cooldown_seconds' => 60],
    ],

    // Composite confidence (doc 08 §6). Calibrate against the evaluation set.
    'confidence' => [
        'weights' => ['model' => 0.45, 'match' => 0.25, 'margin' => 0.20, 'agreement' => 0.10],
        'penalties' => ['not_in_country' => 0.15, 'not_in_region' => 0.05, 'low_quality' => 0.10],
        'high' => 0.80,
        'high_min_margin' => 0.15,
        'medium' => 0.55,
    ],

    'quotas' => [
        // Scans per user per day (Malaysia day) by plan (tunable).
        'daily' => ['free' => (int) env('FISHDEX_FREE_DAILY_SCANS', 5), 'premium' => 50],
        'per_minute' => 6,
        'new_account_hours' => 24,
        'new_account_daily' => 3,
    ],

    'review' => [
        'random_sample_ratio' => 0.02,
    ],

    'account_deletion_grace_days' => 30,

    'admin' => [
        // Staff must enrol an authenticator app before using the panel (doc 10 §1).
        'require_mfa' => (bool) env('ADMIN_REQUIRE_MFA', true),
    ],

    'tokens' => [
        'expiration_days' => 60,
    ],
];
