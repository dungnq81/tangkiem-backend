<?php

declare(strict_types=1);

namespace App\Filament\Resources\Stories\Tables;

use App\Enums\StoryOrigin;
use App\Filament\Resources\Chapters\ChapterResource;
use App\Filament\Resources\Stories\StoryResource;
use App\Enums\StoryStatus;

use App\Models\Story;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Support\Enums\Width;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;
use App\Support\Curator\SafeGlideUrlProvider;

class StoriesTable
{
    // ═══════════════════════════════════════════════════════════════════════
    // Main Configuration
    // ═══════════════════════════════════════════════════════════════════════

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query
                // Only select columns needed for table display.
                // Avoids loading content (longtext), description (text),
                // alternative_titles (json), meta_* fields — saves ~60KB+ per page.
                ->select([
                    'id', 'title', 'slug', 'author_id', 'cover_image_id',
                    'status', 'origin', 'is_published', 'is_featured',
                    'is_hot', 'is_vip', 'is_locked', 'total_chapters',
                    'view_count', 'rating', 'rating_count',
                    'last_chapter_at', 'created_at', 'deleted_at',
                ])
                ->with(['categories:id,name,color', 'coverImage:id,path', 'author:id,name'])
                ->withCount([
                    'chapters as draft_chapters_count' => fn ($q) => $q->where('is_published', false),
                ]))
            ->recordUrl(null)
            ->recordClasses('fi-clickable')
            ->columns(self::columns())
            ->filters(self::filters(), layout: FiltersLayout::Dropdown)
            ->filtersFormColumns(4)
            ->filtersFormWidth(Width::SixExtraLarge)
            ->columnToggleFormColumns(3)
            ->recordActions(self::recordActions())
            ->toolbarActions(self::toolbarActions())
            ->striped()
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([25, 50, 100])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Không có truyện nào')
            ->emptyStateDescription('Thử thay đổi bộ lọc hoặc tạo truyện mới.');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Columns
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * @return array<int, \Filament\Tables\Columns\Column>
     */
    private static function columns(): array
    {
        return [
            self::coverImageColumn(),
            self::titleColumn(),
            self::categoriesColumn(),
            self::statusColumn(),
            self::originColumn(),
            self::chaptersColumn(),
            self::viewCountColumn(),
            self::ratingColumn(),
            self::isPublishedColumn(),
            self::isFeaturedColumn(),
            self::isHotColumn(),
            self::isVipColumn(),
            self::isLockedColumn(),
            self::lastChapterAtColumn(),
            self::createdAtColumn(),
        ];
    }

    private static function coverImageColumn(): ImageColumn
    {
        return ImageColumn::make('coverImage')
            ->label('🖼️')
            ->imageWidth(50)
            ->imageHeight(75)
            ->getStateUsing(function (Story $record): ?string {
                $media = $record->coverImage;
                if (! $media) {
                    return null;
                }

                return url(SafeGlideUrlProvider::getUrl($media->path, 200, 300));
            })
            ->checkFileExistence(false)
            ->visibility('public')
            ->url(fn ($record) => StoryResource::getUrl('edit', ['record' => $record]))
            ->extraAttributes(['class' => ''])
            ->alignCenter();
    }

    private static function titleColumn(): TextColumn
    {
        return TextColumn::make('title')
            ->label('Tiêu đề')
            ->searchable()
            ->sortable()
            ->weight('bold')
            ->limit(50)
            ->url(fn ($record) => StoryResource::getUrl('edit', ['record' => $record]))
            ->description(function (Story $record): ?HtmlString {
                $parts = [];

                // Author name
                if ($record->author?->name) {
                    $parts[] = $record->author->name;
                }

                // Stats for completed stories (uses denormalized total_chapters column)
                if ($record->status === StoryStatus::COMPLETED) {
                    $totalChapters = (int) ($record->total_chapters ?? 0);
                    if ($totalChapters > 0) {
                        $fmtChapters = number_format($totalChapters, 0);
                        $parts[] = "<span class='text-primary-500 dark:text-primary-400 font-medium'>✅ {$fmtChapters} chương</span>";
                    }
                }

                return ! empty($parts)
                    ? new HtmlString(implode(' · ', $parts))
                    : null;
            });
    }

    private static function categoriesColumn(): TextColumn
    {
        return TextColumn::make('categories')
            ->label('Thể loại')
            ->getStateUsing(fn (Story $record) => self::renderCategoryBadges($record))
            ->html();
    }

    private static function statusColumn(): TextColumn
    {
        return TextColumn::make('status')
            ->label('Trạng thái')
            ->badge()
            ->formatStateUsing(fn (StoryStatus $state): string => $state->label())
            ->color(fn (StoryStatus $state): string => $state->color())
            ->sortable();
    }

    private static function originColumn(): TextColumn
    {
        return TextColumn::make('origin')
            ->label('Nguồn')
            ->formatStateUsing(fn (StoryOrigin $state): string =>
                $state->flag() . ' ' . $state->label())
            ->toggleable(isToggledHiddenByDefault: true);
    }

    private static function chaptersColumn(): TextColumn
    {
        // Uses denormalized total_chapters + single withCount for drafts.
        // Removed: deleted_chapters_count (scans trashed — expensive),
        //          chapters_max_volume_number (withMax subquery),
        //          distinct_parts_count (correlated subquery with COUNT DISTINCT).
        return TextColumn::make('total_chapters')
            ->label('Chương')
            ->sortable()
            ->formatStateUsing(function ($state, Story $record): string {
                // total_chapters (denormalized) = published only
                $published = (int) ($state ?? 0);
                $drafts = (int) ($record->draft_chapters_count ?? 0);
                $total = $published + $drafts;

                return $drafts > 0
                    ? number_format($published, 0) . ' / ' . number_format($total, 0)
                    : number_format($total, 0);
            })
            ->description(function (Story $record): ?HtmlString {
                $drafts = (int) ($record->draft_chapters_count ?? 0);

                if ($drafts > 0) {
                    $fmtDrafts = number_format($drafts, 0);

                    return new HtmlString("<span class='text-[10px] opacity-60'>📝 {$fmtDrafts} nháp</span>");
                }

                return null;
            })
            ->badge()
            ->url(fn (Story $record): string => self::getChaptersFilterUrl($record->id))
            ->tooltip('Xem danh sách chương');
    }

    private static function viewCountColumn(): TextColumn
    {
        return TextColumn::make('view_count')
            ->label('Lượt xem')
            ->numeric()
            ->sortable()
            ->alignCenter()
            ->formatStateUsing(fn ($state) => number_format($state))
            ->color('gray')
            ->toggleable(isToggledHiddenByDefault: true);
    }

    private static function ratingColumn(): TextColumn
    {
        return TextColumn::make('rating')
            ->label('Đánh giá')
            ->formatStateUsing(fn ($state, Story $record) =>
                $state > 0 ? "⭐ {$state} ({$record->rating_count})" : '-')
            ->alignCenter()
            ->toggleable(isToggledHiddenByDefault: true);
    }

    private static function isPublishedColumn(): IconColumn
    {
        return IconColumn::make('is_published')
            ->label('Xuất bản')
            ->boolean()
            ->trueIcon(Heroicon::CheckCircle)
            ->falseIcon(Heroicon::OutlinedXCircle)
            ->trueColor('success')
            ->falseColor('danger')
            ->sortable()
            ->alignCenter()
            ->tooltip(fn ($record) => $record->is_published ? 'Đã xuất bản' : 'Chưa xuất bản');
    }

    private static function isFeaturedColumn(): IconColumn
    {
        return IconColumn::make('is_featured')
            ->label('Nổi bật')
            ->boolean()
            ->trueIcon(Heroicon::Star)
            ->falseIcon(Heroicon::OutlinedStar)
            ->trueColor('warning')
            ->falseColor('gray')
            ->sortable()
            ->alignCenter()
            ->tooltip(fn ($record) => $record->is_featured ? 'Truyện nổi bật' : 'Truyện thường');
    }

    private static function isHotColumn(): IconColumn
    {
        return IconColumn::make('is_hot')
            ->label('Hot')
            ->boolean()
            ->trueIcon(Heroicon::Fire)
            ->falseIcon(Heroicon::OutlinedFire)
            ->trueColor('danger')
            ->falseColor('gray')
            ->tooltip(fn ($record) => $record->is_hot ? 'Truyện Hot' : 'Không phải Hot');
    }

    private static function isVipColumn(): IconColumn
    {
        return IconColumn::make('is_vip')
            ->label('VIP')
            ->boolean()
            ->trueIcon(Heroicon::Sparkles)
            ->falseIcon(Heroicon::OutlinedSparkles)
            ->trueColor('warning')
            ->falseColor('gray')
            ->sortable()
            ->alignCenter()
            ->tooltip(fn ($record) => $record->is_vip ? 'Truyện VIP' : 'Truyện miễn phí');
    }

    private static function isLockedColumn(): IconColumn
    {
        return IconColumn::make('is_locked')
            ->label('Khóa')
            ->boolean()
            ->trueIcon(Heroicon::LockClosed)
            ->falseIcon(Heroicon::OutlinedLockOpen)
            ->trueColor('danger')
            ->falseColor('gray')
            ->sortable()
            ->alignCenter()
            ->tooltip(fn ($record) => $record->is_locked ? 'Đã khóa' : 'Không khóa')
            ->toggleable(isToggledHiddenByDefault: true);
    }

    private static function lastChapterAtColumn(): TextColumn
    {
        return TextColumn::make('last_chapter_at')
            ->label('Cập nhật')
            ->dateTime('d/m/Y H:i')
            ->sortable()
            ->toggleable(isToggledHiddenByDefault: true);
    }

    private static function createdAtColumn(): TextColumn
    {
        return TextColumn::make('created_at')
            ->label('Ngày tạo')
            ->dateTime('d/m/Y')
            ->sortable()
            ->toggleable(isToggledHiddenByDefault: true);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Filters
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * @return array<int, \Filament\Tables\Filters\BaseFilter>
     */
    private static function filters(): array
    {
        return [
            SelectFilter::make('status')
                ->label('Trạng thái')
                ->options(StoryStatus::options())
                ->native(false),



            SelectFilter::make('origin')
                ->label('Nguồn gốc')
                ->options(StoryOrigin::options())
                ->native(false),

            SelectFilter::make('categories')
                ->label('Thể loại')
                ->relationship('categories', 'name')
                ->multiple()
                ->searchable(),

            SelectFilter::make('author_id')
                ->label('Tác giả')
                ->relationship('author', 'name')
                ->searchable(),

            TernaryFilter::make('is_published')
                ->label('Xuất bản')
                ->placeholder('Tất cả')
                ->trueLabel('Đã xuất bản')
                ->falseLabel('Chưa xuất bản'),

            TernaryFilter::make('is_featured')
                ->label('Nổi bật')
                ->placeholder('Tất cả')
                ->trueLabel('Nổi bật')
                ->falseLabel('Thường'),

            TernaryFilter::make('is_hot')
                ->label('Hot')
                ->placeholder('Tất cả')
                ->trueLabel('Hot')
                ->falseLabel('Không hot'),

            TernaryFilter::make('is_vip')
                ->label('VIP')
                ->placeholder('Tất cả')
                ->trueLabel('VIP')
                ->falseLabel('Miễn phí'),

            TernaryFilter::make('is_locked')
                ->label('Khóa')
                ->placeholder('Tất cả')
                ->trueLabel('Đã khóa')
                ->falseLabel('Không khóa'),

            TrashedFilter::make()
                ->label('Thùng rác')
                ->placeholder('Chưa xóa')
                ->trueLabel('Tất cả')
                ->falseLabel('Chỉ đã xóa'),
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Record Actions (per-row)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * @return array<int, ActionGroup|Action>
     */
    private static function recordActions(): array
    {
        return [
            ActionGroup::make([
                self::editRecordAction(),
                self::changeStatusAction(),
                self::changeCategoriesAction(),
                self::publishAction(),
                self::unpublishAction(),
                self::toggleFeaturedAction(),
                self::toggleHotAction(),
                self::toggleVipAction(),
                self::toggleLockedAction(),
                RestoreAction::make()
                    ->label('Khôi phục')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('info'),
                DeleteAction::make()
                    ->label('Cho vào thùng rác')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('warning')
                    ->modalHeading('Cho vào thùng rác')
                    ->modalDescription('Truyện sẽ được chuyển vào thùng rác. Bạn có thể khôi phục sau.'),
                ForceDeleteAction::make()
                    ->label('Xóa vĩnh viễn')
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('danger')
                    ->modalHeading('Xóa vĩnh viễn')
                    ->modalDescription('Truyện sẽ bị xóa HOÀN TOÀN khỏi hệ thống. Hành động này không thể hoàn tác!'),
            ])
                ->icon(Heroicon::OutlinedEllipsisVertical)
                ->tooltip('Hành động')
                ->dropdownPlacement('bottom-end'),
        ];
    }

    private static function editRecordAction(): EditAction
    {
        return EditAction::make()
            ->label('Chỉnh sửa')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('primary');
    }

    private static function changeStatusAction(): Action
    {
        return Action::make('changeStatus')
            ->label('Đổi trạng thái')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('info')
            ->visible(fn ($record) => !$record->trashed())
            ->fillForm(fn ($record) => ['status' => $record->status->value])
            ->form([
                Select::make('status')
                    ->label('Trạng thái mới')
                    ->options(StoryStatus::options())
                    ->required()
                    ->native(false),
            ])
            ->action(function (Story $record, array $data): void {
                $record->update(['status' => $data['status']]);
            })
            ->successNotificationTitle('Đã cập nhật trạng thái');
    }

    private static function changeCategoriesAction(): Action
    {
        return Action::make('changeCategories')
            ->label('Đổi thể loại')
            ->icon(Heroicon::OutlinedTag)
            ->color('info')
            ->visible(fn ($record) => !$record->trashed())
            ->fillForm(fn ($record) => ['categories' => $record->categories->pluck('id')->toArray()])
            ->form([
                Select::make('categories')
                    ->label('Thể loại')
                    ->relationship('categories', 'name')
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->required(),
            ])
            ->action(function (Story $record, array $data): void {
                $record->categories()->sync($data['categories']);
            })
            ->successNotificationTitle('Đã cập nhật thể loại');
    }

    private static function publishAction(): Action
    {
        return Action::make('publish')
            ->label('Xuất bản')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn ($record) => !$record->trashed() && !$record->is_published)
            ->requiresConfirmation()
            ->modalHeading('Xuất bản truyện')
            ->modalDescription('Bạn có chắc muốn xuất bản truyện này?')
            ->action(fn ($record) => $record->update(['is_published' => true, 'published_at' => now()]));
    }

    private static function unpublishAction(): Action
    {
        return Action::make('unpublish')
            ->label('Hủy xuất bản')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('warning')
            ->visible(fn ($record) => !$record->trashed() && $record->is_published)
            ->requiresConfirmation()
            ->modalHeading('Hủy xuất bản truyện')
            ->modalDescription('Bạn có chắc muốn hủy xuất bản truyện này?')
            ->action(fn ($record) => $record->update(['is_published' => false]));
    }

    private static function toggleFeaturedAction(): Action
    {
        return Action::make('toggle_featured')
            ->label(fn ($record) => $record->is_featured ? 'Bỏ nổi bật' : 'Đánh dấu nổi bật')
            ->icon(Heroicon::OutlinedStar)
            ->color('warning')
            ->visible(fn ($record) => !$record->trashed())
            ->action(fn ($record) => $record->update(['is_featured' => !$record->is_featured]));
    }

    private static function toggleHotAction(): Action
    {
        return Action::make('toggle_hot')
            ->label(fn ($record) => $record->is_hot ? 'Bỏ Hot' : 'Đánh dấu Hot')
            ->icon(Heroicon::OutlinedFire)
            ->color('danger')
            ->visible(fn ($record) => !$record->trashed())
            ->action(fn ($record) => $record->update(['is_hot' => !$record->is_hot]));
    }

    private static function toggleVipAction(): Action
    {
        return Action::make('toggle_vip')
            ->label(fn ($record) => $record->is_vip ? 'Bỏ VIP' : 'Đánh dấu VIP')
            ->icon(Heroicon::OutlinedSparkles)
            ->color('warning')
            ->visible(fn ($record) => !$record->trashed())
            ->action(fn ($record) => $record->update(['is_vip' => !$record->is_vip]));
    }

    private static function toggleLockedAction(): Action
    {
        return Action::make('toggle_locked')
            ->label(fn ($record) => $record->is_locked ? 'Mở khóa' : 'Khóa truyện')
            ->icon(Heroicon::OutlinedLockClosed)
            ->color(fn ($record) => $record->is_locked ? 'success' : 'danger')
            ->visible(fn ($record) => !$record->trashed())
            ->requiresConfirmation()
            ->action(fn ($record) => $record->update(['is_locked' => !$record->is_locked]));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Toolbar Actions (bulk)
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * @return array<int, BulkActionGroup>
     */
    private static function toolbarActions(): array
    {
        return [
            BulkActionGroup::make([
                self::bulkPublish(),
                self::bulkUnpublish(),
                self::bulkFeature(),
                self::bulkUnfeature(),
                self::bulkSetHot(),
                self::bulkRemoveHot(),
                self::bulkSetVip(),
                self::bulkRemoveVip(),
                self::bulkLock(),
                self::bulkUnlock(),
                DeleteBulkAction::make()
                    ->label('Cho vào thùng rác')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('warning'),
                ForceDeleteBulkAction::make()
                    ->label('Xóa vĩnh viễn')
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('danger')
                    ->modalDescription('Các truyện được chọn sẽ bị xóa HOÀN TOÀN. Không thể hoàn tác!'),
                RestoreBulkAction::make()
                    ->label('Khôi phục')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('info'),
            ]),
        ];
    }

    private static function bulkPublish(): BulkAction
    {
        return BulkAction::make('publishAll')
            ->label('Xuất bản')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Xuất bản các truyện đã chọn')
            ->action(fn (Collection $records) => $records->each->update([
                'is_published' => true,
                'published_at' => now(),
            ]))
            ->deselectRecordsAfterCompletion();
    }

    private static function bulkUnpublish(): BulkAction
    {
        return BulkAction::make('unpublishAll')
            ->label('Hủy xuất bản')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Hủy xuất bản các truyện đã chọn')
            ->action(fn (Collection $records) => $records->each->update(['is_published' => false]))
            ->deselectRecordsAfterCompletion();
    }

    private static function bulkFeature(): BulkAction
    {
        return BulkAction::make('featureAll')
            ->label('Đánh dấu nổi bật')
            ->icon(Heroicon::Star)
            ->color('warning')
            ->requiresConfirmation()
            ->action(fn (Collection $records) => $records->each->update(['is_featured' => true]))
            ->deselectRecordsAfterCompletion();
    }

    private static function bulkUnfeature(): BulkAction
    {
        return BulkAction::make('unfeatureAll')
            ->label('Bỏ nổi bật')
            ->icon(Heroicon::OutlinedStar)
            ->color('gray')
            ->requiresConfirmation()
            ->action(fn (Collection $records) => $records->each->update(['is_featured' => false]))
            ->deselectRecordsAfterCompletion();
    }

    private static function bulkSetHot(): BulkAction
    {
        return BulkAction::make('setHot')
            ->label('Đánh dấu Hot')
            ->icon(Heroicon::Fire)
            ->color('danger')
            ->requiresConfirmation()
            ->action(fn (Collection $records) => $records->each->update(['is_hot' => true]))
            ->deselectRecordsAfterCompletion();
    }

    private static function bulkRemoveHot(): BulkAction
    {
        return BulkAction::make('removeHot')
            ->label('Bỏ Hot')
            ->icon(Heroicon::OutlinedFire)
            ->color('gray')
            ->requiresConfirmation()
            ->action(fn (Collection $records) => $records->each->update(['is_hot' => false]))
            ->deselectRecordsAfterCompletion();
    }

    private static function bulkSetVip(): BulkAction
    {
        return BulkAction::make('setVip')
            ->label('Đánh dấu VIP')
            ->icon(Heroicon::Sparkles)
            ->color('warning')
            ->requiresConfirmation()
            ->action(fn (Collection $records) => $records->each->update(['is_vip' => true]))
            ->deselectRecordsAfterCompletion();
    }

    private static function bulkRemoveVip(): BulkAction
    {
        return BulkAction::make('removeVip')
            ->label('Bỏ VIP')
            ->icon(Heroicon::OutlinedSparkles)
            ->color('gray')
            ->requiresConfirmation()
            ->action(fn (Collection $records) => $records->each->update(['is_vip' => false]))
            ->deselectRecordsAfterCompletion();
    }

    private static function bulkLock(): BulkAction
    {
        return BulkAction::make('lockStories')
            ->label('Khóa truyện')
            ->icon(Heroicon::LockClosed)
            ->color('danger')
            ->requiresConfirmation()
            ->action(fn (Collection $records) => $records->each->update(['is_locked' => true]))
            ->deselectRecordsAfterCompletion();
    }

    private static function bulkUnlock(): BulkAction
    {
        return BulkAction::make('unlockStories')
            ->label('Mở khóa')
            ->icon(Heroicon::OutlinedLockOpen)
            ->color('success')
            ->requiresConfirmation()
            ->action(fn (Collection $records) => $records->each->update(['is_locked' => false]))
            ->deselectRecordsAfterCompletion();
    }

    // ═══════════════════════════════════════════════════════════════════════
    // URL Helpers
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Generate URL to Stories page filtered by category.
     */
    private static function getCategoryFilterUrl(int $categoryId): string
    {
        $baseUrl = once(fn () => StoryResource::getUrl('index'));
        $query = http_build_query([
            'filters' => [
                'categories' => ['values' => [$categoryId]],
            ],
        ]);

        return "{$baseUrl}?{$query}";
    }

    /**
     * Generate URL to Chapters page filtered by story.
     */
    private static function getChaptersFilterUrl(int $storyId): string
    {
        $baseUrl = once(fn () => ChapterResource::getUrl('index'));
        $query = http_build_query([
            'filters' => [
                'story_id' => ['value' => $storyId],
            ],
        ]);

        return "{$baseUrl}?{$query}";
    }

    /**
     * Render category badges with filter links.
     */
    private static function renderCategoryBadges(Story $record): HtmlString
    {
        if ($record->categories->isEmpty()) {
            return new HtmlString('<span class="text-gray-400">-</span>');
        }

        $badges = $record->categories->map(fn ($cat) => sprintf(
            '<a href="%s" class="fi-badge no-underline inline-flex items-center rounded-md text-xs font-medium ring-1 ring-inset px-1.5 py-0.5 hover:opacity-80" style="background:%s33;color:%s;border-color:%s66">%s</a>',
            self::getCategoryFilterUrl($cat->id),
            $cat->color ?: '#6366f1',
            $cat->color ?: '#6366f1',
            $cat->color ?: '#6366f1',
            e($cat->name)
        ))->implode('');

        return new HtmlString('<div class="flex flex-wrap gap-1">' . $badges . '</div>');
    }
}
