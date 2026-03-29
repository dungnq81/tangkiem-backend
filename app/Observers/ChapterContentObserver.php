<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Chapter;
use App\Models\ChapterContent;

class ChapterContentObserver
{
    /**
     * Handle the ChapterContent "created" event.
     * Sync word_count to the parent chapter.
     */
    public function created(ChapterContent $content): void
    {
        $this->syncWordCount($content);
    }

    /**
     * Handle the ChapterContent "updated" event.
     * Sync word_count when content changes.
     */
    public function updated(ChapterContent $content): void
    {
        if ($content->isDirty('content')) {
            $this->syncWordCount($content);
        }
    }

    /**
     * Calculate word count from content and update the parent chapter.
     */
    protected function syncWordCount(ChapterContent $content): void
    {
        $chapter = $content->chapter;

        if (! $chapter) {
            return;
        }

        $plainText = trim(strip_tags($content->content ?? ''));
        $wordCount = Chapter::countWords($plainText);

        $chapter->updateQuietly(['word_count' => $wordCount]);
    }
}
