<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Consolidated base tables migration.
     * Merged from original migrations:
     * - 0001_01_01_000000_create_users_table
     * - 0001_01_01_000001_create_cache_table
     * - 0001_01_01_000002_create_jobs_table
     * - 2026_01_15_225656_add_extra_columns_to_users_table
     * - 2026_01_17_170000_add_activity_and_ban_fields_to_users_table
     * - 2026_02_05_214948_add_avatar_id_to_users_table
     * - 2026_02_14_150734_create_personal_access_tokens_table
     */
    public function up(): void
    {
        // ─── Users ───────────────────────────────────────────────────────
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->unsignedBigInteger('avatar_id')->nullable()->comment('FK to media table - Curator avatar');
            $table->string('avatar_url')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');

            // Flags
            $table->boolean('is_active')->default(true);
            $table->boolean('is_vip')->default(false);
            $table->boolean('is_author')->default(false);

            // Activity tracking
            $table->timestamp('last_active_at')->nullable()
                ->comment('Last activity timestamp, updated every 5 minutes');

            // Ban system
            $table->boolean('is_banned')->default(false)->comment('Permanent ban flag');
            $table->timestamp('banned_until')->nullable()
                ->comment('Temporary ban expiry, null = permanent or not banned');
            $table->string('ban_reason', 500)->nullable()
                ->comment('Reason for ban, shown to user');

            $table->rememberToken();
            $table->timestamps();

            // Indexes
            $table->index('last_active_at', 'idx_users_last_active');
            $table->index('is_banned', 'idx_users_banned');
        });

        // ─── Password Reset Tokens ───────────────────────────────────────
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        // ─── Sessions ────────────────────────────────────────────────────
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        // ─── Cache ───────────────────────────────────────────────────────
        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });

        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });

        // ─── Jobs ────────────────────────────────────────────────────────
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('job_batches', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });

        // ─── Personal Access Tokens (Sanctum) ───────────────────────────
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
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('cache_locks');
        Schema::dropIfExists('cache');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
