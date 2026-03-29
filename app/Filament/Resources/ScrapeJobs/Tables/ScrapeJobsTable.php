<?php

declare(strict_types=1);

namespace App\Filament\Resources\ScrapeJobs\Tables;

use App\Enums\ScheduleFrequency;
use App\Filament\Resources\ScrapeJobs\ScrapeJobResource;
use App\Filament\Resources\ScrapeSources\ScrapeSourceResource;
use App\Jobs\RunScrapeJob;
use App\Models\ScrapeJob;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ScrapeJobsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query
                ->with('source:id,name')
                ->withCount([
                    'items as items_total_count',
                    'items as items_imported_count' => fn ($q) => $q->whereIn('status', ['imported', 'merged']),
                    'items as items_pending_count' => fn ($q) => $q->whereIn('status', ['draft', 'selected']),
                    'items as items_error_count' => fn ($q) => $q->where('status', 'error'),
                ]))
            ->recordUrl(null)
            ->recordClasses('fi-clickable')
            ->columns([
                self::nameColumn(),
                self::sourceColumn(),
                self::entityTypeColumn(),
                self::statusColumn(),
                self::itemsCountColumn(),
                self::scheduleColumn(),
                self::autoFetchColumn(),
                self::autoImportColumn(),
                self::createdAtColumn(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('entity_type')
                    ->label('Loại')
                    ->options(
                        collect(config('scrape.entity_types', []))
                            ->mapWithKeys(fn (array $cfg, string $key) => [$key => $cfg['icon'] . ' ' . $cfg['label']])
                            ->toArray()
                    ),
                SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options([
                        'draft' => 'Nháp',
                        'scraping' => 'Đang thu thập',
                        'scraped' => 'Đã thu thập',
                        'importing' => 'Đang import',
                        'done' => 'Hoàn tất',
                        'failed' => 'Lỗi',
                    ]),
                SelectFilter::make('source_id')
                    ->label('Nguồn')
                    ->relationship('source', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->filtersFormColumns(3)
            ->recordActions([
                ActionGroup::make([
                    Action::make('startScrape')
                        ->label('Thu thập')
                        ->icon(Heroicon::OutlinedPlay)
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Bắt đầu thu thập dữ liệu?')
                        ->modalDescription('Hệ thống sẽ thu thập dữ liệu ngay. Vui lòng chờ.')
                        ->visible(fn ($record) => in_array($record->status, [
                            ScrapeJob::STATUS_DRAFT,
                            ScrapeJob::STATUS_FAILED,
                        ]))
                        ->action(function ($record) {
                            try {
                                $record->markScraping();
                                RunScrapeJob::dispatchWithWorker($record);

                                Notification::make()
                                    ->title('Đã bắt đầu thu thập')
                                    ->body('Job đang chạy nền. Kiểm tra chi tiết ở trang xem.')
                                    ->success()
                                    ->send();
                            } catch (\Throwable $e) {
                                $record->markFailed($e->getMessage());

                                Notification::make()
                                    ->title('Thu thập thất bại')
                                    ->body($e->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        }),
                    ViewAction::make()
                        ->label('Xem')
                        ->icon(Heroicon::OutlinedEye)
                        ->color('info'),
                    EditAction::make()
                        ->label('Sửa')
                        ->icon(Heroicon::OutlinedPencilSquare)
                        ->color('primary'),
                    Action::make('cloneJob')
                        ->label('Clone')
                        ->icon(Heroicon::OutlinedDocumentDuplicate)
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Clone tác vụ?')
                        ->modalDescription(fn ($record) => "Tạo bản sao của \"{$record->name}\" với toàn bộ cấu hình. Items sẽ không được clone.")
                        ->action(function ($record) {
                            $clone = $record->replicate([
                                'status', 'detail_status', 'error_log',
                                'current_page', 'total_pages',
                                'detail_fetched', 'detail_total',
                                'last_scheduled_at',
                                'created_at', 'updated_at',
                                'items_count', // virtual from withCount
                                // withCount aliases from modifyQueryUsing — must exclude or save() fails
                                'items_total_count',
                                'items_imported_count',
                                'items_pending_count',
                                'items_error_count',
                            ]);
                            $clone->name = $record->name . ' (Copy)';
                            $clone->status = ScrapeJob::STATUS_DRAFT;
                            $clone->current_page = 0;
                            $clone->total_pages = 0;
                            $clone->detail_fetched = 0;
                            $clone->detail_total = 0;

                            // Chapter types: also clear parent story
                            if (in_array($record->entity_type, [ScrapeJob::ENTITY_CHAPTER, ScrapeJob::ENTITY_CHAPTER_DETAIL])) {
                                $clone->parent_story_id = null;
                            }

                            $clone->save();

                            Notification::make()
                                ->title('Đã clone tác vụ')
                                ->body("Tác vụ \"{$clone->name}\" đã được tạo.")
                                ->success()
                                ->send();

                            return redirect(ScrapeJobResource::getUrl('edit', ['record' => $clone]));
                        }),
                    Action::make('scheduleJob')
                        ->label('Lên lịch')
                        ->icon(Heroicon::OutlinedClock)
                        ->color('info')
                        ->modalHeading('Cài đặt lịch chạy tự động')
                        ->modalWidth('md')
                        ->schema([
                            Toggle::make('is_scheduled')
                                ->label('Bật chạy tự động')
                                ->helperText('Tác vụ sẽ chạy theo lịch đã cài đặt.'),
                            Select::make('schedule_frequency')
                                ->label('Tần suất')
                                ->options(ScheduleFrequency::options())
                                ->visible(fn ($get) => $get('is_scheduled'))
                                ->required(fn ($get) => $get('is_scheduled')),
                            TextInput::make('fetch_batch_size')
                                ->label('Số chương mỗi batch')
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(100)
                                ->helperText('Số chương fetch mỗi lần chạy scheduled.'),
                            Toggle::make('auto_fetch_content')
                                ->label('Tự động fetch nội dung chương')
                                ->helperText('Tự động lấy nội dung từng chương sau khi thu thập mục lục.'),
                            Toggle::make('auto_import')
                                ->label('Tự động import sau khi thu thập')
                                ->helperText('Tự động import các chương đã fetch vào database.'),
                        ])
                        ->fillForm(function ($record) {
                            $config = $record->detail_config ?? [];

                            return [
                                'is_scheduled'       => $record->is_scheduled,
                                'schedule_frequency' => $record->schedule_frequency,
                                'fetch_batch_size'   => $config['fetch_batch_size'] ?? 20,
                                'auto_fetch_content' => $config['auto_fetch_content'] ?? true,
                                'auto_import'        => $record->auto_import,
                            ];
                        })
                        ->action(function ($record, array $data) {
                            $config = $record->detail_config ?? [];
                            $config['fetch_batch_size'] = (int) ($data['fetch_batch_size'] ?? 20);
                            $config['auto_fetch_content'] = $data['auto_fetch_content'] ?? true;

                            $record->update([
                                'is_scheduled'       => $data['is_scheduled'],
                                'schedule_frequency' => $data['is_scheduled'] ? $data['schedule_frequency'] : $record->schedule_frequency,
                                'auto_import'        => $data['auto_import'],
                                'detail_config'      => $config,
                            ]);

                            $status = $data['is_scheduled'] ? 'Đã bật' : 'Đã tắt';

                            Notification::make()
                                ->title("{$status} lịch chạy tự động")
                                ->success()
                                ->send();
                        }),
                    DeleteAction::make()
                        ->label('Xóa')
                        ->icon(Heroicon::OutlinedTrash)
                        ->color('danger'),
                ])
                    ->icon(Heroicon::OutlinedEllipsisVertical)
                    ->tooltip('Hành động')
                    ->dropdownPlacement('bottom-end'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->label('Xóa')
                        ->icon(Heroicon::OutlinedTrash)
                        ->color('danger'),
                ]),
            ])
            ->recordClasses(function ($record) {
                $total = (int) ($record->items_total_count ?? 0);
                if ($total === 0) {
                    return '';
                }

                $imported = (int) ($record->items_imported_count ?? 0);

                return $imported === $total
                    ? 'bg-success-50 dark:bg-success-950'
                    : '';
            })
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([10, 25, 50]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Columns
    // ═══════════════════════════════════════════════════════════════════════

    private static function nameColumn(): TextColumn
    {
        return TextColumn::make('name')
            ->label('Tên')
            ->searchable()
            ->sortable()
            ->weight('bold')
            ->url(fn ($record) => ScrapeJobResource::getUrl('view', ['record' => $record]));
    }

    private static function sourceColumn(): TextColumn
    {
        return TextColumn::make('source.name')
            ->label('Nguồn')
            ->sortable()
            ->url(fn ($record) => $record->source_id
                ? ScrapeSourceResource::getUrl('edit', ['record' => $record->source_id])
                : null
            );
    }

    private static function entityTypeColumn(): TextColumn
    {
        return TextColumn::make('entity_type')
            ->label('Loại')
            ->badge()
            ->color(fn (string $state) => config("scrape.entity_types.{$state}.color", 'gray'))
            ->formatStateUsing(function (string $state): string {
                $cfg = config("scrape.entity_types.{$state}");

                return $cfg
                    ? $cfg['icon'] . ' ' . $cfg['label']
                    : $state;
            });
    }

    private static function statusColumn(): TextColumn
    {
        return TextColumn::make('status')
            ->label('Trạng thái')
            ->badge()
            ->state(function ($record) {
                $total = (int) ($record->items_total_count ?? 0);

                if ($total === 0) {
                    return $record->status;
                }

                $hasPending = ((int) ($record->items_pending_count ?? 0)) > 0;
                $hasError = ((int) ($record->items_error_count ?? 0)) > 0;

                // Status=done but still has pending or error items → should be scraped
                if ($record->status === 'done' && ($hasPending || $hasError)) {
                    $record->updateQuietly(['status' => 'scraped']);

                    return 'scraped';
                }

                // Status=scraped/draft but all items are imported (no pending, no errors) → should be done
                if (in_array($record->status, ['scraped', 'draft']) && ! $hasPending && ! $hasError) {
                    $record->updateQuietly(['status' => 'done']);

                    return 'done';
                }

                // Status=draft but has imported items → scheduled run reset, show as scraped
                if ($record->status === 'draft' && $hasPending) {
                    $imported = (int) ($record->items_imported_count ?? 0);

                    if ($imported > 0) {
                        $record->updateQuietly(['status' => 'scraped']);

                        return 'scraped';
                    }
                }

                return $record->status;
            })
            ->color(fn (string $state) => match ($state) {
                'draft' => 'gray',
                'scraping' => 'warning',
                'scraped' => 'info',
                'importing' => 'primary',
                'done' => 'success',
                'failed' => 'danger',
                default => 'gray',
            });
    }

    private static function itemsCountColumn(): TextColumn
    {
        return TextColumn::make('items_count')
            ->label('Mục')
            ->state(function ($record) {
                $total = (int) ($record->items_total_count ?? 0);
                if ($total === 0) {
                    return '0';
                }

                $imported = (int) ($record->items_imported_count ?? 0);

                if ($imported === 0) {
                    return (string) $total;
                }

                if ($imported === $total) {
                    return "✅ {$total}";
                }

                return "{$imported}/{$total}";
            })
            ->sortable()
            ->alignCenter()
            ->badge()
            ->color(function ($record) {
                $total = (int) ($record->items_total_count ?? 0);
                if ($total === 0) {
                    return 'gray';
                }

                $imported = (int) ($record->items_imported_count ?? 0);

                if ($imported === $total) {
                    return 'success';
                }

                return $imported > 0 ? 'info' : 'gray';
            });
    }

    private static function createdAtColumn(): TextColumn
    {
        return TextColumn::make('created_at')
            ->label('Tạo lúc')
            ->dateTime('d/m/Y H:i')
            ->sortable()
            ->toggleable(isToggledHiddenByDefault: true);
    }

    private static function autoFetchColumn(): IconColumn
    {
        return IconColumn::make('auto_fetch')
            ->label('Fetch tự động')
            ->state(function ($record) {
                if ($record->entity_type !== ScrapeJob::ENTITY_CHAPTER) {
                    return null;
                }

                // Only show as active if detail config is actually configured
                if (! $record->hasDetailConfig()) {
                    return false;
                }

                $config = $record->detail_config ?? [];

                return ($config['auto_fetch_content'] ?? true) === true;
            })
            ->boolean()
            ->trueIcon(Heroicon::CheckCircle)
            ->falseIcon(Heroicon::XCircle)
            ->trueColor('success')
            ->falseColor('gray')
            ->alignCenter();
    }

    private static function autoImportColumn(): IconColumn
    {
        return IconColumn::make('auto_import')
            ->label('Import tự động')
            ->boolean()
            ->trueIcon(Heroicon::CheckCircle)
            ->falseIcon(Heroicon::XCircle)
            ->trueColor('success')
            ->falseColor('gray')
            ->alignCenter();
    }

    private static function scheduleColumn(): IconColumn
    {
        return IconColumn::make('is_scheduled')
            ->label('Lịch')
            ->icon(function ($record) {
                if (! $record->is_scheduled) {
                    return Heroicon::OutlinedMinus;
                }

                $total = (int) ($record->items_total_count ?? 0);
                $imported = (int) ($record->items_imported_count ?? 0);

                if ($total > 0 && $imported === $total) {
                    return Heroicon::OutlinedCheckCircle;
                }

                return Heroicon::OutlinedClock;
            })
            ->color(function ($record) {
                if (! $record->is_scheduled) {
                    return 'gray';
                }

                $total = (int) ($record->items_total_count ?? 0);
                $imported = (int) ($record->items_imported_count ?? 0);

                if ($total > 0 && $imported === $total) {
                    return 'warning';
                }

                return 'success';
            })
            ->tooltip(function ($record) {
                if (! $record->is_scheduled) {
                    return 'Không có lịch';
                }

                $freq = ScheduleFrequency::tryFrom($record->schedule_frequency)?->label() ?? $record->schedule_frequency;
                $parts = [$freq];

                // Completion status — uses pre-computed counts
                $total = (int) ($record->items_total_count ?? 0);
                $imported = (int) ($record->items_imported_count ?? 0);

                if ($total > 0) {
                    if ($imported === $total) {
                        $parts[] = "Đã import hết — chờ chương mới";
                    } elseif ($imported > 0) {
                        $parts[] = "{$imported}/{$total} imported";
                    }
                }

                return implode(' · ', $parts);
            })
            ->alignCenter();
    }
}
