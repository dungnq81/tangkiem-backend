<?php

declare(strict_types=1);

namespace App\Filament\Resources\Chapters\Pages;

use App\Filament\Resources\Chapters\ChapterResource;
use App\Models\Chapter;
use App\Services\Ai\AiContentCleaner;
use App\Services\Ai\AiService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditChapter extends EditRecord
{
    protected static string $resource = ChapterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // ── Navigation ──────────────────────────────────
            $this->getPrevChapterAction(),
            $this->getNextChapterAction(),

            // ── AI Actions ──────────────────────────────────
            $this->getAiCleanAction(),

            // ── Standard Actions ────────────────────────────
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    // Prev/Next Navigation
    // ═══════════════════════════════════════════════════════════════

    protected function getPrevChapterAction(): Action
    {
        /** @var Chapter $record */
        $record = $this->record;

        $prev = Chapter::query()
            ->where('story_id', $record->story_id)
            ->where('sort_key', '<', $record->sort_key)
            ->orderByDesc('sort_key')
            ->first('id');

        return Action::make('prevChapter')
            ->label('Trước')
            ->icon(Heroicon::OutlinedChevronLeft)
            ->color('gray')
            ->size('sm')
            ->disabled($prev === null)
            ->tooltip($prev ? 'Chương trước' : 'Không có chương trước')
            ->url(fn (): ?string => $prev
                ? ChapterResource::getUrl('edit', ['record' => $prev->id])
                : null);
    }

    protected function getNextChapterAction(): Action
    {
        /** @var Chapter $record */
        $record = $this->record;

        $next = Chapter::query()
            ->where('story_id', $record->story_id)
            ->where('sort_key', '>', $record->sort_key)
            ->orderBy('sort_key')
            ->first('id');

        return Action::make('nextChapter')
            ->label('Sau')
            ->icon(Heroicon::OutlinedChevronRight)
            ->iconPosition('after')
            ->color('gray')
            ->size('sm')
            ->disabled($next === null)
            ->tooltip($next ? 'Chương sau' : 'Không có chương sau')
            ->url(fn (): ?string => $next
                ? ChapterResource::getUrl('edit', ['record' => $next->id])
                : null);
    }

    // ═══════════════════════════════════════════════════════════════
    // Breadcrumbs — Story context
    // ═══════════════════════════════════════════════════════════════

    public function getBreadcrumbs(): array
    {
        /** @var Chapter $record */
        $record = $this->record;
        $story = $record->story;

        return [
            ChapterResource::getUrl() => 'Chương',
            '#' => $story?->title ?? 'Truyện',
            '' => $record->formatted_number,
        ];
    }

    /**
     * T3: AI Dọn dẹp nội dung — clean chapter content.
     */
    protected function getAiCleanAction(): Action
    {
        return Action::make('ai_clean')
            ->label('Dọn dẹp')
            ->icon(Heroicon::OutlinedSparkles)
            ->color('info')
            ->visible(fn (): bool => AiService::isEnabled('content_clean'))
            ->form([
                Select::make('mode')
                    ->label('Chế độ dọn dẹp')
                    ->options([
                        'patterns_only' => '⚡ Patterns + Regex (miễn phí)',
                        'ai'            => '🤖 Patterns + Regex + AI (tốn tokens)',
                    ])
                    ->default('patterns_only')
                    ->required()
                    ->native(false),
            ])
            ->modalIcon(Heroicon::OutlinedSparkles)
            ->modalHeading('Dọn dẹp nội dung chương')
            ->modalDescription('Loại bỏ quảng cáo, watermark, text rác khỏi nội dung chương.')
            ->action(function (array $data): void {
                /** @var Chapter $record */
                $record = $this->record;
                $chapterContent = $record->content;

                if (! $chapterContent || empty($chapterContent->content)) {
                    Notification::make()
                        ->title('Không có nội dung')
                        ->body('Chương này chưa có nội dung để dọn dẹp.')
                        ->warning()
                        ->send();

                    return;
                }

                try {
                    $cleaner = app(AiContentCleaner::class);
                    $useAi = ($data['mode'] ?? 'patterns_only') === 'ai';

                    $originalContent = $chapterContent->content;
                    $result = $cleaner->cleanWithReport(
                        $originalContent,
                        $record->scrape_source_id,
                        $useAi,
                    );

                    if ($result['charDiff'] === 0) {
                        Notification::make()
                            ->title('Không có thay đổi')
                            ->body('Nội dung đã sạch, không cần dọn dẹp.')
                            ->info()
                            ->send();

                        return;
                    }

                    $chapterContent->update([
                        'content'      => $result['content'],
                        'content_hash' => md5($result['content']),
                        'byte_size'    => strlen($result['content']),
                    ]);

                    $this->refreshFormData(['content']);

                    // Build detailed report
                    $lines = ["Đã xóa **{$result['charDiff']}** ký tự rác."];
                    foreach ($result['removals'] as $r) {
                        $lines[] = '- ' . $r;
                    }

                    Notification::make()
                        ->title('Đã dọn dẹp nội dung')
                        ->body(\Illuminate\Support\Str::markdown(implode("\n", $lines)))
                        ->success()
                        ->duration(10000)
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('Lỗi dọn dẹp')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }
}
