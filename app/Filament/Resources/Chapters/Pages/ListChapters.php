<?php

declare(strict_types=1);

namespace App\Filament\Resources\Chapters\Pages;

use App\Filament\Pages\ListRecords;
use App\Filament\Resources\Chapters\ChapterResource;
use App\Models\Chapter;
use App\Models\Story;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Grid;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class ListChapters extends ListRecords
{
    protected static string $resource = ChapterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            self::setVolumeAction(),
            self::setSubChapterAction(),
            self::emptyTrashAction(Chapter::class, 'chương'),
            CreateAction::make(),
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    // Story Tabs — Quick filter by top stories
    // ═══════════════════════════════════════════════════════════════

    public function getTabs(): array
    {
        $tabs = [
            'all' => Tab::make('Tất cả')
                ->icon(Heroicon::OutlinedRectangleStack),
        ];

        // 3 most recently updated stories — quick shortcuts
        $recentStories = Story::query()
            ->withCount('chapters')
            ->latest('updated_at')
            ->limit(3)
            ->get(['id', 'title']);

        foreach ($recentStories as $story) {
            $tabs["story_{$story->id}"] = Tab::make($story->title)
                ->icon(Heroicon::OutlinedBookOpen)
                ->modifyQueryUsing(fn (Builder $query) => $query->where('story_id', $story->id))
                ->badge($story->chapters_count);
        }

        return $tabs;
    }

    // ═══════════════════════════════════════════════════════════════
    // Subheading — Show current filter context
    // ═══════════════════════════════════════════════════════════════

    public function getSubheading(): ?string
    {
        $storyId = request()->input('filters.story_id.value')
            ?? request()->input('tableFilters.story_id.value');

        if (filled($storyId)) {
            $storyTitle = Story::where('id', $storyId)->value('title');
            if ($storyTitle) {
                return "📖 Đang xem: {$storyTitle}";
            }
        }

        $activeTab = $this->activeTab;
        if ($activeTab && str_starts_with($activeTab, 'story_')) {
            $tabStoryId = (int) str_replace('story_', '', $activeTab);
            $storyTitle = Story::where('id', $tabStoryId)->value('title');
            if ($storyTitle) {
                return "📖 Đang xem: {$storyTitle}";
            }
        }

        return null;
    }

    // ═══════════════════════════════════════════════════════════════
    // Bulk Range Actions
    // ═══════════════════════════════════════════════════════════════

    /**
     * Header action: Set volume_number for a range of chapters.
     */
    private static function setVolumeAction(): Action
    {
        return Action::make('setVolume')
            ->label('Set Quyển')
            ->icon(Heroicon::OutlinedBookOpen)
            ->color('info')
            ->modalHeading('Gán Quyển cho hàng loạt chương')
            ->modalDescription('Chọn truyện, nhập khoảng chương và số quyển cần gán.')
            ->modalSubmitActionLabel('Cập nhật')
            ->form(self::rangeForm('Số quyển', 'Ví dụ: 2'))
            ->action(function (array $data): void {
                $result = self::updateChaptersInRange(
                    storyId: (int) $data['story_id'],
                    fromChapter: $data['from_chapter'],
                    toChapter: $data['to_chapter'],
                    column: 'volume_number',
                    value: (int) $data['value'],
                );

                Notification::make()
                    ->title('Đã gán quyển')
                    ->body("✅ Cập nhật {$result['updated']} chương → Quyển {$data['value']} (từ Ch.{$data['from_chapter']} đến Ch.{$data['to_chapter']})")
                    ->success()
                    ->send();
            });
    }

    /**
     * Header action: Set sub_chapter for a range of chapters.
     */
    private static function setSubChapterAction(): Action
    {
        return Action::make('setSubChapter')
            ->label('Set Phần')
            ->icon(Heroicon::OutlinedQueueList)
            ->color('warning')
            ->modalHeading('Gán Phần cho hàng loạt chương')
            ->modalDescription('Chọn truyện, nhập khoảng chương và số phần cần gán. (0 = chương chính, 1,2,3... = phần)')
            ->modalSubmitActionLabel('Cập nhật')
            ->form(self::rangeForm('Số phần', 'Ví dụ: 0 = chính, 1 = phần 1'))
            ->action(function (array $data): void {
                $result = self::updateChaptersInRange(
                    storyId: (int) $data['story_id'],
                    fromChapter: $data['from_chapter'],
                    toChapter: $data['to_chapter'],
                    column: 'sub_chapter',
                    value: (int) $data['value'],
                );

                $label = $data['value'] == 0 ? 'Chương chính' : "Phần {$data['value']}";

                Notification::make()
                    ->title('Đã gán phần')
                    ->body("✅ Cập nhật {$result['updated']} chương → {$label} (từ Ch.{$data['from_chapter']} đến Ch.{$data['to_chapter']})")
                    ->success()
                    ->send();
            });
    }

    // ═══════════════════════════════════════════════════════════════
    // Form Builder
    // ═══════════════════════════════════════════════════════════════

    /**
     * Build the common form for range-based chapter updates.
     *
     * @return array<mixed>
     */
    private static function rangeForm(string $valueLabel, string $valuePlaceholder): array
    {
        return [
            Select::make('story_id')
                ->label('Truyện')
                ->options(fn () => Story::query()
                    ->orderBy('title')
                    ->pluck('title', 'id'))
                ->searchable()
                ->required()
                ->live()
                ->native(false)
                ->helperText('Chọn truyện cần cập nhật'),

            Grid::make(2)
                ->schema([
                    TextInput::make('from_chapter')
                        ->label('Từ chương')
                        ->numeric()
                        ->required()
                        ->placeholder('1')
                        ->minValue(0)
                        ->live(onBlur: true),

                    TextInput::make('to_chapter')
                        ->label('Đến chương')
                        ->numeric()
                        ->required()
                        ->placeholder('100')
                        ->minValue(0)
                        ->live(onBlur: true),
                ]),

            TextInput::make('value')
                ->label($valueLabel)
                ->numeric()
                ->required()
                ->placeholder($valuePlaceholder)
                ->minValue(0),

            TextEntry::make('_preview')
                ->label('Xem trước')
                ->state(function (callable $get): string {
                    $storyId = $get('story_id');
                    $from = $get('from_chapter');
                    $to = $get('to_chapter');

                    if (!$storyId || !filled($from) || !filled($to)) {
                        return '⏳ Chọn truyện và nhập khoảng chương để xem trước.';
                    }

                    $count = Chapter::query()
                        ->where('story_id', (int) $storyId)
                        ->whereBetween('sort_key', [(float) $from, (float) $to])
                        ->count();

                    if ($count === 0) {
                        return '⚠️ Không tìm thấy chương nào trong khoảng này.';
                    }

                    return "📋 Sẽ cập nhật {$count} chương (Chương {$from} → {$to}).";
                })
                ->live(),
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    // Update Logic
    // ═══════════════════════════════════════════════════════════════

    /**
     * Update a column for all chapters in a given range.
     *
     * @return array{updated: int}
     */
    private static function updateChaptersInRange(
        int $storyId,
        string|float $fromChapter,
        string|float $toChapter,
        string $column,
        int $value,
    ): array {
        $updated = Chapter::query()
            ->where('story_id', $storyId)
            ->whereBetween('sort_key', [(float) $fromChapter, (float) $toChapter])
            ->update([$column => $value]);

        return ['updated' => $updated];
    }

}
