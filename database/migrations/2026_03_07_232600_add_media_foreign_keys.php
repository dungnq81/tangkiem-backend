<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add foreign key constraints for media (Curator) columns.
     *
     * Previously these were just unsignedBigInteger with no FK,
     * meaning if media was deleted, orphan IDs would remain
     * and cause broken images on the frontend.
     */
    public function up(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            $table->foreign('cover_image_id', 'fk_stories_cover_image')
                ->references('id')
                ->on('curator')
                ->nullOnDelete();

            $table->foreign('thumbnail_id', 'fk_stories_thumbnail')
                ->references('id')
                ->on('curator')
                ->nullOnDelete();

            $table->foreign('banner_id', 'fk_stories_banner')
                ->references('id')
                ->on('curator')
                ->nullOnDelete();
        });

        Schema::table('authors', function (Blueprint $table) {
            $table->foreign('avatar_id', 'fk_authors_avatar')
                ->references('id')
                ->on('curator')
                ->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('avatar_id', 'fk_users_avatar')
                ->references('id')
                ->on('curator')
                ->nullOnDelete();
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->foreign('image_id', 'fk_categories_image')
                ->references('id')
                ->on('curator')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stories', function (Blueprint $table) {
            $table->dropForeign('fk_stories_cover_image');
            $table->dropForeign('fk_stories_thumbnail');
            $table->dropForeign('fk_stories_banner');
        });

        Schema::table('authors', function (Blueprint $table) {
            $table->dropForeign('fk_authors_avatar');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign('fk_users_avatar');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropForeign('fk_categories_image');
        });
    }
};
