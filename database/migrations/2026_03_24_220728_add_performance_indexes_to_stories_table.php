<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add performance indexes for Stories admin panel.
     *
     * - idx_stories_deleted_at: speeds up onlyTrashed()->exists() in header action (27ms → <1ms)
     * - idx_stories_default_sort: covers default sort + soft delete in table listing
     * - idx_chapters_draft_count: covers withCount for draft chapters correlated subquery
     */
    public function up(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            $table->index('deleted_at', 'idx_stories_deleted_at');
            $table->index(['deleted_at', 'created_at'], 'idx_stories_default_sort');
        });

        // Composite index for draft_chapters_count subquery:
        // WHERE story_id = ? AND is_published = 0 AND deleted_at IS NULL
        Schema::table('chapters', function (Blueprint $table) {
            $table->index(
                ['story_id', 'is_published', 'deleted_at'],
                'idx_chapters_draft_count'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            $table->dropIndex('idx_stories_deleted_at');
            $table->dropIndex('idx_stories_default_sort');
        });

        Schema::table('chapters', function (Blueprint $table) {
            $table->dropIndex('idx_chapters_draft_count');
        });
    }
};
