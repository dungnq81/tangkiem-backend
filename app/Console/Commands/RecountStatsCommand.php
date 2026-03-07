<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Author;
use App\Models\Category;
use App\Models\Chapter;
use App\Models\Comment;
use App\Models\Story;
use App\Models\Tag;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recount denormalized statistics across all models.
 *
 * Usage:
 *   php artisan app:recount-stats          # Recount all
 *   php artisan app:recount-stats tags     # Recount only tags
 *   php artisan app:recount-stats --dry    # Show what would change
 */
class RecountStatsCommand extends Command
{
    protected $signature = 'app:recount-stats
                            {model? : Specific model to recount (tags, categories, stories, chapters, comments, authors)}
                            {--dry : Dry run — show counts without updating}';

    protected $description = 'Recount denormalized statistics (stories_count, comment_count, etc.)';

    public function handle(): int
    {
        $model = $this->argument('model');
        $isDry = $this->option('dry');

        if ($isDry) {
            $this->warn('🔍 DRY RUN — no changes will be made.');
        }

        $recounters = [
            'tags'       => fn () => $this->recountTagStoriesCount($isDry),
            'categories' => fn () => $this->recountCategoryStoriesCount($isDry),
            'stories'    => fn () => $this->recountStoryCommentCount($isDry),
            'chapters'   => fn () => $this->recountChapterCommentCount($isDry),
            'comments'   => fn () => $this->recountCommentRepliesCount($isDry),
            'authors'    => fn () => $this->recountAuthorStats($isDry),
        ];

        if ($model) {
            if (!isset($recounters[$model])) {
                $this->error("Unknown model: {$model}. Available: " . implode(', ', array_keys($recounters)));

                return self::FAILURE;
            }

            $recounters[$model]();
        } else {
            foreach ($recounters as $name => $recounter) {
                $recounter();
            }
        }

        $this->newLine();
        $this->info('✅ Done.');

        return self::SUCCESS;
    }

    /**
     * Recount tags.stories_count from story_tag pivot.
     */
    protected function recountTagStoriesCount(bool $isDry): void
    {
        $this->info('📊 Recounting tags.stories_count...');

        $prefix = DB::getTablePrefix();

        if ($isDry) {
            $mismatched = DB::select("
                SELECT t.id, t.name, t.stories_count AS current,
                       COALESCE(st.cnt, 0) AS actual
                FROM {$prefix}tags t
                LEFT JOIN (
                    SELECT tag_id, COUNT(*) AS cnt
                    FROM {$prefix}story_tag
                    GROUP BY tag_id
                ) st ON st.tag_id = t.id
                WHERE t.stories_count != COALESCE(st.cnt, 0)
            ");

            $this->table(['ID', 'Name', 'Current', 'Actual'], array_map(fn ($r) => [
                $r->id, $r->name, $r->current, $r->actual,
            ], $mismatched));

            $this->line("  → {$this->countLabel(count($mismatched))} mismatched.");

            return;
        }

        $updated = DB::update("
            UPDATE {$prefix}tags t
            LEFT JOIN (
                SELECT tag_id, COUNT(*) AS cnt
                FROM {$prefix}story_tag
                GROUP BY tag_id
            ) st ON st.tag_id = t.id
            SET t.stories_count = COALESCE(st.cnt, 0)
            WHERE t.stories_count != COALESCE(st.cnt, 0)
        ");

        $this->line("  → Updated {$updated} tags.");
    }

    /**
     * Recount categories.stories_count from story_category pivot.
     */
    protected function recountCategoryStoriesCount(bool $isDry): void
    {
        $this->info('📊 Recounting categories.stories_count...');

        $prefix = DB::getTablePrefix();

        if ($isDry) {
            $mismatched = DB::select("
                SELECT c.id, c.name, c.stories_count AS current,
                       COALESCE(sc.cnt, 0) AS actual
                FROM {$prefix}categories c
                LEFT JOIN (
                    SELECT category_id, COUNT(*) AS cnt
                    FROM {$prefix}story_category
                    GROUP BY category_id
                ) sc ON sc.category_id = c.id
                WHERE c.stories_count != COALESCE(sc.cnt, 0)
                AND c.deleted_at IS NULL
            ");

            $this->table(['ID', 'Name', 'Current', 'Actual'], array_map(fn ($r) => [
                $r->id, $r->name, $r->current, $r->actual,
            ], $mismatched));

            $this->line("  → {$this->countLabel(count($mismatched))} mismatched.");

            return;
        }

        $updated = DB::update("
            UPDATE {$prefix}categories c
            LEFT JOIN (
                SELECT category_id, COUNT(*) AS cnt
                FROM {$prefix}story_category
                GROUP BY category_id
            ) sc ON sc.category_id = c.id
            SET c.stories_count = COALESCE(sc.cnt, 0)
            WHERE c.stories_count != COALESCE(sc.cnt, 0)
            AND c.deleted_at IS NULL
        ");

        $this->line("  → Updated {$updated} categories.");
    }

    /**
     * Recount stories.comment_count from comments table.
     */
    protected function recountStoryCommentCount(bool $isDry): void
    {
        $this->info('📊 Recounting stories.comment_count...');

        $prefix = DB::getTablePrefix();

        if ($isDry) {
            $mismatched = DB::select("
                SELECT s.id, s.title, s.comment_count AS current,
                       COALESCE(cm.cnt, 0) AS actual
                FROM {$prefix}stories s
                LEFT JOIN (
                    SELECT commentable_id, COUNT(*) AS cnt
                    FROM {$prefix}comments
                    WHERE commentable_type = 'story' AND deleted_at IS NULL
                    GROUP BY commentable_id
                ) cm ON cm.commentable_id = s.id
                WHERE s.comment_count != COALESCE(cm.cnt, 0)
                AND s.deleted_at IS NULL
                LIMIT 50
            ");

            $this->table(['ID', 'Title', 'Current', 'Actual'], array_map(fn ($r) => [
                $r->id, mb_substr($r->title, 0, 40), $r->current, $r->actual,
            ], $mismatched));

            $this->line("  → {$this->countLabel(count($mismatched))} mismatched (showing max 50).");

            return;
        }

        $updated = DB::update("
            UPDATE {$prefix}stories s
            LEFT JOIN (
                SELECT commentable_id, COUNT(*) AS cnt
                FROM {$prefix}comments
                WHERE commentable_type = 'story' AND deleted_at IS NULL
                GROUP BY commentable_id
            ) cm ON cm.commentable_id = s.id
            SET s.comment_count = COALESCE(cm.cnt, 0)
            WHERE s.comment_count != COALESCE(cm.cnt, 0)
            AND s.deleted_at IS NULL
        ");

        $this->line("  → Updated {$updated} stories.");
    }

    /**
     * Recount chapters.comment_count from comments table.
     */
    protected function recountChapterCommentCount(bool $isDry): void
    {
        $this->info('📊 Recounting chapters.comment_count...');

        $prefix = DB::getTablePrefix();

        if (!$isDry) {
            $updated = DB::update("
                UPDATE {$prefix}chapters ch
                LEFT JOIN (
                    SELECT commentable_id, COUNT(*) AS cnt
                    FROM {$prefix}comments
                    WHERE commentable_type = 'chapter' AND deleted_at IS NULL
                    GROUP BY commentable_id
                ) cm ON cm.commentable_id = ch.id
                SET ch.comment_count = COALESCE(cm.cnt, 0)
                WHERE ch.comment_count != COALESCE(cm.cnt, 0)
                AND ch.deleted_at IS NULL
            ");

            $this->line("  → Updated {$updated} chapters.");
        } else {
            $count = DB::selectOne("
                SELECT COUNT(*) AS cnt
                FROM {$prefix}chapters ch
                LEFT JOIN (
                    SELECT commentable_id, COUNT(*) AS cnt
                    FROM {$prefix}comments
                    WHERE commentable_type = 'chapter' AND deleted_at IS NULL
                    GROUP BY commentable_id
                ) cm ON cm.commentable_id = ch.id
                WHERE ch.comment_count != COALESCE(cm.cnt, 0)
                AND ch.deleted_at IS NULL
            ");

            $this->line("  → {$this->countLabel($count->cnt)} mismatched.");
        }
    }

    /**
     * Recount comments.replies_count from self-referencing parent_id.
     */
    protected function recountCommentRepliesCount(bool $isDry): void
    {
        $this->info('📊 Recounting comments.replies_count...');

        $prefix = DB::getTablePrefix();

        if (!$isDry) {
            $updated = DB::update("
                UPDATE {$prefix}comments c
                LEFT JOIN (
                    SELECT parent_id, COUNT(*) AS cnt
                    FROM {$prefix}comments
                    WHERE deleted_at IS NULL
                    GROUP BY parent_id
                ) r ON r.parent_id = c.id
                SET c.replies_count = COALESCE(r.cnt, 0)
                WHERE c.replies_count != COALESCE(r.cnt, 0)
                AND c.deleted_at IS NULL
            ");

            $this->line("  → Updated {$updated} comments.");
        } else {
            $count = DB::selectOne("
                SELECT COUNT(*) AS cnt
                FROM {$prefix}comments c
                LEFT JOIN (
                    SELECT parent_id, COUNT(*) AS cnt
                    FROM {$prefix}comments
                    WHERE deleted_at IS NULL
                    GROUP BY parent_id
                ) r ON r.parent_id = c.id
                WHERE c.replies_count != COALESCE(r.cnt, 0)
                AND c.deleted_at IS NULL
            ");

            $this->line("  → {$this->countLabel($count->cnt)} mismatched.");
        }
    }

    /**
     * Recount authors aggregate stats.
     */
    protected function recountAuthorStats(bool $isDry): void
    {
        $this->info('📊 Recounting authors stats (stories_count, total_chapters, total_views)...');

        $prefix = DB::getTablePrefix();

        if (!$isDry) {
            $updated = DB::update("
                UPDATE {$prefix}authors a
                LEFT JOIN (
                    SELECT author_id,
                           COUNT(*) AS stories_count,
                           COALESCE(SUM(total_chapters), 0) AS total_chapters,
                           COALESCE(SUM(view_count), 0) AS total_views
                    FROM {$prefix}stories
                    WHERE deleted_at IS NULL
                    GROUP BY author_id
                ) s ON s.author_id = a.id
                SET a.stories_count = COALESCE(s.stories_count, 0),
                    a.total_chapters = COALESCE(s.total_chapters, 0),
                    a.total_views = COALESCE(s.total_views, 0)
                WHERE (
                    a.stories_count != COALESCE(s.stories_count, 0)
                    OR a.total_chapters != COALESCE(s.total_chapters, 0)
                    OR a.total_views != COALESCE(s.total_views, 0)
                )
                AND a.deleted_at IS NULL
            ");

            $this->line("  → Updated {$updated} authors.");
        } else {
            $count = DB::selectOne("
                SELECT COUNT(*) AS cnt
                FROM {$prefix}authors a
                LEFT JOIN (
                    SELECT author_id,
                           COUNT(*) AS stories_count,
                           COALESCE(SUM(total_chapters), 0) AS total_chapters,
                           COALESCE(SUM(view_count), 0) AS total_views
                    FROM {$prefix}stories
                    WHERE deleted_at IS NULL
                    GROUP BY author_id
                ) s ON s.author_id = a.id
                WHERE (
                    a.stories_count != COALESCE(s.stories_count, 0)
                    OR a.total_chapters != COALESCE(s.total_chapters, 0)
                    OR a.total_views != COALESCE(s.total_views, 0)
                )
                AND a.deleted_at IS NULL
            ");

            $this->line("  → {$this->countLabel($count->cnt)} mismatched.");
        }
    }

    protected function countLabel(int $count): string
    {
        return $count === 0 ? '✅ 0' : "⚠️ {$count}";
    }
}
