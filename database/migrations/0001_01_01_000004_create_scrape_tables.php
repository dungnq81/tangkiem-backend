<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Consolidated scraping system tables.
     *
     * Merged from all incremental migrations through 2026-03-07:
     * - Scrape Sources (+ cleanup_after_days, max_concurrency)
     * - Scrape Jobs (+ auto_import, detail_config/status, schedule_day_of_month)
     * - Scrape Items (+ generated columns, performance indexes, compression)
     * - Scrape tracking columns on content tables
     */

    private array $contentTables = ['categories', 'authors', 'stories', 'chapters'];

    public function up(): void
    {
        // ─── Scrape Sources ──────────────────────────────────────────────
        Schema::create('scrape_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('base_url', 500);
            $table->string('render_type', 10)->default('ssr')->comment('ssr | spa');

            // AI extraction settings
            $table->string('extraction_method', 20)->default('ai_prompt')
                ->comment('css_selector | ai_prompt');
            $table->string('ai_provider', 20)->nullable()->comment('gemini | groq');
            $table->string('ai_model', 100)->nullable()->comment('e.g. gemini-2.0-flash');
            $table->text('ai_prompt_template')->nullable()
                ->comment('Default prompt template for AI extraction');

            $table->json('default_headers')->nullable()->comment('Custom HTTP headers (User-Agent, Cookie...)');
            $table->unsignedInteger('delay_ms')->default(2000)->comment('Delay between requests in ms');
            $table->unsignedTinyInteger('max_concurrency')->default(3);
            $table->unsignedTinyInteger('max_retries')->default(3);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('cleanup_after_days')->default(0)
                  ->comment('Auto-delete scrape items after N days. 0 = never.');
            $table->text('notes')->nullable();
            $table->json('clean_patterns')->nullable()
                ->comment('Per-source content cleaning patterns [{pattern, type}]');
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active', 'idx_scrape_sources_active');
        });

        // ─── Scrape Jobs ─────────────────────────────────────────────────
        Schema::create('scrape_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('scrape_sources')->cascadeOnDelete();
            $table->string('entity_type', 30)->comment('category | author | story | chapter');
            $table->string('name');
            $table->string('target_url', 1000);
            $table->json('selectors')->nullable()->comment('CSS selectors config');
            $table->text('ai_prompt')->nullable()->comment('Override AI prompt for this specific job');
            $table->json('pagination')->nullable()->comment('Pagination config');
            $table->json('detail_config')->nullable()
                  ->comment('Config for chapter detail page scraping: {content_selector, remove_selectors, ai_prompt, ...}');
            $table->json('import_defaults')->nullable()
                ->comment('Default values for imported entities (type, origin, status, author_id, etc.)');
            $table->foreignId('parent_story_id')->nullable()
                  ->constrained('stories')->nullOnDelete()
                  ->comment('Only for entity_type=chapter');
            $table->string('status', 20)->default('draft')
                  ->comment('draft|scraping|scraped|importing|done|failed');
            $table->string('detail_status', 20)->nullable()
                  ->comment('Phase 2 status: null|fetching|fetched|failed');
            $table->unsignedInteger('total_pages')->default(0);
            $table->unsignedInteger('current_page')->default(0);
            $table->unsignedInteger('detail_fetched')->default(0)
                  ->comment('Number of items with detail content fetched');
            $table->unsignedInteger('detail_total')->default(0)
                  ->comment('Total items needing detail fetch');
            $table->text('error_log')->nullable();

            // Schedule
            $table->boolean('is_scheduled')->default(false)->comment('Enable/disable auto scheduling');
            $table->boolean('auto_import')->default(false)
                  ->comment('Auto-select and import draft items after scheduled scraping');
            $table->string('schedule_frequency', 30)->nullable()
                ->comment('every_30_min|hourly|every_2_hours|...|daily|weekly');
            $table->string('schedule_time', 5)->nullable()->comment('HH:MM for daily/weekly');
            $table->tinyInteger('schedule_day_of_week')->nullable()
                ->comment('0=Sun, 1=Mon...6=Sat – for weekly');
            $table->unsignedTinyInteger('schedule_day_of_month')->nullable();
            $table->timestamp('last_scheduled_at')->nullable()->comment('Last auto-triggered timestamp');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['source_id', 'entity_type'], 'idx_scrape_jobs_source_type');
            $table->index('status', 'idx_scrape_jobs_status');
            $table->index('parent_story_id', 'idx_scrape_jobs_parent');
            $table->index('is_scheduled', 'idx_scrape_jobs_scheduled');
        });

        // ─── Scrape Items (with generated columns + compression) ────────
        Schema::create('scrape_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('scrape_jobs')->cascadeOnDelete();
            $table->json('raw_data')->comment('Scraped raw data');
            $table->string('source_url', 1000);
            $table->string('source_hash', 64)->comment('SHA256 of source_url for dedup');
            $table->string('status', 20)->default('draft')
                  ->comment('draft|selected|imported|skipped|error');
            $table->text('error_message')->nullable();
            $table->unsignedInteger('page_number')->default(1);
            $table->unsignedInteger('sort_order')->default(0);

            // Generated columns for performant JSON-based filtering
            $table->boolean('has_content')
                ->storedAs("IF(
                    JSON_EXTRACT(raw_data, '$.content') IS NOT NULL
                    AND JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.content')) != ''
                    AND JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.content')) != 'null',
                    1, 0
                )");

            $table->boolean('has_error')
                ->storedAs("IF(
                    JSON_EXTRACT(raw_data, '$._detail_error') IS NOT NULL
                    AND JSON_TYPE(JSON_EXTRACT(raw_data, '$._detail_error')) != 'NULL',
                    1, 0
                )");

            $table->string('error_type', 20)
                ->nullable()
                ->storedAs("NULLIF(JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$._error_type')), 'null')");

            $table->unsignedTinyInteger('retry_count')
                ->storedAs("IFNULL(
                    CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$._retry_count')), 'null') AS UNSIGNED),
                    0
                )");

            $table->timestamps();

            // Unique + standard indexes
            $table->unique(['job_id', 'source_hash'], 'uq_scrape_items_job_hash');
            $table->index(['job_id', 'status'], 'idx_scrape_items_job_status');
            $table->index('created_at', 'idx_scrape_items_created');

            // Composite indexes for hot query paths
            $table->index(['job_id', 'status', 'has_content'], 'idx_scrape_items_detail_fetch');
            $table->index(['job_id', 'has_error', 'error_type', 'retry_count'], 'idx_scrape_items_retry');
        });

        // Set ROW_FORMAT to COMPRESSED for scrape_items
        $prefix = DB::getTablePrefix();
        DB::statement(
            "ALTER TABLE {$prefix}scrape_items ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=8"
        );

        // ─── Add scrape tracking columns to content tables ───────────────
        foreach ($this->contentTables as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->foreignId('scrape_source_id')->nullable()
                          ->constrained('scrape_sources')->nullOnDelete()
                          ->comment('NULL = manually created');
                $blueprint->string('scrape_url', 1000)->nullable()
                          ->comment('Original URL from source website');
                $blueprint->string('scrape_hash', 64)->nullable()
                          ->comment('SHA256(scrape_url) for dedup');
                $blueprint->index('scrape_hash', "idx_{$table}_scrape_hash");
            });
        }
    }

    public function down(): void
    {
        // Remove scrape columns from content tables
        foreach ($this->contentTables as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropIndex("idx_{$table}_scrape_hash");
                $blueprint->dropConstrainedForeignId('scrape_source_id');
                $blueprint->dropColumn(['scrape_url', 'scrape_hash']);
            });
        }

        Schema::dropIfExists('scrape_items');
        Schema::dropIfExists('scrape_jobs');
        Schema::dropIfExists('scrape_sources');
    }
};
