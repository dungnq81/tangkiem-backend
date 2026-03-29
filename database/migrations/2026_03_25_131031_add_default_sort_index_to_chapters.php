<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add covering indexes for chapters listing queries.
 *
 * Problem: All existing chapter indexes start with story_id.
 * When the Filament chapters page loads WITHOUT a story filter
 * (default state), queries like:
 *   SELECT COUNT(*) FROM chapters WHERE deleted_at IS NULL
 *   SELECT * FROM chapters ORDER BY sort_key LIMIT 50
 * fall back to full table scans (23K+ rows).
 *
 * These indexes enable index-only scans for:
 * 1. Unfiltered COUNT(*) with soft delete (deleted_at, id)
 * 2. Unfiltered default sort pagination (deleted_at, sort_key)
 * 3. Navigation badge count (deleted_at covering)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chapters', function (Blueprint $table) {
            // Covers: SELECT COUNT(*) FROM chapters WHERE deleted_at IS NULL
            // and: SELECT ... ORDER BY sort_key LIMIT 50 WHERE deleted_at IS NULL
            $table->index(['deleted_at', 'sort_key'], 'idx_chapters_default_sort');

            // Covers: SELECT COUNT(*) FROM chapters WHERE deleted_at IS NULL
            // Used by navigation badge COUNT(*)
            $table->index(['deleted_at', 'created_at'], 'idx_chapters_deleted_created');
        });
    }

    public function down(): void
    {
        Schema::table('chapters', function (Blueprint $table) {
            $table->dropIndex('idx_chapters_default_sort');
            $table->dropIndex('idx_chapters_deleted_created');
        });
    }
};
