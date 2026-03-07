<?php

declare(strict_types=1);

namespace App\Filament\Resources\ScrapeJobs\RelationManagers;

use App\Models\Chapter;
use App\Models\ScrapeItem;
use App\Services\Scraper\ScrapeImporter;
use App\Services\Scraper\ScraperService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Livewire\Attributes\On;
use Livewire\Component;

class ScrapeItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Kết quả thu thập';

    protected static ?string $modelLabel = 'Item';

    /**
     * Hide the relation manager table for chapter_detail entity type.
     * Chapter detail content is reviewed/edited via the ChapterDetailPreviewWidget.
     */
    public static function canViewForRecord(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->entity_type !== \App\Models\ScrapeJob::ENTITY_CHAPTER_DETAIL;
    }

    #[On('scrape-items-updated')]
    public function refreshItems(): void
    {
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                self::titleColumn(),
                self::chapterNumberColumn(),
                self::volumeColumn(),
                self::subChapterColumn(),
                self::sourceUrlColumn(),
                self::statusColumn(),
                self::contentStatusColumn(),
                self::pageNumberColumn(),
                self::errorColumn(),
            ])
            ->defaultSort('page_number')
            ->filters([
                SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options([
                        'draft' => '⚪ Nháp',
                        'selected' => '✅ Đã chọn',
                        'imported' => '🟢 Đã import',
                        'merged' => '🔄 Đã gộp',
                        'skipped' => '🔴 Bỏ qua',
                        'error' => '❌ Lỗi',
                    ])
                    ->default('draft')
                    ->label('Lọc trạng thái'),
                SelectFilter::make('page_number')
                    ->label('Trang')
                    ->options(function () {
                        $pages = $this->getOwnerRecord()
                            ->items()
                            ->distinct()
                            ->pluck('page_number')
                            ->sort();

                        return $pages->mapWithKeys(fn ($p) => [$p => "Trang {$p}"])->toArray();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('selectItems')
                        ->label('Chọn để import')
                        ->color('success')
                        ->icon(Heroicon::OutlinedCheckCircle)
                        ->action(function ($records) {
                            $count = $records->count();
                            ScrapeItem::whereIn('id', $records->pluck('id'))
                                ->update(['status' => ScrapeItem::STATUS_SELECTED]);

                            Notification::make()
                                ->title("Đã chọn {$count} items")
                                ->success()
                                ->send();

                            $this->dispatch('scrape-items-updated');
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('skipItems')
                        ->label('Bỏ qua')
                        ->color('danger')
                        ->icon(Heroicon::OutlinedXCircle)
                        ->action(function ($records) {
                            $count = $records->count();
                            ScrapeItem::whereIn('id', $records->pluck('id'))
                                ->update(['status' => ScrapeItem::STATUS_SKIPPED]);

                            Notification::make()
                                ->title("Đã bỏ qua {$count} items")
                                ->warning()
                                ->send();

                            $this->dispatch('scrape-items-updated');
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('resetToDraft')
                        ->label('Reset về nháp')
                        ->color('gray')
                        ->icon(Heroicon::OutlinedArrowPath)
                        ->action(function ($records) {
                            ScrapeItem::whereIn('id', $records->pluck('id'))
                                ->update(['status' => ScrapeItem::STATUS_DRAFT]);

                            Notification::make()
                                ->title('Đã reset về nháp')
                                ->info()
                                ->send();

                            $this->dispatch('scrape-items-updated');
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('assignVolume')
                        ->label('Gán quyển')
                        ->color('warning')
                        ->icon(Heroicon::OutlinedBookOpen)
                        ->schema([
                            TextInput::make('volume_number')
                                ->label('Số quyển')
                                ->numeric()
                                ->minValue(1)
                                ->required()
                                ->helperText('Gán cùng số quyển cho tất cả items đã chọn.'),
                        ])
                        ->action(function ($records, array $data) {
                            $volume = (int) $data['volume_number'];
                            $count = $records->count();

                            foreach ($records as $record) {
                                $rawData = $record->raw_data ?? [];
                                $rawData['volume_number'] = $volume;
                                $record->update(['raw_data' => $rawData]);
                            }

                            Notification::make()
                                ->title("Đã gán Quyển {$volume} cho {$count} items")
                                ->success()
                                ->send();
                        })
                        ->visible(function (Component $livewire) {
                            if ($livewire instanceof RelationManager) {
                                return $livewire->getOwnerRecord()->entity_type === 'chapter';
                            }

                            return false;
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('assignSubChapter')
                        ->label('Gán phần')
                        ->color('info')
                        ->icon(Heroicon::OutlinedDocumentDuplicate)
                        ->schema([
                            TextInput::make('sub_chapter')
                                ->label('Số phần')
                                ->numeric()
                                ->minValue(1)
                                ->required()
                                ->helperText('Gán cùng số phần cho tất cả items đã chọn. Ví dụ: Phần 2.'),
                        ])
                        ->action(function ($records, array $data) {
                            $sub = (int) $data['sub_chapter'];
                            $count = $records->count();

                            foreach ($records as $record) {
                                $rawData = $record->raw_data ?? [];
                                $rawData['sub_chapter'] = $sub;
                                $record->update(['raw_data' => $rawData]);
                            }

                            Notification::make()
                                ->title("Đã gán Phần {$sub} cho {$count} items")
                                ->success()
                                ->send();
                        })
                        ->visible(function (Component $livewire) {
                            if ($livewire instanceof RelationManager) {
                                return $livewire->getOwnerRecord()->entity_type === 'chapter';
                            }

                            return false;
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('fetchContent')
                        ->label('Fetch nội dung')
                        ->color('info')
                        ->icon(Heroicon::OutlinedDocumentText)
                        ->requiresConfirmation()
                        ->modalHeading('Fetch nội dung các chương đã chọn?')
                        ->modalDescription(function ($records) {
                            $count = $records->count();
                            $alreadyFetched = $records->filter(fn ($r) => ! empty($r->raw_data['content'] ?? null))->count();
                            $toFetch = $count - $alreadyFetched;

                            $msg = "Sẽ truy cập {$toFetch} URL chương để lấy nội dung.";
                            if ($alreadyFetched > 0) {
                                $msg .= " ({$alreadyFetched} chương đã có content sẽ được fetch lại.)";
                            }
                            $msg .= ' Quá trình này có thể mất vài phút.';

                            return $msg;
                        })
                        ->action(function ($records) {
                            $job = $this->getOwnerRecord();

                            try {
                                $scraper = app(ScraperService::class);
                                $results = $scraper->fetchDetailForItems($job, $records);

                                // Sync fetched content into already-imported chapters
                                $synced = 0;
                                foreach ($records as $item) {
                                    $item->refresh();
                                    if ($item->status === ScrapeItem::STATUS_IMPORTED
                                        && ! empty($item->raw_data['content'] ?? null)) {
                                        $importer = app(ScrapeImporter::class);
                                        $importer->importItem($item, $job);
                                        $synced++;
                                    }
                                }

                                $msg = "Thành công: {$results['fetched']} | Lỗi: {$results['errors']} / {$results['total']}";
                                if ($synced > 0) {
                                    $msg .= " | Đã cập nhật {$synced} chương đã import.";
                                }

                                Notification::make()
                                    ->title('Fetch hoàn tất!')
                                    ->body($msg)
                                    ->success()
                                    ->send();
                            } catch (\Throwable $e) {
                                Notification::make()
                                    ->title('Fetch thất bại')
                                    ->body($e->getMessage())
                                    ->danger()
                                    ->send();
                            }

                            // Refresh table rows + progress widget without page reload
                            $this->dispatch('scrape-items-updated');
                            $this->dispatch('scrape-data-updated');
                        })
                        ->visible(function (Component $livewire) {
                            if ($livewire instanceof RelationManager) {
                                $owner = $livewire->getOwnerRecord();

                                return $owner->entity_type === 'chapter'
                                    && $owner->hasDetailConfig();
                            }

                            return false;
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('deleteItems')
                        ->label('Xóa')
                        ->color('danger')
                        ->icon(Heroicon::OutlinedTrash)
                        ->requiresConfirmation()
                        ->modalHeading('Xóa các items đã chọn?')
                        ->modalDescription('Hành động này không thể hoàn tác.')
                        ->action(function ($records) {
                            $count = $records->count();
                            ScrapeItem::whereIn('id', $records->pluck('id'))->delete();

                            Notification::make()
                                ->title("Đã xóa {$count} items")
                                ->success()
                                ->send();

                            $this->dispatch('scrape-items-updated');
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->headerActions([
                Action::make('selectAll')
                    ->label('✅ Chọn tất cả nháp')
                    ->color('success')
                    ->action(function () {
                        $count = $this->getOwnerRecord()
                            ->items()
                            ->where('status', ScrapeItem::STATUS_DRAFT)
                            ->update(['status' => ScrapeItem::STATUS_SELECTED]);

                        Notification::make()
                            ->title("Đã chọn {$count} items")
                            ->success()
                            ->send();

                        $this->resetTable();
                        $this->dispatch('scrape-items-updated');
                    }),

                Action::make('skipAll')
                    ->label('❌ Bỏ tất cả nháp')
                    ->color('danger')
                    ->action(function () {
                        $count = $this->getOwnerRecord()
                            ->items()
                            ->where('status', ScrapeItem::STATUS_DRAFT)
                            ->update(['status' => ScrapeItem::STATUS_SKIPPED]);

                        Notification::make()
                            ->title("Đã bỏ qua {$count} items")
                            ->warning()
                            ->send();

                        $this->resetTable();
                        $this->dispatch('scrape-items-updated');
                    }),

                Action::make('exportTxt')
                    ->label(function () {
                        $count = $this->getOwnerRecord()->items()->count();

                        return "📥 Export TXT ({$count})";
                    })
                    ->color('gray')
                    ->icon(Heroicon::OutlinedDocumentArrowDown)
                    ->visible(function () {
                        $owner = $this->getOwnerRecord();

                        return in_array($owner->entity_type, ['story', 'category', 'author'])
                            && $owner->items()->exists();
                    })
                    ->action(function () {
                        $job = $this->getOwnerRecord();
                        $items = $job->items()
                            ->orderBy('sort_order')
                            ->orderBy('id')
                            ->get();

                        // Build lines: Tên | URL
                        $lines = [];
                        foreach ($items as $item) {
                            $title = trim($item->getTitle());
                            $url = trim($item->source_url ?? '');
                            $lines[] = "{$title} | {$url}";
                        }

                        $content = implode("\n", $lines);

                        // UTF-8 BOM for proper Vietnamese diacritics in text editors
                        $bom = "\xEF\xBB\xBF";

                        $prefix = match ($job->entity_type) {
                            'category' => 'danh-muc',
                            'author'   => 'tac-gia',
                            default    => 'truyen',
                        };
                        $jobName = str($job->name ?? 'export')
                            ->slug()
                            ->toString();
                        $date = now()->format('Y-m-d');
                        $filename = "{$prefix}-{$jobName}-{$date}.txt";

                        return response()->streamDownload(function () use ($bom, $content) {
                            echo $bom . $content;
                        }, $filename, [
                            'Content-Type' => 'text/plain; charset=utf-8',
                        ]);
                    }),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('editChapterInfo')
                        ->label('Sửa thông tin chương')
                        ->icon(Heroicon::OutlinedPencilSquare)
                        ->color('warning')
                        ->modalHeading('Sửa thông tin chương')
                        ->modalWidth('md')
                        ->schema([
                            TextInput::make('chapter_number')
                                ->label('Số chương')
                                ->maxLength(20)
                                ->rules(['nullable', 'regex:/^\d+(\.\d+)?[a-zA-Z]?$/'])
                                ->helperText('Ví dụ: 1, 2, 1a, 1.5'),
                            TextInput::make('volume_number')
                                ->label('Quyển')
                                ->numeric()
                                ->minValue(1)
                                ->helperText('Ví dụ: 1, 2, 3. Bỏ trống nếu không chia quyển.'),
                            TextInput::make('sub_chapter')
                                ->label('Phần')
                                ->numeric()
                                ->minValue(1)
                                ->helperText('Ví dụ: 1, 2. Bỏ trống nếu chương không chia phần. Kết quả: "Chương 15 - Phần 2".'),
                        ])
                        ->fillForm(function (ScrapeItem $record): array {
                            $rawData = $record->raw_data ?? [];

                            // Get chapter number: prefer raw_data value > extract from title
                            $chapterNumber = $rawData['chapter_number'] ?? null;
                            if ($chapterNumber === null) {
                                $title = $rawData['title'] ?? '';
                                if (preg_match('/(?:ch(?:ương|ap|apter)?|hồi)\s*(\d+(?:\.\d+)?[a-zA-Z]?)/iu', $title, $matches)) {
                                    $chapterNumber = Chapter::normalizeChapterNumber($matches[1]);
                                } elseif (preg_match('/(\d+(?:\.\d+)?[a-zA-Z]?)/', $title, $matches)) {
                                    $chapterNumber = Chapter::normalizeChapterNumber($matches[1]);
                                }
                            }

                            return [
                                'chapter_number' => $chapterNumber,
                                'volume_number' => $rawData['volume_number'] ?? null,
                                'sub_chapter' => $rawData['sub_chapter'] ?? null,
                            ];
                        })
                        ->action(function (ScrapeItem $record, array $data) {
                            $rawData = $record->raw_data ?? [];

                            // Update chapter_number (allow null to clear)
                            if ($data['chapter_number'] !== null && $data['chapter_number'] !== '') {
                                $rawData['chapter_number'] = Chapter::normalizeChapterNumber($data['chapter_number']);
                            } else {
                                unset($rawData['chapter_number']);
                            }

                            // Update volume_number (allow null to clear)
                            if ($data['volume_number'] !== null && $data['volume_number'] !== '') {
                                $rawData['volume_number'] = (int) $data['volume_number'];
                            } else {
                                unset($rawData['volume_number']);
                            }

                            // Update sub_chapter (allow null to clear)
                            if ($data['sub_chapter'] !== null && $data['sub_chapter'] !== '') {
                                $rawData['sub_chapter'] = (int) $data['sub_chapter'];
                            } else {
                                unset($rawData['sub_chapter']);
                            }

                            $record->update(['raw_data' => $rawData]);

                            Notification::make()
                                ->title('Đã cập nhật thông tin chương')
                                ->success()
                                ->send();
                        })
                        ->visible(function (Component $livewire) {
                            if ($livewire instanceof RelationManager) {
                                return $livewire->getOwnerRecord()->entity_type === 'chapter';
                            }

                            return false;
                        }),

                    Action::make('resetToDraft')
                        ->label('Reset về nháp')
                        ->icon(Heroicon::OutlinedArrowPath)
                        ->color('gray')
                        ->visible(fn (ScrapeItem $record) => in_array($record->status, [
                            ScrapeItem::STATUS_IMPORTED,
                            ScrapeItem::STATUS_MERGED,
                            ScrapeItem::STATUS_SKIPPED,
                            ScrapeItem::STATUS_ERROR,
                        ]))
                        ->requiresConfirmation()
                        ->modalHeading('Reset về nháp?')
                        ->modalDescription('Item sẽ được reset về nháp để có thể chọn và import lại.')
                        ->action(function (ScrapeItem $record) {
                            $record->update(['status' => ScrapeItem::STATUS_DRAFT]);

                            Notification::make()
                                ->title('Đã reset về nháp')
                                ->info()
                                ->send();

                            $this->dispatch('scrape-items-updated');
                        }),

                    Action::make('viewRawData')
                        ->label('Xem dữ liệu')
                        ->icon(Heroicon::OutlinedEye)
                        ->color('info')
                        ->modalHeading('Dữ liệu thô')
                        ->modalContent(fn (ScrapeItem $record) => view('filament.scrape-item-raw', [
                            'data' => $record->raw_data,
                            'url' => $record->source_url,
                        ])),

                    Action::make('deleteItem')
                        ->label('Xóa')
                        ->icon(Heroicon::OutlinedTrash)
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Xóa item này?')
                        ->modalDescription('Hành động này không thể hoàn tác.')
                        ->action(function (ScrapeItem $record) {
                            $record->delete();

                            Notification::make()
                                ->title('Đã xóa')
                                ->success()
                                ->send();
                        }),
                ])
                    ->icon(Heroicon::OutlinedEllipsisVertical)
                    ->tooltip('Hành động')
                    ->dropdownPlacement('bottom-end'),
            ])
            ->striped()
            ->recordClasses(fn (ScrapeItem $record) => match ($record->status) {
                'imported', 'merged' => 'opacity-75',
                'skipped' => 'opacity-60',
                'error' => 'bg-danger-50 dark:bg-danger-950',
                default => '',
            })
            ->emptyStateHeading(fn () => $this->getFilteredEmptyHeading())
            ->emptyStateDescription(fn () => $this->getFilteredEmptyDescription())
            ->emptyStateIcon(Heroicon::OutlinedInboxStack)
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([10, 25, 50, 100]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Columns
    // ═══════════════════════════════════════════════════════════════════════

    private static function titleColumn(): TextColumn
    {
        return TextColumn::make('title_display')
            ->label('Tên')
            ->state(fn (ScrapeItem $record) => $record->getTitle())
            ->searchable(query: function ($query, string $search) {
                $escaped = str_replace(['%', '_'], ['\%', '\_'], $search);
                $query->where('raw_data', 'like', "%{$escaped}%");
            })
            ->limit(50);
    }

    private static function chapterNumberColumn(): TextColumn
    {
        return TextColumn::make('chapter_number_display')
            ->label('Số chương')
            ->state(function (ScrapeItem $record) {
                $rawData = $record->raw_data ?? [];

                // Priority: raw_data['chapter_number'] > extract from title
                if (isset($rawData['chapter_number'])) {
                    return Chapter::normalizeChapterNumber($rawData['chapter_number']);
                }

                // Fallback: extract from title using same regex as ScrapeImporter
                $title = $rawData['title'] ?? '';
                if (preg_match('/(?:ch(?:ương|ap|apter)?|hồi)\s*(\d+(?:\.\d+)?[a-zA-Z]?)/iu', $title, $matches)) {
                    return Chapter::normalizeChapterNumber($matches[1]);
                }
                if (preg_match('/(\d+(?:\.\d+)?[a-zA-Z]?)/', $title, $matches)) {
                    return Chapter::normalizeChapterNumber($matches[1]);
                }

                return '—';
            })
            ->badge()
            ->color('info')
            ->visible(function (Component $livewire) {
                if ($livewire instanceof RelationManager) {
                    return $livewire->getOwnerRecord()->entity_type === 'chapter';
                }

                return false;
            })
            ->sortable(query: function ($query, string $direction) {
                $query->orderByRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(raw_data, '$.chapter_number')) AS DECIMAL(10,2)) {$direction}");
            });
    }

    private static function volumeColumn(): TextColumn
    {
        return TextColumn::make('volume_display')
            ->label('Quyển')
            ->state(function (ScrapeItem $record) {
                $rawData = $record->raw_data ?? [];
                $volume = $rawData['volume_number'] ?? null;

                if ($volume === null) {
                    return null;
                }

                return "Q.{$volume}";
            })
            ->badge()
            ->color('warning')
            ->placeholder('—')
            ->visible(function (Component $livewire) {
                if ($livewire instanceof RelationManager) {
                    return $livewire->getOwnerRecord()->entity_type === 'chapter';
                }

                return false;
            });
    }

    private static function subChapterColumn(): TextColumn
    {
        return TextColumn::make('sub_chapter_display')
            ->label('Phần')
            ->state(function (ScrapeItem $record) {
                $rawData = $record->raw_data ?? [];
                $sub = $rawData['sub_chapter'] ?? null;

                if ($sub === null) {
                    return null;
                }

                return "P.{$sub}";
            })
            ->badge()
            ->color('success')
            ->placeholder('—')
            ->visible(function (Component $livewire) {
                if ($livewire instanceof RelationManager) {
                    return $livewire->getOwnerRecord()->entity_type === 'chapter';
                }

                return false;
            });
    }

    private static function sourceUrlColumn(): TextColumn
    {
        return TextColumn::make('source_url')
            ->label('URL gốc')
            ->limit(40)
            ->url(fn ($record) => $record->source_url, shouldOpenInNewTab: true)
            ->toggleable();
    }

    private static function statusColumn(): TextColumn
    {
        return TextColumn::make('status')
            ->label('Trạng thái')
            ->badge()
            ->color(fn (string $state) => match ($state) {
                'draft' => 'gray',
                'selected' => 'info',
                'imported' => 'success',
                'merged' => 'primary',
                'skipped' => 'warning',
                'error' => 'danger',
                default => 'gray',
            })
            ->formatStateUsing(fn (string $state) => match ($state) {
                'draft' => '⚪ Nháp',
                'selected' => '✅ Đã chọn',
                'imported' => '🟢 Đã import',
                'merged' => '🔄 Đã gộp',
                'skipped' => '🔴 Bỏ qua',
                'error' => '❌ Lỗi',
                default => $state,
            });
    }

    private static function contentStatusColumn(): TextColumn
    {
        return TextColumn::make('content_status')
            ->label('Nội dung')
            ->state(function (ScrapeItem $record) {
                $rawData = $record->raw_data ?? [];

                // Error state with retry info (TIER 2)
                if (! empty($rawData['_detail_error'] ?? null)) {
                    $errorType = $rawData['_error_type'] ?? 'unknown';
                    $retryCount = $rawData['_retry_count'] ?? 0;
                    $prefix = $errorType === 'permanent' ? '🚫' : "🔄{$retryCount}";

                    return "{$prefix} " . mb_substr($rawData['_detail_error'], 0, 35);
                }

                if (! empty($rawData['content'] ?? null)) {
                    // Validation issues (TIER 3)
                    $issues = $rawData['_validation_issues'] ?? [];
                    if (! empty($issues)) {
                        $labels = array_map(fn ($i) => match ($i) {
                            'empty_content' => 'trống',
                            'short_content' => 'ngắn',
                            'encoding_error' => 'lỗi mã',
                            default => $i,
                        }, $issues);

                        return '⚠️ ' . implode(', ', $labels);
                    }

                    return '✅ Đã fetch';
                }

                return '⏳ Chưa fetch';
            })
            ->color(function (ScrapeItem $record) {
                $rawData = $record->raw_data ?? [];
                if (! empty($rawData['_detail_error'] ?? null)) {
                    $errorType = $rawData['_error_type'] ?? 'unknown';

                    return $errorType === 'permanent' ? 'gray' : 'danger';
                }
                if (! empty($rawData['content'] ?? null)) {
                    return empty($rawData['_validation_issues'] ?? []) ? 'success' : 'warning';
                }

                return 'gray';
            })
            ->visible(function (Component $livewire) {
                if ($livewire instanceof RelationManager) {
                    return $livewire->getOwnerRecord()->entity_type === 'chapter';
                }

                return false;
            })
            ->tooltip(function (ScrapeItem $record) {
                $rawData = $record->raw_data ?? [];

                if (! empty($rawData['content'] ?? null)) {
                    $byteCount = strlen($rawData['content']);
                    $sizeKb = round($byteCount / 1024, 1);
                    $timing = $rawData['_timing'] ?? null;
                    $tip = "Nội dung: ~{$sizeKb} KB";

                    // TIER 5B: Show timing
                    if ($timing) {
                        $tip .= " | Fetch: {$timing['fetch_ms']}ms | Extract: {$timing['extract_ms']}ms";
                    }

                    return $tip;
                }

                if (! empty($rawData['_detail_error'] ?? null)) {
                    $errorType = $rawData['_error_type'] ?? 'unknown';
                    $retryCount = $rawData['_retry_count'] ?? 0;

                    return "[{$errorType}] retry: {$retryCount} | " . $rawData['_detail_error'];
                }

                return 'Chưa fetch nội dung';
            });
    }

    private static function pageNumberColumn(): TextColumn
    {
        return TextColumn::make('page_number')
            ->label('Trang')
            ->sortable();
    }

    private static function errorColumn(): TextColumn
    {
        return TextColumn::make('error_message')
            ->label('Lỗi')
            ->limit(30)
            ->toggleable(isToggledHiddenByDefault: true);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Empty state helpers
    // ═══════════════════════════════════════════════════════════════════════

    private function getFilteredEmptyHeading(): string
    {
        $total = $this->getOwnerRecord()->items()->count();

        if ($total === 0) {
            return 'Chưa có kết quả thu thập';
        }

        return 'Không có items ở trạng thái này';
    }

    private function getFilteredEmptyDescription(): string
    {
        $total = $this->getOwnerRecord()->items()->count();

        if ($total === 0) {
            return 'Bấm "Bắt đầu thu thập" để scrape dữ liệu';
        }

        $counts = $this->getOwnerRecord()->items()
            ->selectRaw("status, COUNT(*) as total")
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        $parts = [];
        foreach ($counts as $status => $count) {
            $label = match ($status) {
                'draft' => 'nháp',
                'selected' => 'đã chọn',
                'imported' => 'đã import',
                'merged' => 'đã gộp',
                'skipped' => 'bỏ qua',
                'error' => 'lỗi',
                default => $status,
            };
            $parts[] = "{$count} {$label}";
        }

        return 'Tổng: ' . implode(', ', $parts) . '. Thử đổi bộ lọc trạng thái.';
    }
}

