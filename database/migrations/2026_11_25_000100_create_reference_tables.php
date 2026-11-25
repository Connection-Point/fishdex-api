<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->smallIncrements('id');
            $table->char('iso2', 2)->unique();
            $table->string('name', 100);
            $table->string('default_locale', 10);
            $table->boolean('is_launched')->default(false);
            $table->smallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('regions', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedSmallInteger('country_id');
            $table->string('code', 10);
            $table->string('name', 100);
            $table->enum('type', ['state', 'federal_territory', 'province']);
            $table->timestamps();

            $table->foreign('country_id')->references('id')->on('countries');
            $table->unique(['country_id', 'code']);
        });

        Schema::create('habitats', function (Blueprint $table) {
            $table->smallIncrements('id');
            $table->string('code', 40)->unique();
            $table->string('name_en', 80);
            $table->string('name_ms', 80);
            $table->timestamps();
        });

        Schema::create('rarity_tiers', function (Blueprint $table) {
            $table->tinyIncrements('id');
            $table->string('code', 20)->unique();
            $table->unsignedTinyInteger('sort_order');
            $table->string('name_en', 40);
            $table->string('name_ms', 40);
            $table->char('color_hex', 7);
            $table->string('foil_effect', 30)->default('none');
            $table->string('reveal_animation', 30)->default('standard');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rarity_tiers');
        Schema::dropIfExists('habitats');
        Schema::dropIfExists('regions');
        Schema::dropIfExists('countries');
    }
};
