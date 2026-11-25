<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('handle', 30)->unique();
            $table->string('display_name', 60);
            $table->string('email', 191)->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->unsignedBigInteger('avatar_media_id')->nullable();
            $table->unsignedSmallInteger('country_id');
            $table->unsignedInteger('home_region_id')->nullable();
            $table->string('locale', 10)->default('en');
            $table->string('timezone', 40)->default('Asia/Kuala_Lumpur');
            $table->enum('units', ['metric', 'imperial'])->default('metric');
            $table->enum('default_location_visibility', ['private', 'region'])->default('private');
            $table->string('plan', 20)->default('free');
            $table->enum('status', ['active', 'suspended', 'pending_deletion'])->default('active');
            $table->boolean('beta_ai_consent')->default(false);
            $table->timestamp('beta_ai_consent_at')->nullable();
            $table->timestamp('last_active_at')->nullable();
            $table->timestamp('deletion_requested_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('country_id')->references('id')->on('countries');
            $table->foreign('home_region_id')->references('id')->on('regions')->nullOnDelete();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('provider', ['google', 'apple']);
            $table->string('provider_user_id', 191);
            $table->string('email', 191)->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_user_id']);
        });

        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('device_uuid', 64);
            $table->enum('platform', ['android', 'ios']);
            $table->string('fcm_token', 255)->nullable();
            $table->string('app_version', 20)->nullable();
            $table->string('os_version', 20)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'device_uuid']);
        });

        Schema::create('admins', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('email', 191)->unique();
            $table->string('password');
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->string('role', 30)->default('support');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admins');
        Schema::dropIfExists('devices');
        Schema::dropIfExists('social_accounts');
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
