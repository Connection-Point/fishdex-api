<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taxon_families', function (Blueprint $table) {
            $table->increments('id');
            $table->string('scientific_name', 120)->unique();
            $table->string('order_name', 120)->nullable();
            $table->string('common_name_en', 120)->nullable();
            $table->string('common_name_ms', 120)->nullable();
            $table->timestamps();
        });

        Schema::create('fish_species', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('scientific_name', 160)->unique();
            $table->string('authority', 120)->nullable();
            $table->string('genus', 80)->index();
            $table->unsignedInteger('family_id')->index();
            $table->string('slug', 180)->unique();
            $table->json('water_types');
            $table->decimal('max_length_cm', 6, 1)->nullable();
            $table->decimal('max_weight_kg', 7, 2)->nullable();
            $table->text('description')->nullable();
            $table->text('identification_notes')->nullable();
            $table->json('lookalike_species_ids')->nullable();
            $table->enum('taxonomy_status', ['accepted', 'provisional', 'under_review'])->default('accepted');
            $table->json('external_refs')->nullable();
            $table->boolean('is_published')->default(false)->index();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('family_id')->references('id')->on('taxon_families');
        });

        Schema::create('species_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('species_id')->constrained('fish_species')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->text('description')->nullable();
            $table->text('identification_notes')->nullable();
            $table->string('fun_fact', 500)->nullable();
            $table->timestamps();

            $table->unique(['species_id', 'locale']);
        });

        Schema::create('species_synonyms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('species_id')->constrained('fish_species')->cascadeOnDelete();
            $table->string('scientific_name', 160)->unique();
            $table->string('normalized_name', 160)->index();
            $table->enum('kind', ['synonym', 'misspelling', 'former_name'])->default('synonym');
            $table->string('source', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('species_names', function (Blueprint $table) {
            $table->id();
            $table->foreignId('species_id')->constrained('fish_species')->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('normalized_name', 160)->index();
            $table->string('locale', 10);
            $table->unsignedSmallInteger('country_id')->nullable();
            $table->unsignedInteger('region_id')->nullable();
            $table->enum('name_type', ['common', 'local_alias', 'trade', 'juvenile'])->default('common');
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_ambiguous')->default(false);
            $table->timestamps();

            $table->foreign('country_id')->references('id')->on('countries');
            $table->foreign('region_id')->references('id')->on('regions');
            $table->index(['species_id', 'locale', 'is_primary']);

            if (Schema::getConnection()->getDriverName() === 'mysql') {
                $table->fullText('name');
            }
        });

        Schema::create('species_habitats', function (Blueprint $table) {
            $table->foreignId('species_id')->constrained('fish_species')->cascadeOnDelete();
            $table->unsignedSmallInteger('habitat_id');
            $table->primary(['species_id', 'habitat_id']);
            $table->foreign('habitat_id')->references('id')->on('habitats')->cascadeOnDelete();
        });

        Schema::create('species_country_status', function (Blueprint $table) {
            $table->id();
            $table->foreignId('species_id')->constrained('fish_species')->cascadeOnDelete();
            $table->unsignedSmallInteger('country_id');
            $table->enum('origin', ['native', 'endemic', 'introduced', 'invasive', 'uncertain']);
            $table->boolean('is_present')->default(true);
            $table->text('distribution_notes')->nullable();
            $table->enum('legal_protection', ['none', 'protected', 'restricted', 'sanctuary_only'])->default('none');
            $table->string('legal_notes', 500)->nullable();
            $table->string('source', 255)->nullable();
            $table->timestamps();

            $table->unique(['species_id', 'country_id']);
            $table->foreign('country_id')->references('id')->on('countries');
        });

        Schema::create('species_region_presence', function (Blueprint $table) {
            $table->foreignId('species_id')->constrained('fish_species')->cascadeOnDelete();
            $table->unsignedInteger('region_id');
            $table->enum('confidence', ['confirmed', 'probable'])->default('confirmed');
            $table->string('source', 255)->nullable();
            $table->timestamps();

            $table->primary(['species_id', 'region_id']);
            $table->foreign('region_id')->references('id')->on('regions')->cascadeOnDelete();
        });

        Schema::create('conservation_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('species_id')->constrained('fish_species')->cascadeOnDelete();
            $table->enum('authority', ['iucn', 'national', 'state']);
            $table->unsignedSmallInteger('country_id')->nullable();
            $table->string('status_code', 10);
            $table->smallInteger('assessed_year')->nullable();
            $table->string('source_url', 500)->nullable();
            $table->boolean('is_current')->default(true);
            $table->timestamps();

            $table->foreign('country_id')->references('id')->on('countries');
            $table->index(['species_id', 'is_current']);
        });

        Schema::create('species_rarities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('species_id')->constrained('fish_species')->cascadeOnDelete();
            $table->unsignedSmallInteger('country_id');
            $table->unsignedTinyInteger('rarity_tier_id');
            $table->enum('basis', ['editorial', 'computed', 'event'])->default('editorial');
            $table->string('rationale', 500)->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->foreignId('set_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            $table->foreign('country_id')->references('id')->on('countries');
            $table->foreign('rarity_tier_id')->references('id')->on('rarity_tiers');
            $table->index(['species_id', 'country_id', 'effective_to']);
        });

        Schema::create('reference_images', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('species_id')->constrained('fish_species')->cascadeOnDelete();
            $table->enum('kind', ['reference_photo', 'card_art', 'silhouette', 'diagram']);
            $table->string('disk', 30)->default('r2_public');
            $table->string('storage_key', 255);
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->string('blurhash', 40)->nullable();
            $table->string('license', 50);
            $table->string('attribution', 255)->nullable();
            $table->string('source_url', 500)->nullable();
            $table->boolean('is_verified')->default(false);
            $table->foreignId('verified_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->smallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('card_sets', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('code', 30)->unique();
            $table->string('name', 120);
            $table->unsignedSmallInteger('country_id')->nullable();
            $table->string('theme', 60)->nullable();
            $table->date('released_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('total_cards')->default(0);
            $table->timestamps();

            $table->foreign('country_id')->references('id')->on('countries');
        });

        Schema::create('fish_cards', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('card_set_id')->constrained()->cascadeOnDelete();
            $table->foreignId('species_id')->constrained('fish_species')->cascadeOnDelete();
            $table->string('card_number', 10);
            $table->enum('variant', ['standard', 'holo', 'alt_art', 'event'])->default('standard');
            $table->foreignId('artwork_image_id')->nullable()->constrained('reference_images')->nullOnDelete();
            $table->string('flavor_text_key', 100)->nullable();
            $table->json('display_attributes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['card_set_id', 'card_number', 'variant']);
            $table->unique(['card_set_id', 'species_id', 'variant']);
        });
    }

    public function down(): void
    {
        foreach ([
            'fish_cards', 'card_sets', 'reference_images', 'species_rarities', 'conservation_assessments',
            'species_region_presence', 'species_country_status', 'species_habitats', 'species_names',
            'species_synonyms', 'species_translations', 'fish_species', 'taxon_families',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
