<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Consolidated system tables migration.
     *
     * Merged from all incremental migrations through 2026-03-07:
     * - Activity Logs, Settings, Curator (+ indexes),
     *   API Domains, Bookmarks, Reading History (chapter_id SET NULL),
     *   Web Cron Logs.
     */
    public function up(): void
    {
        // ─── Activity Logs ───────────────────────────────────────────────
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();

            $table->string('log_name', 50)->default('default');
            $table->text('description');

            // Actor
            $table->string('causer_type', 100)->nullable()->comment('App\\Models\\User');
            $table->unsignedBigInteger('causer_id')->nullable();

            // Subject
            $table->string('subject_type', 100)->nullable()->comment('App\\Models\\Story');
            $table->unsignedBigInteger('subject_id')->nullable();

            // Event details
            $table->string('event', 50)->nullable()->comment('created, updated, deleted');
            $table->json('properties')->nullable()->comment('{old: {...}, attributes: {...}}');

            $table->char('batch_uuid', 36)->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Indexes
            $table->index(['causer_type', 'causer_id'], 'idx_activity_causer');
            $table->index(['subject_type', 'subject_id'], 'idx_activity_subject');
            $table->index(['log_name', 'created_at'], 'idx_activity_log');
            $table->index('batch_uuid', 'idx_activity_batch');
        });

        // ─── Settings ────────────────────────────────────────────────────
        Schema::create('settings', function (Blueprint $table) {
            $table->id();

            $table->string('group', 50)->comment('general, reading, scraping, seo');
            $table->string('key', 100);
            $table->json('value');
            $table->enum('type', ['string', 'int', 'float', 'bool', 'array', 'json'])->default('string');
            $table->string('label', 255)->nullable()->comment('UI label');
            $table->text('description')->nullable();
            $table->boolean('is_public')->default(false)->comment('Expose to frontend');

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['group', 'key'], 'uq_settings_key');
        });

        // ─── Curator ─────────────────────────────────────────────────────
        Schema::create('curator', function (Blueprint $table) {
            $table->id();

            $table->string('disk');
            $table->string('directory')->nullable();
            $table->string('visibility')->default('public');
            $table->string('name');
            $table->string('path')->index();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('size')->nullable();
            $table->string('type');
            $table->string('ext');
            $table->string('alt')->nullable();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->text('caption')->nullable();
            $table->text('pretty_name')->nullable();
            $table->text('exif')->nullable();
            $table->longText('curations')->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable();

            $table->timestamps();

            // Browsing indexes
            $table->index('type', 'idx_curator_type');
            $table->index(['disk', 'directory'], 'idx_curator_disk_dir');
        });

        // ─── API Domains ─────────────────────────────────────────────────
        Schema::create('api_domains', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('domain')->unique();
            $table->string('public_key', 64)->unique();
            $table->string('secret_key', 64)->unique();
            $table->json('allowed_groups')->nullable();
            $table->dateTime('valid_from')->nullable();
            $table->dateTime('valid_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'valid_until']);
        });

        // ─── Bookmarks ──────────────────────────────────────────────────
        Schema::create('bookmarks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('story_id')->constrained('stories')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'story_id']);
            $table->index('story_id');
        });

        // ─── Reading History ─────────────────────────────────────────────
        Schema::create('reading_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('story_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chapter_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('progress')->default(0)->comment('Reading progress percentage 0-100');
            $table->timestamp('read_at')->useCurrent();
            $table->timestamps();

            $table->unique(['user_id', 'story_id']);
            $table->index(['user_id', 'read_at']);
            $table->index('story_id');
        });

        // ─── Web Cron Logs ───────────────────────────────────────────────
        Schema::create('web_cron_logs', function (Blueprint $table) {
            $table->id();

            // Execution timestamps
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable()
                ->comment('Execution duration in milliseconds');

            // Status: success, partial, failed, running
            $table->string('status', 20)->default('running')
                ->index();

            // What triggered this run
            $table->string('trigger', 20)->default('heartbeat')
                ->comment('heartbeat, manual, server_cron');

            // Task results (JSON)
            $table->json('tasks_summary')->nullable()
                ->comment('Array of {task, status, duration_ms, output, error}');

            // Resource usage
            $table->unsignedSmallInteger('memory_peak_mb')->nullable()
                ->comment('Peak memory usage in MB');

            // Error message for completely failed runs
            $table->text('error')->nullable();

            // Indexes for efficient querying
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_cron_logs');
        Schema::dropIfExists('reading_history');
        Schema::dropIfExists('bookmarks');
        Schema::dropIfExists('api_domains');
        Schema::dropIfExists('curator');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('activity_logs');
    }
};
