<?php

declare(strict_types=1);

namespace App\Filament\Resources\ScrapeJobs\Pages;

use App\Enums\StoryOrigin;
use App\Enums\StoryStatus;
use App\Filament\Pages\ListRecords;
use App\Filament\Resources\ScrapeJobs\ScrapeJobResource;
use App\Filament\Resources\ScrapeJobs\Widgets\ScrapeCompletionWidget;
use App\Models\ScrapeJob;
use App\Models\ScrapeSource;
use App\Services\Scraper\ScrapeCsvImporter;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;

class ListScrapeJobs extends ListRecords
{
    protected static string $resource = ScrapeJobResource::class;

    /**
     * Session key used to persist the active entity_type filter
     * across create/edit/view pages for sidebar context.
     */
    public const SESSION_ENTITY_TYPE = 'scrape_jobs.entity_type_filter';

    public function mount(): void
    {
        parent::mount();

        // Persist entity_type filter to session for sidebar context
        $entityType = request()->input('filters.entity_type.value');

        if ($entityType) {
            session()->put(self::SESSION_ENTITY_TYPE, $entityType);
        } else {
            session()->forget(self::SESSION_ENTITY_TYPE);
        }
    }

    protected function getHeaderActions(): array
    {
        $createAction = CreateAction::make();

        // Carry entity_type filter into create page URL
        $entityType = session(self::SESSION_ENTITY_TYPE);
        if ($entityType) {
            $createAction->url(
                static::getResource()::getUrl('create', [
                    'entity_type' => $entityType,
                ])
            );
        }

        $actions = [$createAction];

        // Only show CSV import when filtering by chapter entity type
        if ($entityType === ScrapeJob::ENTITY_CHAPTER) {
            $actions[] = $this->csvImportAction();
        }

        return $actions;
    }

    protected function getHeaderWidgets(): array
    {
        return [
            ScrapeCompletionWidget::class,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // CSV Import Action
    // ═══════════════════════════════════════════════════════════════════════

    private function csvImportAction(): Action
    {
        return Action::make('csvImport')
            ->label('Import CSV')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->color('info')
            ->modalHeading('Import CSV — Tạo truyện + tác vụ chương')
            ->modalDescription('Upload file CSV (phân cách bằng |) để tạo hàng loạt truyện và tác vụ thu thập chương.')
            ->modalWidth('3xl')
            ->modalSubmitActionLabel('Import')
            ->form([
                Section::make('Nguồn & Template')
                    ->compact()
                    ->schema([
                        Grid::make(2)->schema([
                            Select::make('source_id')
                                ->label('Nguồn')
                                ->options(fn () => ScrapeSource::where('is_active', true)->pluck('name', 'id')->toArray())
                                ->searchable()
                                ->required()
                                ->live(),

                            Select::make('template_job_id')
                                ->label('Template Job')
                                ->options(function (callable $get) {
                                    $sourceId = $get('source_id');
                                    if (! $sourceId) {
                                        return [];
                                    }

                                    return ScrapeJob::where('source_id', $sourceId)
                                        ->where('entity_type', ScrapeJob::ENTITY_CHAPTER)
                                        ->orderByDesc('id')
                                        ->limit(50)
                                        ->get()
                                        ->mapWithKeys(fn (ScrapeJob $job) => [
                                            $job->id => "#{$job->id} — {$job->name}",
                                        ])
                                        ->toArray();
                                })
                                ->searchable()
                                ->required()
                                ->helperText('Dùng làm mẫu selectors, detail config, import defaults.'),
                        ]),
                    ]),

                Section::make('File CSV')
                    ->description('story_title|chapter_url|pagination_pattern|cover_image_url')
                    ->compact()
                    ->schema([
                        FileUpload::make('csv_file')
                            ->label('File CSV')
                            ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel', '.csv'])
                            ->required()
                            ->disk('local')
                            ->directory('csv-imports')
                            ->helperText('Mỗi dòng 1 truyện. Cách nhau bằng |. 2 cột cuối tùy chọn.'),
                    ]),

                Section::make('Mặc định truyện mới')
                    ->description('Truyện đã có sẵn không bị ảnh hưởng.')
                    ->compact()
                    ->schema([
                        Grid::make(2)->schema([
                            Select::make('story_origin')
                                ->label('Nguồn gốc')
                                ->options(StoryOrigin::options())
                                ->default(StoryOrigin::default()->value)
                                ->native(false),
                            Select::make('story_status')
                                ->label('Trạng thái')
                                ->options(StoryStatus::options())
                                ->default(StoryStatus::default()->value)
                                ->native(false),
                        ]),
                        Select::make('category_ids')
                            ->label('Thể loại')
                            ->options(fn () => \App\Models\Category::orderBy('name')->pluck('name', 'id')->toArray())
                            ->multiple()
                            ->searchable()
                            ->native(false)
                            ->helperText('Thể loại đầu tiên sẽ là điều hướng chính.'),
                        Toggle::make('is_published')
                            ->label('Xuất bản ngay')
                            ->default(false),
                    ]),
            ])
            ->action(function (array $data) {
                // Validate source & template
                $source = ScrapeSource::find($data['source_id']);
                $templateJob = ScrapeJob::find($data['template_job_id']);

                if (! $source || ! $templateJob) {
                    Notification::make()
                        ->title('Lỗi')
                        ->body('Nguồn hoặc Template Job không hợp lệ.')
                        ->danger()
                        ->send();

                    return;
                }

                // Resolve CSV file path (local disk = storage/app/private)
                $csvPath = Storage::disk('local')->path($data['csv_file']);

                if (! file_exists($csvPath)) {
                    Notification::make()
                        ->title('Lỗi')
                        ->body('Không tìm thấy file CSV đã upload.')
                        ->danger()
                        ->send();

                    return;
                }

                // Build story defaults — primary_category = first category
                $categoryIds = $data['category_ids'] ?? [];
                $storyDefaults = [
                    'origin'              => $data['story_origin'] ?? null,
                    'status'              => $data['story_status'] ?? null,
                    'is_published'        => $data['is_published'] ?? false,
                    'category_ids'        => $categoryIds,
                    'primary_category_id' => $categoryIds[0] ?? null,
                ];

                // Execute import
                $importer = new ScrapeCsvImporter();
                $result = $importer->import($csvPath, $source, $templateJob, $storyDefaults);

                // Clean up uploaded file
                Storage::disk('local')->delete($data['csv_file']);

                // Show notification
                if ($result->errors > 0) {
                    Notification::make()
                        ->title('Import hoàn tất (có lỗi)')
                        ->body($result->summary())
                        ->warning()
                        ->persistent()
                        ->send();
                } else {
                    Notification::make()
                        ->title('Import thành công! 🎉')
                        ->body($result->summary())
                        ->success()
                        ->persistent()
                        ->send();
                }
            });
    }
}

