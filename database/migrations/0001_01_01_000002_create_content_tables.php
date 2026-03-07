<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Consolidated content tables migration.
     *
     * Merged from all incremental migrations through 2026-03-07:
     * - Authors, Categories (+ softDeletes), Tags, Stories (type dropped), Chapters (+ sort_key),
     *   Chapter Contents (+ hash index), Story↔Tag, Story↔Category, Comments, Ratings, Slug Redirects.
     *
     * Indexes reflect post-optimization state (redundant indexes already excluded).
     */
    public function up(): void
    {
        // ─── Authors ─────────────────────────────────────────────────────
        Schema::create('authors', function (Blueprint $table) {
            $table->id();

            // Basic Info
            $table->string('name', 255);
            $table->string('slug', 255)->unique();
            $table->string('original_name', 255)->nullable()->comment('Tên gốc (tác giả nước ngoài)');

            // Media
            $table->unsignedBigInteger('avatar_id')->nullable()->comment('FK to media table');

            // Description
            $table->text('bio')->nullable()->comment('Tiểu sử ngắn');
            $table->longText('description')->nullable()->comment('Mô tả chi tiết');

            // Social Links
            $table->json('social_links')->nullable();

            // Denormalized Stats
            $table->unsignedInteger('stories_count')->default(0);
            $table->unsignedBigInteger('total_views')->default(0);
            $table->unsignedInteger('total_chapters')->default(0);

            // SEO
            $table->string('meta_title', 255)->nullable();
            $table->text('meta_description')->nullable();

            // Status
            $table->boolean('is_active')->default(true);
            $table->boolean('is_verified')->default(false);

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('name', 'idx_authors_name');
            $table->fullText(['name', 'original_name'], 'ft_authors_search');
        });

        // ─── Categories ──────────────────────────────────────────────────
        Schema::create('categories', function (Blueprint $table) {
            $table->id();

            // Basic Info
            $table->string('name', 255);
            $table->string('slug', 255)->unique();
            $table->text('description')->nullable();
            $table->string('icon', 100)->nullable();
            $table->string('color', 20)->nullable();

            // Media
            $table->unsignedBigInteger('image_id')->nullable()->comment('FK to media table');

            // Nested Set
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedTinyInteger('depth')->default(0);
            $table->string('path', 500)->nullable();
            $table->integer('sort_order')->default(0);

            // Denormalized Stats
            $table->unsignedInteger('stories_count')->default(0);
            $table->unsignedInteger('children_count')->default(0);

            // SEO
            $table->string('meta_title', 255)->nullable();
            $table->text('meta_description')->nullable();

            // Status
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->boolean('show_in_menu')->default(true);

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('parent_id', 'idx_categories_parent');
            $table->index('path', 'idx_categories_path');
            $table->index(['is_active', 'sort_order'], 'idx_categories_sort');
            $table->index(['is_active', 'is_featured'], 'idx_categories_featured');

            // Foreign Key
            $table->foreign('parent_id', 'fk_categories_parent')
                ->references('id')
                ->on('categories')
                ->nullOnDelete();
        });

        // ─── Tags (type already VARCHAR) ─────────────────────────────────
        Schema::create('tags', function (Blueprint $table) {
            $table->id();

            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->string('type', 50)->default('tag');
            $table->text('description')->nullable();
            $table->string('color', 20)->nullable();

            $table->unsignedInteger('stories_count')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // Indexes
            $table->index(['type', 'is_active'], 'idx_tags_type');
            $table->index('stories_count', 'idx_tags_popular');
        });

        // ─── Stories (type column removed) ───────────────────────────────
        Schema::create('stories', function (Blueprint $table) {
            $table->id();

            // Ownership
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('authors')->nullOnDelete();

            // Basic Info
            $table->string('title', 500);
            $table->string('slug', 500)->unique();
            $table->json('alternative_titles')->nullable();
            $table->text('alternative_titles_text')
                ->nullable()
                ->comment('Flattened for full-text search: Tên 1 | Tên 2');

            // Content
            $table->text('description')->nullable();
            $table->longText('content')->nullable();

            // Media
            $table->unsignedBigInteger('cover_image_id')->nullable()->comment('FK to media table');
            $table->unsignedBigInteger('thumbnail_id')->nullable()->comment('FK to media table');
            $table->unsignedBigInteger('banner_id')->nullable()->comment('FK to media table');

            // Classification (VARCHAR instead of ENUM for flexibility)
            $table->string('status', 50)->default('ongoing');
            $table->string('origin', 50)->default('vietnam');

            // Publishing
            $table->boolean('is_published')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_hot')->default(false);
            $table->boolean('is_vip')->default(false);
            $table->boolean('is_locked')->default(false);
            $table->timestamp('published_at')->nullable();

            // Primary Category (denormalized)
            $table->foreignId('primary_category_id')->nullable()->constrained('categories')->nullOnDelete();

            // Denormalized Statistics
            $table->unsignedInteger('total_chapters')->default(0);
            $table->string('latest_chapter_number', 20)->default('0');
            $table->string('latest_chapter_title', 255)->nullable();
            $table->timestamp('last_chapter_at')->nullable();

            $table->unsignedBigInteger('total_word_count')->default(0);

            // View counts
            $table->unsignedBigInteger('view_count')->default(0);
            $table->unsignedBigInteger('view_count_day')->default(0);
            $table->unsignedBigInteger('view_count_week')->default(0);
            $table->unsignedBigInteger('view_count_month')->default(0);

            // Engagement
            $table->unsignedInteger('favorite_count')->default(0);
            $table->unsignedInteger('comment_count')->default(0);
            $table->decimal('rating', 3, 2)->default(0.00);
            $table->unsignedInteger('rating_count')->default(0);
            $table->unsignedInteger('rating_sum')->default(0);

            // SEO
            $table->string('meta_title', 255)->nullable();
            $table->text('meta_description')->nullable();
            $table->string('meta_keywords', 500)->nullable();
            $table->string('canonical_url', 500)->nullable();

            // Timestamps
            $table->timestamps();
            $table->softDeletes();

            // Indexes (post-optimization — redundant indexes removed)
            $table->index('author_id', 'idx_stories_author');
            $table->index('user_id', 'idx_stories_user');
            $table->index('primary_category_id', 'idx_stories_category');

            $table->index(['is_published', 'status'], 'idx_stories_published');
            $table->index(['is_published', 'last_chapter_at'], 'idx_stories_latest');
            $table->index(['is_published', 'origin', 'last_chapter_at'], 'idx_stories_origin');
            $table->index(['is_published', 'rating', 'rating_count'], 'idx_stories_rating');

            $table->fullText(['title', 'description'], 'ft_stories_search');
        });

        // ─── Chapters (chapter_number is VARCHAR, sort_key is generated) ─
        Schema::create('chapters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('story_id')->constrained('stories')->cascadeOnDelete();

            // Ordering — VARCHAR to support alphanumeric (e.g., "5b", "1.5")
            $table->string('chapter_number', 20);
            // sort_key: generated STORED column added via raw SQL below
            $table->unsignedTinyInteger('sub_chapter')->default(0)->comment('0=main, 1,2,3=parts of chapter');
            $table->unsignedTinyInteger('volume_number')->default(1);

            // Basic Info
            $table->string('title', 500)->nullable();
            $table->string('slug', 500);

            // Navigation Cache
            $table->unsignedBigInteger('prev_chapter_id')->nullable();
            $table->unsignedBigInteger('next_chapter_id')->nullable();

            // Stats
            $table->unsignedInteger('word_count')->default(0);
            $table->unsignedBigInteger('view_count')->default(0);
            $table->unsignedInteger('comment_count')->default(0);

            // Status
            $table->boolean('is_published')->default(false);
            $table->boolean('is_vip')->default(false);
            $table->boolean('is_free_preview')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('scheduled_at')->nullable();

            // SEO
            $table->string('meta_title', 255)->nullable();
            $table->text('meta_description')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Indexes (post-optimization — redundant indexes removed)
            $table->unique(['story_id', 'chapter_number', 'sub_chapter'], 'uq_chapters_story_number');
            $table->unique(['story_id', 'slug'], 'uq_chapters_story_slug');
            // idx_chapters_list and idx_chapters_sort use sort_key — created via raw SQL below
        });

        // Add sort_key generated column (REGEXP_SUBSTR handles alphanumeric chapter numbers)
        $prefix = DB::getTablePrefix();
        DB::statement("
            ALTER TABLE {$prefix}chapters
            ADD COLUMN sort_key DECIMAL(10,2) AS (
                CAST(
                    COALESCE(
                        REGEXP_SUBSTR(chapter_number, '^[0-9]+\\.?[0-9]*'),
                        '0'
                    ) AS DECIMAL(10,2)
                )
            ) STORED
            AFTER chapter_number
        ");

        DB::statement("
            CREATE INDEX idx_chapters_list
            ON {$prefix}chapters (story_id, is_published, sort_key, sub_chapter)
        ");

        DB::statement("
            CREATE INDEX idx_chapters_sort
            ON {$prefix}chapters (story_id, sort_key, chapter_number, sub_chapter)
        ");

        // ─── Chapter Contents ────────────────────────────────────────────
        Schema::create('chapter_contents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chapter_id')->unique()->constrained('chapters')->cascadeOnDelete();

            $table->longText('content');
            $table->longText('content_html')->nullable()->comment('Pre-rendered HTML');
            $table->char('content_hash', 32)->nullable()->comment('MD5 for deduplication');
            $table->unsignedInteger('byte_size')->default(0);

            $table->timestamps();

            // Index for deduplication queries
            $table->index('content_hash', 'idx_chapter_contents_hash');
        });

        // Set ROW_FORMAT to COMPRESSED for storage optimization
        DB::statement(
            'ALTER TABLE ' . DB::getTablePrefix() .
            'chapter_contents ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=8'
        );

        // ─── Story ↔ Tag (pivot) ─────────────────────────────────────────
        Schema::create('story_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('story_id')->constrained('stories')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['story_id', 'tag_id'], 'uq_story_tag');
            $table->index(['tag_id', 'story_id'], 'idx_tag_stories');
        });

        // ─── Story ↔ Category (pivot) ────────────────────────────────────
        Schema::create('story_category', function (Blueprint $table) {
            $table->id();
            $table->foreignId('story_id')->constrained('stories')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->timestamp('created_at')->nullable();

            $table->unique(['story_id', 'category_id'], 'uq_story_category');
            $table->index(['category_id', 'story_id'], 'idx_category_stories');
        });

        // ─── Comments ────────────────────────────────────────────────────
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // Polymorphic
            $table->string('commentable_type', 50)->comment('story, chapter');
            $table->unsignedBigInteger('commentable_id');

            // Nested comments
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedTinyInteger('depth')->default(0)->comment('0=root, 1=reply, 2=reply-to-reply');

            // Content
            $table->text('content');
            $table->text('content_html')->nullable()->comment('Rendered with markdown/mentions');

            // Stats
            $table->unsignedInteger('likes_count')->default(0);
            $table->unsignedInteger('replies_count')->default(0);

            // Flags
            $table->boolean('is_pinned')->default(false);
            $table->boolean('is_spoiler')->default(false);
            $table->boolean('is_hidden')->default(false);
            $table->string('hidden_reason', 255)->nullable();

            // Edit tracking
            $table->timestamp('edited_at')->nullable();
            $table->unsignedTinyInteger('edit_count')->default(0);

            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['commentable_type', 'commentable_id', 'created_at'], 'idx_comments_target');
            $table->index(['user_id', 'created_at'], 'idx_comments_user');
            $table->index('parent_id', 'idx_comments_parent');
            $table->index(
                ['commentable_type', 'commentable_id', 'is_pinned', 'created_at'],
                'idx_comments_pinned'
            );

            // Foreign Keys
            $table->foreign('parent_id', 'fk_comments_parent')
                ->references('id')
                ->on('comments')
                ->cascadeOnDelete();
        });

        // ─── Ratings ─────────────────────────────────────────────────────
        Schema::create('ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('story_id')->constrained('stories')->cascadeOnDelete();

            $table->unsignedTinyInteger('rating')->comment('1-5');
            $table->text('review')->nullable()->comment('Optional review text');

            $table->unsignedInteger('helpful_count')->default(0)->comment('"Hữu ích" votes');
            $table->boolean('is_featured')->default(false)->comment('Highlighted review');

            $table->timestamps();

            $table->unique(['user_id', 'story_id'], 'uq_rating_user_story');
            $table->index(['story_id', 'rating'], 'idx_ratings_story');
            $table->index(['story_id', 'is_featured', 'helpful_count'], 'idx_ratings_featured');
        });

        // ─── Slug Redirects ──────────────────────────────────────────────
        Schema::create('slug_redirects', function (Blueprint $table) {
            $table->id();

            $table->string('model_type', 50)->comment('story, category, author, tag');
            $table->unsignedBigInteger('model_id');
            $table->string('old_slug', 500);

            $table->timestamp('created_at')->useCurrent();

            $table->unique(['model_type', 'old_slug'], 'uq_slug_redirect');
            $table->index(['model_type', 'model_id'], 'idx_redirect_model');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slug_redirects');
        Schema::dropIfExists('ratings');
        Schema::dropIfExists('comments');
        Schema::dropIfExists('story_category');
        Schema::dropIfExists('story_tag');
        Schema::dropIfExists('chapter_contents');
        Schema::dropIfExists('chapters');
        Schema::dropIfExists('stories');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('authors');
    }
};
