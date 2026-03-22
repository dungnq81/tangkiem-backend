<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Chapter;
use App\Models\Comment;
use App\Models\Story;
use App\Services\Ai\AiModerator;
use App\Services\Ai\AiService;
use Illuminate\Support\Facades\Log;

class CommentObserver
{
    /**
     * Handle the Comment "created" event.
     *
     * - Auto-moderate new comments using AI if enabled.
     * - Sync comment_count on the target (story/chapter).
     * - Sync replies_count on the parent comment.
     */
    public function created(Comment $comment): void
    {
        // Sync denormalized counters
        $this->syncTargetCommentCount($comment);
        $this->syncParentRepliesCount($comment);

        // AI moderation
        if (! AiService::isEnabled('content_moderation')) {
            return;
        }

        // Don't moderate hidden comments (already flagged)
        if ($comment->is_hidden) {
            return;
        }

        try {
            $moderator = app(AiModerator::class);
            $result = $moderator->check($comment->content ?? '');

            if (! $result['is_safe']) {
                $reason = "AI: [{$result['category']}] {$result['reason']}";

                $comment->hide($reason);

                Log::info('Comment auto-moderated', [
                    'comment_id' => $comment->id,
                    'category'   => $result['category'],
                    'reason'     => $result['reason'],
                ]);
            }
        } catch (\Throwable $e) {
            // Fail open: don't hide comment if AI is unavailable
            Log::warning('AI comment moderation failed', [
                'comment_id' => $comment->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle the Comment "deleted" event (soft delete).
     * Sync comment_count and replies_count.
     */
    public function deleted(Comment $comment): void
    {
        $this->syncTargetCommentCount($comment);
        $this->syncParentRepliesCount($comment);
    }

    /**
     * Handle the Comment "restored" event.
     * Sync comment_count and replies_count.
     */
    public function restored(Comment $comment): void
    {
        $this->syncTargetCommentCount($comment);
        $this->syncParentRepliesCount($comment);
    }

    /**
     * Handle the Comment "forceDeleted" event.
     * Sync comment_count and replies_count.
     */
    public function forceDeleted(Comment $comment): void
    {
        $this->syncTargetCommentCount($comment);
        $this->syncParentRepliesCount($comment);
    }

    // ═══════════════════════════════════════════════════════════════
    // Counter Sync Helpers
    // ═══════════════════════════════════════════════════════════════

    /**
     * Sync comment_count on the polymorphic target (story or chapter).
     */
    protected function syncTargetCommentCount(Comment $comment): void
    {
        $count = Comment::query()
            ->where('commentable_type', $comment->commentable_type)
            ->where('commentable_id', $comment->commentable_id)
            ->count();

        match ($comment->commentable_type) {
            'story' => Story::query()
                ->where('id', $comment->commentable_id)
                ->update(['comment_count' => $count]),
            'chapter' => Chapter::query()
                ->where('id', $comment->commentable_id)
                ->update(['comment_count' => $count]),
            default => null,
        };
    }

    /**
     * Sync replies_count on the parent comment.
     */
    protected function syncParentRepliesCount(Comment $comment): void
    {
        if (! $comment->parent_id) {
            return;
        }

        $count = Comment::query()
            ->where('parent_id', $comment->parent_id)
            ->count();

        Comment::query()
            ->where('id', $comment->parent_id)
            ->update(['replies_count' => $count]);
    }
}
