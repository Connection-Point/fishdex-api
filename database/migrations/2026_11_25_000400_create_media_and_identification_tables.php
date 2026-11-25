<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('purpose', ['scan', 'encounter', 'avatar']);
            $table->string('disk', 30);
            $table->string('storage_key', 255);
            $table->json('variants')->nullable();
            $table->string('mime', 50);
            $table->unsignedInteger('bytes');
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->char('sha256', 64)->index();
            $table->bigInteger('phash')->nullable()->index(); // signed: PHP ints are signed 64-bit
            $table->dateTime('exif_taken_at')->nullable();
            $table->boolean('exif_has_gps')->default(false);
            $table->enum('status', ['uploaded', 'processing', 'ready', 'rejected', 'purged'])->default('uploaded');
            $table->string('reject_reason', 50)->nullable();
            $table->timestamp('purge_after')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('identification_requests', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_id')->constrained('media');
            $table->uuid('client_uuid')->nullable();
            $table->unsignedSmallInteger('country_id');
            $table->unsignedInteger('region_id')->nullable();
            $table->enum('encounter_type', ['catch', 'observation'])->default('catch');
            $table->unsignedBigInteger('trip_id')->nullable();
            $table->dateTime('captured_at');
            $table->text('location_precise')->nullable();
            $table->char('geohash5', 5)->nullable();
            $table->enum('status', ['queued', 'waiting_capacity', 'processing', 'completed', 'failed', 'timed_out', 'rejected'])->default('queued')->index();
            $table->enum('outcome', ['identified', 'no_fish', 'multiple_fish', 'unsupported_image', 'not_in_catalogue'])->nullable();
            $table->enum('confidence_band', ['high', 'medium', 'low'])->nullable();
            $table->foreignId('top_species_id')->nullable()->constrained('fish_species')->nullOnDelete();
            $table->decimal('top_confidence', 5, 4)->nullable();
            $table->json('flags')->nullable();
            $table->unsignedBigInteger('total_cost_usd_micros')->default(0);
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->string('ai_tier', 10)->default('free');
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('expected_after')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('error_code', 50)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'client_uuid']);
            $table->index(['user_id', 'created_at']);
            $table->foreign('country_id')->references('id')->on('countries');
            $table->foreign('region_id')->references('id')->on('regions')->nullOnDelete();
        });

        Schema::create('identification_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('identification_request_id')->constrained()->cascadeOnDelete();
            $table->enum('pass', ['open', 'constrained']);
            $table->string('provider', 30);
            $table->string('model', 60);
            $table->string('ai_tier', 10);
            $table->string('prompt_version', 20);
            $table->enum('status', ['success', 'error', 'timeout', 'invalid_output', 'rate_limited']);
            $table->smallInteger('http_status')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('cost_usd_micros')->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->json('response_json')->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamps();

            $table->index(['provider', 'created_at']);
        });

        Schema::create('identification_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('identification_request_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rank');
            $table->string('raw_label', 160)->nullable();
            $table->string('raw_scientific_name', 160)->nullable();
            $table->decimal('model_confidence', 5, 4)->nullable();
            $table->foreignId('matched_species_id')->nullable()->constrained('fish_species')->nullOnDelete();
            $table->enum('match_method', ['scientific_exact', 'synonym', 'common_name', 'fuzzy', 'constrained_pick', 'none']);
            $table->decimal('match_score', 5, 4)->default(0);
            $table->boolean('geo_plausible')->nullable();
            $table->decimal('final_confidence', 5, 4)->default(0);
            $table->json('visible_features')->nullable();
            $table->timestamps();

            $table->index(['identification_request_id', 'rank']);
        });

        Schema::create('ai_usage_daily', function (Blueprint $table) {
            $table->date('date');
            $table->string('provider', 30);
            $table->string('model', 60);
            $table->unsignedInteger('requests')->default(0);
            $table->unsignedInteger('successes')->default(0);
            $table->unsignedInteger('failures')->default(0);
            $table->unsignedInteger('rate_limited')->default(0);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('cost_usd_micros')->default(0);
            $table->unsignedInteger('p50_latency_ms')->nullable();
            $table->unsignedInteger('p95_latency_ms')->nullable();
            $table->timestamps();

            $table->primary(['date', 'provider', 'model']);
        });

        Schema::create('user_scan_usage', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('period_date');
            $table->unsignedInteger('scans_count')->default(0);
            $table->timestamps();

            $table->primary(['user_id', 'period_date']);
        });
    }

    public function down(): void
    {
        foreach (['user_scan_usage', 'ai_usage_daily', 'identification_candidates', 'identification_attempts', 'identification_requests', 'media'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
