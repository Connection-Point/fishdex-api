<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fishing_trips', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120)->nullable();
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $table->enum('water_body_type', ['river', 'lake', 'pond', 'sea', 'estuary', 'other'])->nullable();
            $table->unsignedInteger('region_id')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['user_id', 'client_uuid']);
            $table->foreign('region_id')->references('id')->on('regions')->nullOnDelete();
        });

        Schema::create('fish_encounters', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trip_id')->nullable()->constrained('fishing_trips')->nullOnDelete();
            $table->foreignId('species_id')->nullable()->constrained('fish_species')->nullOnDelete();
            $table->foreignId('identification_request_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('primary_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->enum('status', ['pending_confirmation', 'confirmed', 'unidentified', 'rejected']);
            $table->enum('identification_source', ['ai_confirmed', 'user_selected', 'manual', 'expert_corrected']);
            $table->enum('confidence_band', ['high', 'medium', 'low'])->nullable();
            $table->boolean('is_verified')->default(false);
            $table->enum('decision', ['undecided', 'collect', 'release'])->default('undecided');
            $table->timestamp('decision_at')->nullable();
            $table->boolean('collect_eligible')->default(false);
            $table->enum('encounter_type', ['catch', 'observation'])->default('catch');
            $table->enum('physical_outcome', ['released_alive', 'kept', 'unknown'])->nullable();
            $table->dateTime('caught_at');
            $table->decimal('length_cm', 6, 1)->nullable();
            $table->decimal('weight_kg', 7, 3)->nullable();
            $table->enum('method', ['casting_lure', 'bait', 'fly', 'jigging', 'trolling', 'net', 'handline', 'observation', 'other'])->nullable();
            $table->string('tackle_notes', 255)->nullable();
            $table->text('location_precise')->nullable();
            $table->char('geohash5', 5)->nullable()->index();
            $table->unsignedInteger('region_id')->nullable();
            $table->unsignedSmallInteger('country_id');
            $table->enum('location_visibility', ['private', 'region'])->default('private');
            $table->text('notes')->nullable();
            $table->json('weather')->nullable();
            $table->uuid('client_uuid')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['user_id', 'client_uuid']);
            $table->index(['user_id', 'caught_at']);
            $table->index(['user_id', 'species_id']);
            $table->foreign('region_id')->references('id')->on('regions')->nullOnDelete();
            $table->foreign('country_id')->references('id')->on('countries');
        });

        Schema::create('encounter_media', function (Blueprint $table) {
            $table->foreignId('encounter_id')->constrained('fish_encounters')->cascadeOnDelete();
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->primary(['encounter_id', 'media_id']);
        });

        Schema::create('user_species_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('species_id')->constrained('fish_species')->cascadeOnDelete();
            $table->enum('state', ['seen', 'collected']);
            $table->foreignId('card_id')->nullable()->constrained('fish_cards')->nullOnDelete();
            $table->unsignedTinyInteger('rarity_tier_id_snapshot')->nullable();
            $table->dateTime('first_seen_at');
            $table->dateTime('first_collected_at')->nullable();
            $table->foreignId('first_encounter_id')->nullable()->constrained('fish_encounters')->nullOnDelete();
            $table->foreignId('first_collected_encounter_id')->nullable()->constrained('fish_encounters')->nullOnDelete();
            $table->foreignId('latest_encounter_id')->nullable()->constrained('fish_encounters')->nullOnDelete();
            $table->unsignedInteger('encounter_count')->default(0);
            $table->unsignedInteger('collected_count')->default(0);
            $table->unsignedInteger('released_count')->default(0);
            $table->decimal('best_length_cm', 6, 1)->nullable();
            $table->foreignId('best_length_encounter_id')->nullable()->constrained('fish_encounters')->nullOnDelete();
            $table->decimal('best_weight_kg', 7, 3)->nullable();
            $table->foreignId('best_weight_encounter_id')->nullable()->constrained('fish_encounters')->nullOnDelete();
            $table->foreignId('cover_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->boolean('is_favorite')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'species_id']);
            $table->index(['user_id', 'state']);
            $table->foreign('rarity_tier_id_snapshot')->references('id')->on('rarity_tiers');
        });

        Schema::create('user_stats', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('species_seen')->default(0);
            $table->unsignedInteger('species_collected')->default(0);
            $table->unsignedInteger('encounters_total')->default(0);
            $table->unsignedInteger('encounters_released')->default(0);
            $table->json('by_rarity')->nullable();
            $table->json('by_family')->nullable();
            $table->json('by_habitat')->nullable();
            $table->json('completion_by_set')->nullable();
            $table->dateTime('last_encounter_at')->nullable();
            $table->timestamp('recalculated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['user_stats', 'user_species_collections', 'encounter_media', 'fish_encounters', 'fishing_trips'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
