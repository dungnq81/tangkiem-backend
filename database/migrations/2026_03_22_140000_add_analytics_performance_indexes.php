<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add composite indexes for analytics aggregation performance.
 *
 * Key optimization: The aggregation job queries page_visits by date range
 * + api_domain_id + is_bot. A composite index covering these columns
 * allows MySQL to do efficient index range scans instead of full table scans.
 *
 * Also adds a covering index for the session_hash-based
 * "returning visitors" subquery.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Primary aggregation index: visited_at range + site + bot filter
        // Covers: calculateStats base query, getActiveVisitors, getTodayViews fallback
        $this->safeAddIndex(
            'tk_page_visits',
            'idx_visits_agg',
            '(`visited_at`, `api_domain_id`, `is_bot`)'
        );

        // Session hash lookup with date for returning visitors subquery
        // Replaces idx_visits_session with a broader coverage
        $this->safeDropIndex('tk_page_visits', 'idx_visits_session');
        $this->safeAddIndex(
            'tk_page_visits',
            'idx_visits_session_date',
            '(`session_hash`, `visited_at`, `is_bot`)'
        );
    }

    public function down(): void
    {
        $this->safeDropIndex('tk_page_visits', 'idx_visits_agg');

        $this->safeDropIndex('tk_page_visits', 'idx_visits_session_date');
        $this->safeAddIndex(
            'tk_page_visits',
            'idx_visits_session',
            '(`session_hash`, `visited_at`)'
        );
    }

    private function safeAddIndex(string $table, string $name, string $columns): void
    {
        try {
            DB::statement("ALTER TABLE `{$table}` ADD INDEX `{$name}` {$columns}");
        } catch (\Throwable) {
            // Index already exists — skip
        }
    }

    private function safeDropIndex(string $table, string $name): void
    {
        try {
            DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$name}`");
        } catch (\Throwable) {
            // Index doesn't exist — skip
        }
    }
};
