<?php

declare(strict_types=1);

namespace App\Filament\Resources\ScrapeJobs\Widgets;

use App\Filament\Resources\ScrapeJobs\ScrapeJobResource;
use App\Models\ScrapeItem;
use App\Models\ScrapeJob;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;

class ScrapeCompletionWidget extends Widget
{
    protected string $view = 'filament.resources.scrape-jobs.widgets.scrape-completion-widget';

    protected int | string | array $columnSpan = 'full';

    /**
     * Track whether confirmation step is active.
     */
    public bool $confirming = false;

    protected function getViewData(): array
    {
        $total = ScrapeJob::count();
        $allDone = false;

        if ($total > 0) {
            $allDone = true;

            $jobs = ScrapeJob::withCount('items')->get();

            /** @var ScrapeJob $job */
            foreach ($jobs as $job) {
                // Job must have items
                if ($job->items_count === 0) {
                    $allDone = false;
                    break;
                }

                // All items must be imported or merged (no draft, selected, error, skipped)
                $importedCount = $job->items()
                    ->whereIn('status', [ScrapeItem::STATUS_IMPORTED, ScrapeItem::STATUS_MERGED])
                    ->count();

                if ($importedCount !== $job->items_count) {
                    $allDone = false;
                    break;
                }

                // For chapter jobs with detail_config: all items must have content fetched (no _detail_error)
                if ($job->isChapterType() && $job->hasDetailConfig()) {
                    $hasDetailError = $job->items()
                        ->where('has_error', true)
                        ->exists();

                    if ($hasDetailError) {
                        $allDone = false;
                        break;
                    }

                    // Verify all items have content
                    $missingContent = $job->items()
                        ->where('has_content', false)
                        ->exists();

                    if ($missingContent) {
                        $allDone = false;
                        break;
                    }
                }
            }
        }

        return [
            'allDone' => $allDone,
            'total' => $total,
        ];
    }

    public function confirmDelete(): void
    {
        $this->confirming = true;
    }

    public function cancelDelete(): void
    {
        $this->confirming = false;
    }

    public function deleteAllDone(): void
    {
        $deleted = ScrapeJob::where('status', ScrapeJob::STATUS_DONE)->delete();

        Notification::make()
            ->title("Đã xóa {$deleted} tác vụ hoàn thành")
            ->success()
            ->send();

        $this->redirect(ScrapeJobResource::getUrl('index'));
    }
}
