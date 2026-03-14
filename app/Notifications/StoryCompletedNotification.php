<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Story;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Sent when a bookmarked story's status changes to "completed".
 *
 * Recipients: all users who bookmarked the story.
 * Channel: database.
 */
class StoryCompletedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected int $storyId,
        protected string $storyTitle,
        protected string $storySlug,
    ) {}

    /**
     * Create from a Story model (convenience factory).
     */
    public static function fromStory(Story $story): self
    {
        return new self(
            storyId: $story->id,
            storyTitle: $story->title,
            storySlug: $story->slug,
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
            'type'        => 'story_completed',
            'story_id'    => $this->storyId,
            'story_title' => $this->storyTitle,
            'story_slug'  => $this->storySlug,
        ];
    }
}
