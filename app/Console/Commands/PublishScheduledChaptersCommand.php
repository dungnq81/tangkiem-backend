<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Chapter;
use App\Models\User;
use App\Notifications\NewChapterNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Auto-publish chapters that have reached their scheduled_at time.
 *
 * Usage:
 *   php artisan chapters:publish-scheduled        # Publish due chapters
 *   php artisan chapters:publish-scheduled --dry   # Show what would be published
 *
 * Important: Uses individual model ->update() to trigger ChapterObserver,
 * which rebuilds story stats (total_chapters, latest_chapter_*, navigation).
 */
class PublishScheduledChaptersCommand extends Command
{
    protected $signature = 'chapters:publish-scheduled
                            {--dry : Dry run — show what would be published without publishing}';

    protected $description = 'Auto-publish chapters that have reached their scheduled_at time';

    public function handle(): int
    {
        $isDry = $this->option('dry');

        // Find chapters that are due for publishing
        $chapters = Chapter::query()
            ->where('is_published', false)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->with('story:id,title,slug')
            ->ordered()
            ->get(['id', 'story_id', 'chapter_number', 'sub_chapter', 'title', 'scheduled_at']);

        if ($chapters->isEmpty()) {
            $this->line('No scheduled chapters are due for publishing.');

            return self::SUCCESS;
        }

        if ($isDry) {
            $this->warn('🔍 DRY RUN — no changes will be made.');
            $this->table(
                ['ID', 'Story', 'Chapter', 'Title', 'Scheduled At'],
                $chapters->map(fn (Chapter $ch) => [
                    $ch->id,
                    mb_substr($ch->story?->title ?? '—', 0, 30),
                    $ch->formatted_number,
                    mb_substr($ch->title ?? '—', 0, 30),
                    $ch->scheduled_at?->format('Y-m-d H:i'),
                ])->toArray(),
            );
            $this->line("→ {$chapters->count()} chapter(s) would be published.");

            return self::SUCCESS;
        }

        $published = 0;
        $affectedStories = [];

        foreach ($chapters as $chapter) {
            /** @var Chapter $chapter */
            try {
                // Use scheduled_at as published_at to preserve the intended time
                $chapter->update([
                    'is_published' => true,
                    'published_at' => $chapter->scheduled_at,
                ]);

                $published++;
                $affectedStories[$chapter->story_id] = $chapter->story?->title ?? "Story #{$chapter->story_id}";

                $this->line("  ✅ Published: [{$chapter->story?->title}] {$chapter->formatted_number}");
            } catch (\Throwable $e) {
                $this->error("  ❌ Failed to publish Chapter #{$chapter->id}: {$e->getMessage()}");

                Log::error('Failed to publish scheduled chapter', [
                    'chapter_id' => $chapter->id,
                    'story_id'   => $chapter->story_id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        // Summary
        $this->newLine();
        $this->info("✅ Published {$published}/{$chapters->count()} chapter(s) across " . count($affectedStories) . ' story/stories.');

        // Notify bookmarkers (queued, per story — only latest chapter)
        if ($published > 0) {
            $this->notifyBookmarkers($chapters->filter(fn ($ch) => $ch->is_published), $affectedStories);

            Log::info('Scheduled chapters auto-published', [
                'count'            => $published,
                'affected_stories' => $affectedStories,
            ]);
        }

        return self::SUCCESS;
    }

    /**
     * Send new chapter notifications to users who bookmarked affected stories.
     *
     * When multiple chapters publish for the same story, only notify about
     * the latest one to avoid spamming.
     */
    protected function notifyBookmarkers($publishedChapters, array $affectedStories): void
    {
        // Group by story, keep only the latest chapter per story
        $latestPerStory = $publishedChapters
            ->groupBy('story_id')
            ->map(fn ($chapters) => $chapters->sortByDesc('chapter_number')->first());

        foreach ($latestPerStory as $storyId => $chapter) {
            // Get users who bookmarked this story
            $users = User::query()
                ->whereHas('bookmarks', fn ($q) => $q->where('story_id', $storyId))
                ->get();

            if ($users->isEmpty()) {
                continue;
            }

            $notification = NewChapterNotification::fromChapter($chapter);
            Notification::send($users, $notification);

            $this->line("  📢 Notified {$users->count()} bookmarker(s) for [{$affectedStories[$storyId]}]");
        }
    }
}
