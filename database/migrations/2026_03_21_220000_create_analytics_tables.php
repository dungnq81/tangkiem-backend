<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Analytics module tables.
     *
     * page_visits:     Raw visit events (hot table, 30-day retention)
     * daily_analytics: Pre-aggregated daily stats (permanent)
     */
    public function up(): void
    {
        // ─── Page Visits (raw event log) ────────────────────────────────
        Schema::create('page_visits', function (Blueprint $table) {
            $table->id();

            // When
            $table->timestamp('visited_at')->useCurrent();

            // What
            $table->string('page_type', 20)
                ->comment('story, chapter, category, search, ranking, author');
            $table->unsignedBigInteger('page_id')->nullable()
                ->comment('story_id, chapter_id, category_id...');
            $table->string('page_slug', 255)->nullable()
                ->comment('URL slug for reference');

            // Who (privacy-first, no PII stored)
            $table->char('session_hash', 16)
                ->comment('xxh3(ip + user_agent) — unique visitor identifier');
            $table->char('ip_hash', 16)
                ->comment('xxh3(ip + daily_salt) — privacy-safe IP');
            $table->unsignedBigInteger('user_id')->nullable()
                ->comment('Logged-in user, null for guests');

            // Where from
            $table->string('referrer_domain', 100)->nullable();
            $table->string('referrer_type', 20)->default('direct')
                ->comment('direct, search, social, external');
            $table->string('utm_source', 50)->nullable();
            $table->string('utm_medium', 50)->nullable();

            // Device info
            $table->string('device_type', 10)->default('desktop')
                ->comment('desktop, mobile, tablet');
            $table->string('browser', 30)->nullable();
            $table->string('os', 30)->nullable();

            // Geolocation
            $table->char('country_code', 2)->nullable()
                ->comment('ISO 3166-1 alpha-2, from MaxMind GeoLite2');

            // Flags
            $table->boolean('is_bot')->default(false);

            // Indexes for efficient querying and cleanup
            $table->index('visited_at', 'idx_visits_time');
            $table->index(['page_type', 'page_id', 'visited_at'], 'idx_visits_page');
            $table->index(['session_hash', 'visited_at'], 'idx_visits_session');
            $table->index('referrer_type', 'idx_visits_referrer');
            $table->index('device_type', 'idx_visits_device');
        });

        // ─── Daily Analytics (aggregated, permanent) ────────────────────
        Schema::create('daily_analytics', function (Blueprint $table) {
            $table->id();

            // Dimensions
            $table->date('date');
            $table->string('page_type', 20)->nullable()
                ->comment('null = site-wide aggregate');
            $table->unsignedBigInteger('page_id')->nullable()
                ->comment('null = all pages of type');

            // Core Metrics
            $table->unsignedInteger('total_views')->default(0);
            $table->unsignedInteger('unique_visitors')->default(0);
            $table->unsignedInteger('new_visitors')->default(0);
            $table->unsignedInteger('returning_visitors')->default(0);

            // Device Breakdown
            $table->unsignedInteger('desktop_views')->default(0);
            $table->unsignedInteger('mobile_views')->default(0);
            $table->unsignedInteger('tablet_views')->default(0);

            // Quality Metrics
            $table->unsignedInteger('bot_views')->default(0);
            $table->decimal('bounce_rate', 5, 2)->default(0.00)
                ->comment('Percentage of single-page sessions');
            $table->decimal('avg_pages_per_session', 5, 2)->default(0.00);

            // Flexible Breakdowns (JSON for extensibility)
            $table->json('referrer_breakdown')->nullable()
                ->comment('[{domain, type, count}] — top referrers');
            $table->json('browser_breakdown')->nullable()
                ->comment('[{name, count}] — browser distribution');
            $table->json('os_breakdown')->nullable()
                ->comment('[{name, count}] — OS distribution');
            $table->json('country_breakdown')->nullable()
                ->comment('[{code, count}] — country distribution');
            $table->json('hourly_views')->nullable()
                ->comment('[h0, h1, ..., h23] — 24 hourly counts');

            $table->timestamps();

            // Unique constraint: one row per date + page_type + page_id
            $table->unique(['date', 'page_type', 'page_id'], 'uq_daily_analytics');
            $table->index('date', 'idx_daily_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_analytics');
        Schema::dropIfExists('page_visits');
    }
};
