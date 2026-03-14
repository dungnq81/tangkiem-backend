<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Chapter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Sent when a new chapter is published for a bookmarked story.
 *
 * Recipients: all users who bookmarked the story.
 * Channel: database.
 */
class NewChapterNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected int $storyId,
        protected string $storyTitle,
        protected string $storySlug,
        protected int $chapterId,
        protected string $chapterTitle,
        protected string $chapterNumber,
    ) {}

    /**
     * Create from a Chapter model (convenience factory).
     */
    public static function fromChapter(Chapter $chapter): self
    {
        return new self(
            storyId: $chapter->story_id,
            storyTitle: $chapter->story?->title ?? '',
            storySlug: $chapter->story?->slug ?? '',
            chapterId: $chapter->id,
            chapterTitle: $chapter->title ?? '',
            chapterNumber: $chapter->formatted_number ?? "Ch.{$chapter->chapter_number}",
        );
    }

    /**
     * @return string[]
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type'           => 'new_chapter',
            'story_id'       => $this->storyId,
            'story_title'    => $this->storyTitle,
            'story_slug'     => $this->storySlug,
            'chapter_id'     => $this->chapterId,
            'chapter_title'  => $this->chapterTitle,
            'chapter_number' => $this->chapterNumber,
        ];
    }
}
