<?php

declare(strict_types=1);

namespace App\Filament\Resources\ScrapeJobs\Schemas;

use App\Enums\ScheduleFrequency;
use App\Enums\StoryOrigin;
use App\Enums\StoryStatus;
use App\Models\ScrapeJob;
use App\Models\ScrapeSource;
use Awcodes\Curator\Components\Forms\CuratorPicker;
use Filament\Forms\Components\KeyValue;
use Filament\Infolists\Components\TextEntry;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

class ScrapeJobForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('Tabs')
                ->tabs([
                    self::basicTab(),
                    self::selectorsTab(),
                    self::aiPromptTab(),
                    self::chapterDetailTab(),
                    self::chapterConfigTab(),
                    self::paginationTab(),
                    self::scheduleTab(),
                    self::importDefaultsTab(),
                ])
                ->columnSpanFull()
                ->persistTabInQueryString(),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Helpers — source extraction method detection
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Check if the currently selected source uses AI extraction.
     */
    private static function selectedSourceUsesAi(callable $get): bool
    {
        $sourceId = $get('source_id');
        if (! $sourceId) {
            return false;
        }

        $source = ScrapeSource::find($sourceId);

        return $source?->usesAi() ?? false;
    }

    /**
     * Check if the currently selected source uses CSS selectors.
     */
    private static function selectedSourceUsesCss(callable $get): bool
    {
        return ! self::selectedSourceUsesAi($get);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Tabs
    // ═══════════════════════════════════════════════════════════════════════

    private static function basicTab(): Tab
    {
        return Tab::make('Cơ bản')
            ->icon(Heroicon::OutlinedInformationCircle)
            ->schema([
                self::basicInfoSection(),
            ]);
    }

    private static function selectorsTab(): Tab
    {
        return Tab::make('CSS Selectors')
            ->icon(Heroicon::OutlinedCodeBracket)
            ->schema([
                self::selectorsSection(),
            ])
            ->visible(fn (callable $get) => self::selectedSourceUsesCss($get)
                && $get('entity_type') !== ScrapeJob::ENTITY_CHAPTER_DETAIL);
    }

    private static function aiPromptTab(): Tab
    {
        return Tab::make('AI Prompt')
            ->icon(Heroicon::OutlinedSparkles)
            ->schema([
                self::aiPromptSection(),
            ])
            ->visible(fn (callable $get) => self::selectedSourceUsesAi($get)
                && $get('entity_type') !== ScrapeJob::ENTITY_CHAPTER_DETAIL);
    }

    private static function paginationTab(): Tab
    {
        return Tab::make('Phân trang')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->schema([
                self::paginationSection(),
            ])
            ->visible(fn (callable $get) => $get('entity_type') !== ScrapeJob::ENTITY_CHAPTER_DETAIL);
    }

    private static function chapterDetailTab(): Tab
    {
        return Tab::make('Chi tiết chương')
            ->icon(Heroicon::OutlinedDocumentText)
            ->schema([
                self::chapterDetailSection(),
            ])
            ->visible(fn (callable $get) => $get('entity_type') === ScrapeJob::ENTITY_CHAPTER_DETAIL);
    }

    private static function chapterConfigTab(): Tab
    {
        return Tab::make('Trang chương')
            ->icon(Heroicon::OutlinedDocumentText)
            ->schema([
                self::chapterConfigSection(),
            ])
            ->visible(fn (callable $get) => $get('entity_type') === ScrapeJob::ENTITY_CHAPTER);
    }

    private static function importDefaultsTab(): Tab
    {
        return Tab::make('Mặc định Import')
            ->icon(Heroicon::OutlinedCog6Tooth)
            ->schema([
                self::importDefaultsSection(),
            ])
            ->visible(fn (callable $get) => $get('entity_type') === ScrapeJob::ENTITY_STORY);
    }

    private static function scheduleTab(): Tab
    {
        return Tab::make('Lịch chạy')
            ->icon(Heroicon::OutlinedClock)
            ->schema([
                self::scheduleSection(),
            ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Sections
    // ═══════════════════════════════════════════════════════════════════════

    private static function basicInfoSection(): Section
    {
        return Section::make()
            ->schema([
                Grid::make(2)->schema([
                    self::sourceSelect(),
                    self::entityTypeSelect(),
                ]),
                Grid::make(2)->schema([
                    self::nameInput(),
                    self::parentStorySelect(),
                ]),
                self::targetUrlInput(),
            ]);
    }

    private static function selectorsSection(): Section
    {
        return Section::make('CSS Selectors')
            ->description('Container = selector cho từng item. Fields = selector cho các trường bên trong item.')
            ->icon('heroicon-o-code-bracket')
            ->schema([
                self::containerSelectorInput(),
                self::fieldSelectorsInput(),
            ]);
    }

    private static function aiPromptSection(): Section
    {
        return Section::make('AI Prompt')
            ->description('Viết prompt hướng dẫn AI trích xuất dữ liệu. Để trống = dùng prompt mẫu từ nguồn.')
            ->icon('heroicon-o-sparkles')
            ->schema([
                self::aiPromptTextarea(),
                self::sourceAiInfoPlaceholder(),
            ]);
    }

    private static function scheduleSection(): Section
    {
        return Section::make('Lịch chạy tự động')
            ->description('Thu thập lại dữ liệu theo lịch qua Web Cron. Chọn tần suất, auto-fetch, và auto-import.')
            ->icon('heroicon-o-clock')
            ->schema([
                self::scheduleToggle(),
                Grid::make(2)->schema([
                    self::scheduleFrequencySelect(),
                    self::scheduleTimeInput(),
                ]),
                Grid::make(2)->schema([
                    self::scheduleDayOfWeekSelect(),
                    self::scheduleDayOfMonthSelect(),
                ]),
                Grid::make(2)->schema([
                    self::scheduleAutoFetchContentToggle(),
                    self::scheduleFetchBatchSizeInput(),
                ]),
                self::autoImportToggle(),
                self::scheduleInfoPlaceholder(),
            ]);
    }

    private static function paginationSection(): Section
    {
        return Section::make('Phân trang')
            ->description('Cài đặt nếu dữ liệu nằm trên nhiều trang. Hỗ trợ URL pattern và follow link.')
            ->icon('heroicon-o-arrows-right-left')
            ->schema([
                self::paginationTypeSelect(),
                self::paginationUrlPatternInput(),
                self::paginationFirstPageBaseUrlToggle(),
                Grid::make(2)->schema([
                    self::paginationStartInput(),
                    self::paginationEndInput(),
                ]),
                self::paginationNextSelectorInput(),
                self::paginationMaxPagesInput(),
            ]);
    }

    private static function chapterDetailSection(): Section
    {
        return Section::make('Chi tiết chương')
            ->description('Cào nội dung chương. Mặc định: follow link "Chương tiếp" cào chuỗi. Bật toggle để chỉ cào 1 trang.')
            ->icon('heroicon-o-document-text')
            ->schema([
                self::defaultChapterPublishedToggle(),
                self::singleChapterModeToggle(),
                self::chainSelectorInput(),
                Grid::make(2)->schema([
                    self::chainMaxChaptersInput(),
                    self::chainBatchSizeInput(),
                ]),
                self::chapterNumberInput(),
                self::detailContentSelectorInput(),
                self::detailRemoveSelectorsInput(),
                self::detailRemoveTextPatternsInput(),
                self::detailTitleSelectorInput(),
            ]);
    }

    /**
     * Section for entity_type = chapter (giữ nguyên logic cũ).
     */
    private static function chapterConfigSection(): Section
    {
        return Section::make('Trang chi tiết chương')
            ->description('Có Content selector → CSS xử lý (miễn phí). Không có → AI trích xuất (tốn token).')
            ->icon('heroicon-o-document-text')
            ->schema([
                self::defaultChapterPublishedToggle(),
                self::sequentialNumberingToggle(),
                Toggle::make('detail_config.auto_fetch_content')
                    ->label('Tự động tải nội dung chương')
                    ->default(false)
                    ->helperText('Bật = tự động fetch nội dung chương. Tắt = chỉ lấy danh sách, fetch thủ công sau.'),
                self::detailContentSelectorInput(),
                self::detailRemoveSelectorsInput(),
                self::detailRemoveTextPatternsInput(),
                Grid::make(2)->schema([
                    self::detailTitleSelectorInput(),
                    self::detailChapterNumberPatternInput(),
                ]),
                self::detailVolumeSelectorInput(),
                self::detailAiPromptTextarea(),
            ]);
    }

    private static function importDefaultsSection(): Section
    {
        return Section::make('Giá trị mặc định khi import')
            ->description('Gán cho TẤT CẢ truyện import. Để trống tác giả/thể loại → tự match từ dữ liệu.')
            ->icon('heroicon-o-cog-6-tooth')
            ->schema([
                Grid::make(2)->schema([
                    self::defaultStoryOriginSelect(),
                    self::defaultStoryStatusSelect(),
                ]),
                Grid::make(2)->schema([
                    self::defaultCategoriesSelect(),
                    self::defaultPrimaryCategorySelect(),
                ]),
                Grid::make(2)->schema([
                    self::defaultAuthorSelect(),
                    self::defaultPublishedToggle(),
                ]),
                Grid::make(3)->schema([
                    self::defaultFeaturedToggle(),
                    self::defaultHotToggle(),
                    self::defaultVipToggle(),
                ]),
            ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Basic Info Components
    // ═══════════════════════════════════════════════════════════════════════

    private static function sourceSelect(): Select
    {
        return Select::make('source_id')
            ->label('Nguồn')
            ->relationship('source', 'name')
            ->searchable()
            ->preload()
            ->required()
            ->live();
    }

    private static function entityTypeSelect(): Select
    {
        return Select::make('entity_type')
            ->label('Loại dữ liệu')
            ->options(
                collect(config('scrape.entity_types', []))
                    ->mapWithKeys(fn (array $cfg, string $key) => [$key => $cfg['icon'] . ' ' . $cfg['label']])
                    ->toArray()
            )
            ->required()
            ->live();
    }

    private static function nameInput(): TextInput
    {
        return TextInput::make('name')
            ->label('Tên phiên thu thập')
            ->placeholder('VD: Danh mục TTV trang 1-5')
            ->required()
            ->maxLength(255);
    }

    private static function parentStorySelect(): Select
    {
        return Select::make('parent_story_id')
            ->label('Truyện')
            ->relationship(
                name: 'parentStory',
                modifyQueryUsing: fn (\Illuminate\Database\Eloquent\Builder $query) => $query
                    ->with('author')
                    ->orderBy('title'),
            )
            ->getOptionLabelFromRecordUsing(
                fn (\Illuminate\Database\Eloquent\Model $record): string => $record->author
                    ? "{$record->title} — {$record->author->name}"
                    : $record->title,
            )
            ->searchable()
            ->getSearchResultsUsing(function (string $search): array {
                return \App\Models\Story::query()
                    ->with('author')
                    ->where('title', 'like', "%{$search}%")
                    ->orWhereHas('author', fn ($q) => $q->where('name', 'like', "%{$search}%"))
                    ->orderBy('title')
                    ->limit(50)
                    ->get()
                    ->mapWithKeys(fn ($story) => [
                        $story->id => $story->author
                            ? "{$story->title} — {$story->author->name}"
                            : $story->title,
                    ])
                    ->toArray();
            })
            ->preload()
            ->createOptionForm([
                TextInput::make('title')
                    ->label('Tiêu đề truyện')
                    ->required()
                    ->maxLength(500)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function ($state, callable $set, callable $get) {
                        if (! $get('slug')) {
                            $set('slug', Str::slug($state));
                        }
                    }),
                TextInput::make('slug')
                    ->label('Slug (URL)')
                    ->required()
                    ->maxLength(500)
                    ->unique('stories', 'slug'),
                Grid::make(2)->schema([
                    Select::make('status')
                        ->label('Trạng thái')
                        ->options(StoryStatus::options())
                        ->default(StoryStatus::default()->value)
                        ->required()
                        ->native(false),
                    Select::make('origin')
                        ->label('Nguồn gốc')
                        ->options(StoryOrigin::options())
                        ->default(StoryOrigin::default()->value)
                        ->required()
                        ->native(false),
                ]),
                Grid::make(2)->schema([
                    Select::make('category_ids')
                        ->label('Thể loại')
                        ->options(fn () => \App\Models\Category::orderBy('name')->pluck('name', 'id')->toArray())
                        ->multiple()
                        ->searchable()
                        ->required()
                        ->native(false)
                        ->createOptionForm([
                            TextInput::make('name')
                                ->label('Tên thể loại')
                                ->required()
                                ->live(onBlur: true)
                                ->afterStateUpdated(
                                    fn ($state, callable $set) => $set('slug', Str::slug($state))
                                ),
                            TextInput::make('slug')
                                ->label('Slug')
                                ->required(),
                        ])
                        ->createOptionUsing(function (array $data): int {
                            return \App\Models\Category::create($data)->getKey();
                        }),
                    Select::make('primary_category_id')
                        ->label('Thể loại điều hướng')
                        ->options(fn () => \App\Models\Category::orderBy('name')->pluck('name', 'id')->toArray())
                        ->searchable()
                        ->required()
                        ->native(false),
                ]),
                Grid::make(2)->schema([
                    Select::make('author_id')
                        ->label('Tác giả')
                        ->options(fn () => \App\Models\Author::orderBy('name')->pluck('name', 'id')->toArray())
                        ->searchable()
                        ->native(false)
                        ->createOptionForm([
                            TextInput::make('name')
                                ->label('Tên tác giả')
                                ->required()
                                ->live(onBlur: true)
                                ->afterStateUpdated(
                                    fn ($state, callable $set) => $set('slug', Str::slug($state))
                                ),
                            TextInput::make('slug')
                                ->label('Slug')
                                ->required(),
                        ])
                        ->createOptionUsing(function (array $data): int {
                            return \App\Models\Author::create($data)->getKey();
                        }),
                    Select::make('tag_ids')
                        ->label('Tags')
                        ->options(fn () => \App\Models\Tag::orderBy('name')->pluck('name', 'id')->toArray())
                        ->multiple()
                        ->searchable()
                        ->native(false)
                        ->createOptionForm([
                            TextInput::make('name')
                                ->label('Tên tag')
                                ->required()
                                ->live(onBlur: true)
                                ->afterStateUpdated(
                                    fn ($state, callable $set) => $set('slug', Str::slug($state))
                                ),
                            TextInput::make('slug')
                                ->label('Slug')
                                ->required(),
                        ])
                        ->createOptionUsing(function (array $data): int {
                            return \App\Models\Tag::create($data)->getKey();
                        }),
                ]),
                CuratorPicker::make('cover_image_id')
                    ->label('Ảnh bìa')
                    ->buttonLabel('Chọn ảnh bìa')
                    ->acceptedFileTypes(['image/*']),
                Toggle::make('is_published')
                    ->label('Đã xuất bản')
                    ->default(false)
                    ->helperText('Hiển thị công khai trên website'),
            ])
            ->createOptionUsing(function (array $data): int {
                $categoryIds = $data['category_ids'] ?? [];
                $tagIds = $data['tag_ids'] ?? [];
                unset($data['category_ids'], $data['tag_ids']);

                $data['user_id'] = auth()->id();

                $story = \App\Models\Story::create($data);

                if (! empty($categoryIds)) {
                    $story->categories()->attach($categoryIds);
                }

                if (! empty($tagIds)) {
                    $story->tags()->attach($tagIds);
                }

                return $story->getKey();
            })
            ->visible(fn (callable $get) => in_array($get('entity_type'), [
                ScrapeJob::ENTITY_CHAPTER,
                ScrapeJob::ENTITY_CHAPTER_DETAIL,
            ]))
            ->required(fn (callable $get) => in_array($get('entity_type'), [
                ScrapeJob::ENTITY_CHAPTER,
                ScrapeJob::ENTITY_CHAPTER_DETAIL,
            ]))
            ->helperText('Bắt buộc khi thu thập chương — chọn truyện mà chương thuộc về.');
    }

    private static function targetUrlInput(): TextInput
    {
        return TextInput::make('target_url')
            ->label('URL mục tiêu (trang cần thu thập)')
            ->placeholder(fn (callable $get) => match ($get('entity_type')) {
                ScrapeJob::ENTITY_CATEGORY       => 'https://example.com/the-loai',
                ScrapeJob::ENTITY_AUTHOR         => 'https://example.com/tac-gia',
                ScrapeJob::ENTITY_STORY          => 'https://example.com/tong-hop?tp=cv&page=1',
                ScrapeJob::ENTITY_CHAPTER        => 'https://example.com/doc-truyen/ten-truyen',
                ScrapeJob::ENTITY_CHAPTER_DETAIL => 'https://example.com/doc-truyen/ten-truyen/chuong-1',
                default                          => 'https://example.com/danh-sach?page=1',
            })
            ->required()
            ->url()
            ->maxLength(1000)
            ->columnSpanFull()
            ->helperText(function (callable $get) {
                $paginationType = $get('pagination.type');

                // When URL pattern pagination is active, target_url is NOT used for fetching
                if ($paginationType === 'query_param') {
                    return '⚠️ Khi dùng URL pattern, URL này chỉ là tham chiếu — trang thực tế sinh từ tab Phân trang.';
                }

                return match ($get('entity_type')) {
                    ScrapeJob::ENTITY_CATEGORY       => 'URL trang liệt kê danh mục/thể loại. VD: trang "Thể loại" của website nguồn.',
                    ScrapeJob::ENTITY_AUTHOR         => 'URL trang liệt kê tác giả, hoặc trang có danh sách tác giả cần thu thập.',
                    ScrapeJob::ENTITY_STORY          => 'URL trang danh sách truyện. Nếu có phân trang, nhập URL trang đầu tiên.',
                    ScrapeJob::ENTITY_CHAPTER        => 'URL trang mục lục (danh sách chương) của truyện cần thu thập.',
                    ScrapeJob::ENTITY_CHAPTER_DETAIL => 'URL trang chi tiết chương (trang chứa nội dung chương, không phải mục lục).',
                    default                          => 'URL trang web cụ thể cần thu thập dữ liệu. Đây là trang thật sự sẽ được scrape (không phải URL gốc ở nguồn).',
                };
            });
    }

    private static function chapterNumberInput(): TextInput
    {
        return TextInput::make('import_defaults.chapter_number')
            ->label('Số chương bắt đầu')
            ->maxLength(20)
            ->rules(['nullable', 'regex:/^\d+(\.\d+)?[a-zA-Z]?$/'])
            ->placeholder('Mặc định: 1')
            ->visible(fn (callable $get) => $get('entity_type') === ScrapeJob::ENTITY_CHAPTER_DETAIL
                && ! $get('detail_config.single_chapter_mode'))
            ->helperText('Số chương bắt đầu cho chuỗi. VD: nhập 50 → đánh số từ 50, 51, 52... Để trống = bắt đầu từ 1.');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Selectors Components (CSS mode)
    // ═══════════════════════════════════════════════════════════════════════

    private static function containerSelectorInput(): TextInput
    {
        return TextInput::make('selectors.container')
            ->label('Container selector')
            ->placeholder('.list-item, table tbody tr, .book-item')
            ->required(fn (callable $get) => self::selectedSourceUsesCss($get))
            ->helperText('Selector cho MỖI item riêng lẻ (VD: ul.authors li), không phải wrapper (ul.authors).');
    }

    private static function fieldSelectorsInput(): KeyValue
    {
        return KeyValue::make('selectors.fields')
            ->label('Field selectors')
            ->keyLabel('Tên field')
            ->valueLabel('CSS selector')
            ->addActionLabel('+ Thêm field')
            ->helperText('Selector BÊN TRONG container. Dùng @ lấy attribute: a@href (link), img@src (ảnh).');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // AI Prompt Components (AI mode)
    // ═══════════════════════════════════════════════════════════════════════

    private static function aiPromptTextarea(): Textarea
    {
        return Textarea::make('ai_prompt')
            ->label('Prompt AI')
            ->rows(8)
            ->placeholder(fn (callable $get) => match ($get('entity_type')) {
                ScrapeJob::ENTITY_CATEGORY       => "Trích xuất danh sách thể loại/danh mục từ HTML.\nVới mỗi thể loại, lấy: name, url, description (nếu có).\nTrả về JSON array.",
                ScrapeJob::ENTITY_AUTHOR         => "Trích xuất danh sách tác giả từ HTML.\nVới mỗi tác giả, lấy: name, url.\nTrả về JSON array.",
                ScrapeJob::ENTITY_STORY          => "Trích xuất danh sách truyện từ HTML.\nVới mỗi truyện, lấy: title, url, author, categories, description, cover_image.\nTrả về JSON array.",
                ScrapeJob::ENTITY_CHAPTER        => "Trích xuất danh sách chương từ HTML.\nVới mỗi chương, lấy: title, url, chapter_number.\nTrả về JSON array.",
                ScrapeJob::ENTITY_CHAPTER_DETAIL => "Trích xuất nội dung chương từ HTML.\nLấy: content (HTML nội dung chương), title.\nLoại bỏ quảng cáo, điều hướng.\nTrả về JSON object.",
                default                          => "Trích xuất dữ liệu từ HTML. Mô tả rõ cần lấy field nào.\nTrả về JSON array.",
            })
            ->helperText('Viết cụ thể cho loại dữ liệu đang thu thập. Để trống = dùng prompt mẫu ở nguồn.');
    }

    private static function sourceAiInfoPlaceholder(): TextEntry
    {
        return TextEntry::make('source_ai_info')
            ->label('Thông tin AI từ nguồn')
            ->state(function (callable $get) {
                $sourceId = $get('source_id');
                if (! $sourceId) {
                    return 'Chưa chọn nguồn.';
                }

                $source = ScrapeSource::find($sourceId);
                if (! $source || ! $source->usesAi()) {
                    return 'Nguồn không dùng AI.';
                }

                $provider = match ($source->ai_provider) {
                    'gemini' => '🌟 Google Gemini',
                    'groq'   => '⚡ Groq',
                    default  => $source->ai_provider ?? '(chưa cấu hình)',
                };

                $lines = [
                    "Provider: {$provider}",
                    'Model: ' . ($source->ai_model ?: '(mặc định)'),
                ];

                if ($source->ai_prompt_template) {
                    $truncated = Str::limit($source->ai_prompt_template, 200);
                    $lines[] = "Prompt mẫu: {$truncated}";
                } else {
                    $lines[] = 'Prompt mẫu: (chưa thiết lập — nên viết prompt ở đây hoặc về cấu hình nguồn)';
                }

                return implode("\n", $lines);
            });
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Pagination Components
    // ═══════════════════════════════════════════════════════════════════════

    private static function paginationTypeSelect(): Select
    {
        return Select::make('pagination.type')
            ->label('Kiểu phân trang')
            ->options([
                'single' => 'Trang đơn (không phân trang)',
                'query_param' => 'URL pattern (tự nhập link có {page})',
                'next_link' => 'Theo link phân trang (CSS selector)',
            ])
            ->default('single')
            ->live()
            ->helperText('Trang đơn = 1 URL. URL pattern = nhập URL có {page}. Theo link = follow pagination link.');
    }

    private static function paginationUrlPatternInput(): TextInput
    {
        return TextInput::make('pagination.url_pattern')
            ->label('URL pattern phân trang')
            ->placeholder('https://example.com/list?p={page}')
            ->visible(fn (callable $get) => $get('pagination.type') === 'query_param')
            ->required(fn (callable $get) => $get('pagination.type') === 'query_param')
            ->helperText('Full URL, dùng {page} ở vị trí số trang. VD: ...?page={page} hoặc .../page/{page}.html');
    }

    private static function paginationFirstPageBaseUrlToggle(): Toggle
    {
        return Toggle::make('pagination.first_page_is_base_url')
            ->label('Trang đầu dùng URL mục tiêu')
            ->default(false)
            ->visible(fn (callable $get) => $get('pagination.type') === 'query_param')
            ->helperText('Bật khi trang đầu không có số trang trong URL. VD: /truyen/ (trang 1) vs /truyen/2/ (trang 2+). Trang đầu sẽ dùng URL mục tiêu thay vì URL pattern.');
    }

    private static function paginationStartInput(): TextInput
    {
        return TextInput::make('pagination.start_page')
            ->label('Trang bắt đầu cào')
            ->numeric()
            ->placeholder('Mặc định: 0')
            ->visible(fn (callable $get) => $get('pagination.type') === 'query_param')
            ->helperText('Để trống = trang 0 (tự bỏ qua nếu trống). Nhập số lớn hơn "Trang kết thúc" để cào ngược.');
    }

    private static function paginationEndInput(): TextInput
    {
        return TextInput::make('pagination.end_page')
            ->label('Trang kết thúc cào')
            ->numeric()
            ->placeholder('Tự động')
            ->visible(fn (callable $get) => $get('pagination.type') === 'query_param')
            ->helperText('Để trống = cào đến khi hết dữ liệu (tự dừng). Nhập số cụ thể để giới hạn.');
    }

    private static function paginationNextSelectorInput(): TextInput
    {
        return TextInput::make('pagination.next_selector')
            ->label('CSS selector liên kết phân trang')
            ->placeholder('.pagination a.next, a[rel="next"], .pagination a.prev')
            ->visible(fn (callable $get) => $get('pagination.type') === 'next_link')
            ->required(fn (callable $get) => $get('pagination.type') === 'next_link')
            ->helperText('Selector thẻ <a> phân trang. Cào ngược: dùng selector nút "Trang trước" thay vì "Tiếp".');
    }

    private static function paginationMaxPagesInput(): TextInput
    {
        return TextInput::make('pagination.max_pages')
            ->label('Giới hạn số trang')
            ->numeric()
            ->default(100)
            ->minValue(1)
            ->maxValue(500)
            ->visible(fn (callable $get) => in_array($get('pagination.type'), ['next_link', 'query_param']))
            ->helperText('Tối đa số trang sẽ scrape để tránh loop vô tận. Mặc định: 100.');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Import Defaults Components
    // ═══════════════════════════════════════════════════════════════════════



    private static function defaultStoryOriginSelect(): Select
    {
        return Select::make('import_defaults.story_origin')
            ->label('Nguồn gốc')
            ->options(StoryOrigin::options())
            ->default(StoryOrigin::default()->value)
            ->native(false)
            ->helperText('VD: Trung Quốc, Việt Nam, Hàn Quốc…');
    }

    private static function defaultStoryStatusSelect(): Select
    {
        return Select::make('import_defaults.story_status')
            ->label('Trạng thái truyện')
            ->options(StoryStatus::options())
            ->default(StoryStatus::default()->value)
            ->native(false)
            ->helperText('VD: Đang ra, Hoàn thành…');
    }

    private static function defaultCategoriesSelect(): Select
    {
        return Select::make('import_defaults.category_ids')
            ->label('Thể loại')
            ->options(fn () => \App\Models\Category::orderBy('name')->pluck('name', 'id')->toArray())
            ->multiple()
            ->searchable()
            ->native(false)
            ->helperText('Chọn cụ thể → gán cho tất cả truyện. Để trống → tự match.');
    }

    private static function defaultPrimaryCategorySelect(): Select
    {
        return Select::make('import_defaults.primary_category_id')
            ->label('Thể loại điều hướng')
            ->options(fn () => \App\Models\Category::orderBy('name')->pluck('name', 'id')->toArray())
            ->searchable()
            ->native(false)
            ->helperText('Chọn cụ thể → gán cho tất cả. Để trống → dùng thể loại đầu tiên match được.');
    }

    private static function defaultAuthorSelect(): Select
    {
        return Select::make('import_defaults.author_id')
            ->label('Tác giả')
            ->options(fn () => \App\Models\Author::orderBy('name')->pluck('name', 'id')->toArray())
            ->searchable()
            ->placeholder('-- Để trống = tự động match --')
            ->helperText('Chọn cụ thể → gán cho tất cả. Để trống → tự match theo tên.');
    }

    private static function defaultPublishedToggle(): Toggle
    {
        return Toggle::make('import_defaults.is_published')
            ->label('Xuất bản ngay')
            ->default(false)
            ->helperText('Tắt = import ở trạng thái nháp (an toàn hơn).');
    }

    private static function defaultFeaturedToggle(): Toggle
    {
        return Toggle::make('import_defaults.is_featured')
            ->label('Nổi bật')
            ->default(false);
    }

    private static function defaultHotToggle(): Toggle
    {
        return Toggle::make('import_defaults.is_hot')
            ->label('Hot')
            ->default(false);
    }

    private static function defaultVipToggle(): Toggle
    {
        return Toggle::make('import_defaults.is_vip')
            ->label('VIP')
            ->default(false);
    }

    private static function defaultChapterPublishedToggle(): Toggle
    {
        return Toggle::make('import_defaults.is_published')
            ->label('Xuất bản chương ngay')
            ->default(false)
            ->helperText('Bật = xuất bản ngay khi import. Tắt = nháp (review trước khi xuất bản).');
    }

    private static function sequentialNumberingToggle(): Toggle
    {
        return Toggle::make('import_defaults.sequential_numbering')
            ->label('Đánh số chương xuyên suốt')
            ->default(false)
            ->visible(fn (callable $get) => in_array($get('entity_type'), [
                ScrapeJob::ENTITY_CHAPTER,
            ]))
            ->helperText('Dùng khi truyện đánh số chương theo quyển (VD: mỗi quyển đánh lại từ Chương 1). Bật = bỏ qua số chương từ tiêu đề, đánh số tăng dần 1, 2, 3… theo thứ tự thu thập.');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Chapter Detail Config Components
    // ═══════════════════════════════════════════════════════════════════════

    private static function singleChapterModeToggle(): Toggle
    {
        return Toggle::make('detail_config.single_chapter_mode')
            ->label('Chỉ cào 1 chương')
            ->default(false)
            ->live()
            ->helperText('Bật = chỉ cào trang hiện tại (1 chương) rồi dừng. Tắt = cào chuỗi chương qua link "Chương tiếp".');
    }

    private static function chainSelectorInput(): TextInput
    {
        return TextInput::make('detail_config.chain_selector')
            ->label('🔗 CSS selector nút "Chương tiếp"')
            ->placeholder('a[rel="next"], .btn-next-chapter')
            ->visible(fn (callable $get) => $get('entity_type') === ScrapeJob::ENTITY_CHAPTER_DETAIL
                && ! $get('detail_config.single_chapter_mode'))
            ->required(fn (callable $get) => $get('entity_type') === ScrapeJob::ENTITY_CHAPTER_DETAIL
                && ! $get('detail_config.single_chapter_mode'))
            ->helperText('CSS selector của nút/link "Chương tiếp theo". VD: a[rel="next"], .next-chapter a');
    }

    private static function chainMaxChaptersInput(): TextInput
    {
        return TextInput::make('detail_config.chain_max_chapters')
            ->label('Giới hạn số chương')
            ->numeric()
            ->default(5000)
            ->minValue(1)
            ->maxValue(10000)
            ->visible(fn (callable $get) => $get('entity_type') === ScrapeJob::ENTITY_CHAPTER_DETAIL
                && ! $get('detail_config.single_chapter_mode'))
            ->helperText('Tối đa số chương. Mặc định: 5000. Tự dừng khi hết chương.');
    }

    private static function chainBatchSizeInput(): TextInput
    {
        return TextInput::make('detail_config.chain_batch_size')
            ->label('Số chương mỗi lượt (batch)')
            ->numeric()
            ->default(50)
            ->minValue(5)
            ->maxValue(500)
            ->visible(fn (callable $get) => $get('entity_type') === ScrapeJob::ENTITY_CHAPTER_DETAIL
                && ! $get('detail_config.single_chapter_mode'))
            ->helperText('Mỗi lượt cron cào N chương rồi dừng. Lượt sau tiếp tục. Chạy thủ công = không giới hạn.');
    }

    private static function detailContentSelectorInput(): TextInput
    {
        return TextInput::make('detail_config.content_selector')
            ->label('Content selector')
            ->placeholder('.chapter-content, .khung-chinh')
            ->helperText('CSS selector vùng chứa nội dung chương. Có → CSS xử lý (không gọi AI). Trống → dùng AI.');
    }

    private static function detailRemoveSelectorsInput(): Textarea
    {
        return Textarea::make('detail_config.remove_selectors')
            ->label('Selectors cần loại bỏ')
            ->placeholder(".ads\n.chapter-nav\nscript\n.truyen:last-child")
            ->rows(4)
            ->helperText('Mỗi dòng 1 CSS selector — xóa khỏi trang trước khi lấy nội dung.');
    }

    private static function detailRemoveTextPatternsInput(): Textarea
    {
        return Textarea::make('detail_config.remove_text_patterns')
            ->label('Chuỗi text cần loại bỏ')
            ->placeholder("▲        Chương trình ủng hộ thương hiệu Việt...\nNguồn: tangthuvien.vn")
            ->rows(4)
            ->helperText('Mỗi dòng 1 chuỗi text — xóa khỏi nội dung sau khi extract. Dùng khi CSS không xóa được.');
    }

    private static function detailTitleSelectorInput(): TextInput
    {
        return TextInput::make('detail_config.title_selector')
            ->label('Title selector (tùy chọn)')
            ->placeholder('h1.chapter-title, p.hoi')
            ->helperText('Để trống → giữ nguyên title từ mục lục.');
    }

    private static function detailChapterNumberPatternInput(): TextInput
    {
        return TextInput::make('detail_config.chapter_number_selector')
            ->label('Chapter number selector (tùy chọn)')
            ->placeholder('.chapter-num')
            ->helperText('Để trống → giữ nguyên số chương từ mục lục.');
    }

    private static function detailVolumeSelectorInput(): TextInput
    {
        return TextInput::make('detail_config.volume_selector')
            ->label('Volume/Quyển selector (tùy chọn)')
            ->placeholder('.volume-name, .book-num')
            ->helperText('Để trống → không gán quyển (giữ nguyên giá trị mặc định khi tạo chương).');
    }

    private static function detailAiPromptTextarea(): Textarea
    {
        return Textarea::make('detail_config.ai_prompt')
            ->label('AI Prompt cho trang chi tiết')
            ->rows(6)
            ->placeholder("Trích xuất nội dung chương từ HTML.\nLấy: content (HTML nội dung chương), title, chapter_number, volume_number (nếu có).\nLoại bỏ: quảng cáo, điều hướng, scripts.\nTrả về JSON object.")
            ->visible(fn (callable $get) => self::selectedSourceUsesAi($get))
            ->helperText('⚠️ Chỉ hoạt động khi KHÔNG điền Content selector. Có CSS → prompt bị bỏ qua. Ưu tiên CSS (nhanh, miễn phí).');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Schedule Components
    // ═══════════════════════════════════════════════════════════════════════

    private static function scheduleToggle(): Toggle
    {
        return Toggle::make('is_scheduled')
            ->label('Bật lịch chạy tự động')
            ->default(false)
            ->live()
            ->helperText('Bật = hệ thống tự động thu thập lại theo lịch.');
    }

    private static function autoImportToggle(): Toggle
    {
        return Toggle::make('auto_import')
            ->label('Tự động import sau khi thu thập')
            ->default(false)
            ->visible(fn (callable $get) => (bool) $get('is_scheduled'))
            ->helperText('Bật = sau khi thu thập, tự chọn tất cả items nháp và import. Tắt = import thủ công.');
    }

    private static function scheduleAutoFetchContentToggle(): Toggle
    {
        return Toggle::make('detail_config.auto_fetch_content')
            ->label('Tự động fetch nội dung chương')
            ->default(true)
            ->visible(fn (callable $get) => (bool) $get('is_scheduled')
                && $get('entity_type') === ScrapeJob::ENTITY_CHAPTER)
            ->helperText('Bật = tự động fetch nội dung chương. Tắt = chỉ lấy danh sách.');
    }

    private static function scheduleFetchBatchSizeInput(): TextInput
    {
        return TextInput::make('detail_config.fetch_batch_size')
            ->label('Số chương mỗi batch')
            ->numeric()
            ->default(20)
            ->minValue(1)
            ->maxValue(100)
            ->visible(fn (callable $get) => (bool) $get('is_scheduled')
                && $get('entity_type') === ScrapeJob::ENTITY_CHAPTER)
            ->helperText('Fetch theo batch để tránh quá tải. Giảm xuống 5–10 nếu nguồn dùng AI.');
    }

    private static function scheduleFrequencySelect(): Select
    {
        return Select::make('schedule_frequency')
            ->label('Tần suất')
            ->options(ScheduleFrequency::options())
            ->required(fn (callable $get) => (bool) $get('is_scheduled'))
            ->visible(fn (callable $get) => (bool) $get('is_scheduled'))
            ->live()
            ->native(false);
    }

    private static function scheduleTimeInput(): TextInput
    {
        return TextInput::make('schedule_time')
            ->label('Giờ chạy')
            ->placeholder('08:00')
            ->visible(fn (callable $get) => $get('is_scheduled') && in_array($get('schedule_frequency'), ['daily', 'weekly', 'monthly']))
            ->required(fn (callable $get) => $get('is_scheduled') && in_array($get('schedule_frequency'), ['daily', 'weekly', 'monthly']))
            ->helperText('Định dạng HH:MM (24h). VD: 08:00, 23:30.')
            ->regex('/^\d{2}:\d{2}$/');
    }

    private static function scheduleDayOfWeekSelect(): Select
    {
        return Select::make('schedule_day_of_week')
            ->label('Ngày trong tuần')
            ->options([
                1 => 'Thứ Hai',
                2 => 'Thứ Ba',
                3 => 'Thứ Tư',
                4 => 'Thứ Năm',
                5 => 'Thứ Sáu',
                6 => 'Thứ Bảy',
                0 => 'Chủ Nhật',
            ])
            ->default(1)
            ->visible(fn (callable $get) => $get('is_scheduled') && $get('schedule_frequency') === 'weekly')
            ->required(fn (callable $get) => $get('is_scheduled') && $get('schedule_frequency') === 'weekly')
            ->native(false);
    }

    private static function scheduleDayOfMonthSelect(): Select
    {
        return Select::make('schedule_day_of_month')
            ->label('Ngày trong tháng')
            ->options(array_combine(range(1, 31), range(1, 31)))
            ->default(1)
            ->visible(fn (callable $get) => $get('is_scheduled') && $get('schedule_frequency') === 'monthly')
            ->required(fn (callable $get) => $get('is_scheduled') && $get('schedule_frequency') === 'monthly')
            ->native(false);
    }

    private static function scheduleInfoPlaceholder(): TextEntry
    {
        return TextEntry::make('schedule_info')
            ->label('')
            ->state(function (callable $get, $record) {
                if (! $get('is_scheduled')) {
                    return '💡 Bật toggle để cài đặt lịch chạy tự động.';
                }

                if (! $record || ! $record->last_scheduled_at) {
                    return '🕐 Chưa có lần chạy tự động nào.';
                }

                return '✅ Lần chạy tự động gần nhất: ' . $record->last_scheduled_at->format('d/m/Y H:i');
            });
    }
}
