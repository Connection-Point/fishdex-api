<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_items', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('encounter_id')->nullable()->constrained('fish_encounters')->cascadeOnDelete();
            $table->foreignId('identification_request_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('reason', ['low_confidence_user_pick', 'random_sample', 'duplicate_image', 'possible_screen_photo', 'user_dispute', 'report']);
            $table->enum('status', ['open', 'confirmed', 'corrected', 'rejected'])->default('open')->index();
            $table->foreignId('resolved_species_id')->nullable()->constrained('fish_species')->nullOnDelete();
            $table->foreignId('assigned_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->text('user_comment')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->json('value');
            $table->foreignId('updated_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('admin_activity_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('action', 60);
            $table->string('subject_type', 120)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('changes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_activity_log');
        Schema::dropIfExists('app_settings');
        Schema::dropIfExists('review_items');
    }
};
