<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Update unique constraints on user interaction tables for per-site isolation.
 *
 * Replaces global unique(user_id, story_id) with composite index for per-site lookups.
 * Uniqueness enforced at the SERVICE LAYER (NULL-safe WHERE clauses).
 *
 * Index names verified from actual DB schema via SHOW INDEX.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ─── Bookmarks ──────────────────────────────────────────────────
        // Old unique was already dropped in a previous attempt.
        // Clean up leftover temp indexes and ensure the correct composite exists.
        $this->safeDropIndex('tk_bookmarks', 'idx_bookmarks_user_story');
        // idx_bookmark_user_story_site already exists from previous attempt — keep it.

        // ─── Ratings ────────────────────────────────────────────────────
        DB::statement('ALTER TABLE `tk_ratings` ADD INDEX `idx_rating_user_story_site`(`user_id`, `story_id`, `api_domain_id`)');
        DB::statement('ALTER TABLE `tk_ratings` DROP INDEX `uq_rating_user_story`');

        // ─── Reading History ────────────────────────────────────────────
        DB::statement('ALTER TABLE `tk_reading_history` ADD INDEX `idx_history_user_story_site`(`user_id`, `story_id`, `api_domain_id`)');
        DB::statement('ALTER TABLE `tk_reading_history` DROP INDEX `tk_tk_reading_history_user_id_story_id_unique`');
    }

    public function down(): void
    {
        // ─── Reading History ────────────────────────────────────────────
        DB::statement('ALTER TABLE `tk_reading_history` ADD UNIQUE INDEX `tk_tk_reading_history_user_id_story_id_unique`(`user_id`, `story_id`)');
        $this->safeDropIndex('tk_reading_history', 'idx_history_user_story_site');

        // ─── Ratings ────────────────────────────────────────────────────
        DB::statement('ALTER TABLE `tk_ratings` ADD UNIQUE INDEX `uq_rating_user_story`(`user_id`, `story_id`)');
        $this->safeDropIndex('tk_ratings', 'idx_rating_user_story_site');

        // ─── Bookmarks ──────────────────────────────────────────────────
        DB::statement('ALTER TABLE `tk_bookmarks` ADD UNIQUE INDEX `tk_bookmarks_user_id_story_id_unique`(`user_id`, `story_id`)');
        $this->safeDropIndex('tk_bookmarks', 'idx_bookmark_user_story_site');
    }

    /**
     * Safely drop an index (ignore if not exists).
     */
    private function safeDropIndex(string $table, string $index): void
    {
        try {
            DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$index}`");
        } catch (\Throwable) {
            // Index doesn't exist — skip
        }
    }
};
