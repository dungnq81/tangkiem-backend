<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add api_domain_id (site discriminator) to analytics + user interaction tables.
 *
 * This enables per-site analytics filtering:
 * - NULL = legacy/global data (backward compatible)
 * - integer = specific FE site (ApiDomain ID)
 *
 * Pattern: Discriminator column — standard multi-tenant approach.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ─── Analytics tables ───────────────────────────────────────────
        Schema::table('page_visits', function (Blueprint $table) {
            $table->foreignId('api_domain_id')
                ->nullable()
                ->after('is_bot')
                ->constrained('api_domains')
                ->nullOnDelete();

            $table->index('api_domain_id', 'idx_visits_site');
        });

        Schema::table('daily_analytics', function (Blueprint $table) {
            $table->foreignId('api_domain_id')
                ->nullable()
                ->after('page_id')
                ->constrained('api_domains')
                ->nullOnDelete();

            // Drop old unique and recreate with api_domain_id
            $table->dropUnique('uq_daily_analytics');
            $table->unique(
                ['date', 'page_type', 'page_id', 'api_domain_id'],
                'uq_daily_analytics'
            );
        });

        // ─── User interaction tables ────────────────────────────────────
        Schema::table('bookmarks', function (Blueprint $table) {
            $table->foreignId('api_domain_id')
                ->nullable()
                ->after('story_id')
                ->constrained('api_domains')
                ->nullOnDelete();

            $table->index('api_domain_id', 'idx_bookmarks_site');
        });

        Schema::table('ratings', function (Blueprint $table) {
            $table->foreignId('api_domain_id')
                ->nullable()
                ->after('story_id')
                ->constrained('api_domains')
                ->nullOnDelete();

            $table->index('api_domain_id', 'idx_ratings_site');
        });

        Schema::table('reading_history', function (Blueprint $table) {
            $table->foreignId('api_domain_id')
                ->nullable()
                ->after('chapter_id')
                ->constrained('api_domains')
                ->nullOnDelete();

            $table->index('api_domain_id', 'idx_history_site');
        });
    }

    public function down(): void
    {
        Schema::table('reading_history', function (Blueprint $table) {
            $table->dropForeign(['api_domain_id']);
            $table->dropIndex('idx_history_site');
            $table->dropColumn('api_domain_id');
        });

        Schema::table('ratings', function (Blueprint $table) {
            $table->dropForeign(['api_domain_id']);
            $table->dropIndex('idx_ratings_site');
            $table->dropColumn('api_domain_id');
        });

        Schema::table('bookmarks', function (Blueprint $table) {
            $table->dropForeign(['api_domain_id']);
            $table->dropIndex('idx_bookmarks_site');
            $table->dropColumn('api_domain_id');
        });

        Schema::table('daily_analytics', function (Blueprint $table) {
            $table->dropUnique('uq_daily_analytics');
            $table->unique(['date', 'page_type', 'page_id'], 'uq_daily_analytics');

            $table->dropForeign(['api_domain_id']);
            $table->dropColumn('api_domain_id');
        });

        Schema::table('page_visits', function (Blueprint $table) {
            $table->dropForeign(['api_domain_id']);
            $table->dropIndex('idx_visits_site');
            $table->dropColumn('api_domain_id');
        });
    }
};
