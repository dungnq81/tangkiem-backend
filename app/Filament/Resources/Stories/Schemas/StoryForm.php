<?php

declare(strict_types=1);

namespace App\Filament\Resources\Stories\Schemas;

use App\Enums\StoryOrigin;
use App\Enums\StoryStatus;
use App\Filament\Support\StatCard;
use App\Models\Story;

use Awcodes\Curator\Components\Forms\CuratorPicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use App\Support\SeoLimits;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class StoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('Tabs')
                ->tabs([
                    self::basicInfoTab(),
                    self::contentTab(),
                    self::mediaTab(),
                    self::publishingTab(),
                    self::seoTab(),
                    self::statisticsTab(),
                ])
                ->columnSpanFull()
                ->persistTabInQueryString(),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Tabs
    // ═══════════════════════════════════════════════════════════════════════

    private static function basicInfoTab(): Tab
    {
        return Tab::make('Thông tin cơ bản')
            ->icon(Heroicon::OutlinedInformationCircle)
            ->schema([
                self::titleSection(),
                self::classificationSection(),
            ]);
    }

    private static function contentTab(): Tab
    {
        return Tab::make('Nội dung')
            ->icon(Heroicon::OutlinedDocumentText)
            ->schema([
                self::descriptionSection(),
            ]);
    }

    private static function mediaTab(): Tab
    {
        return Tab::make('Hình ảnh')
            ->icon(Heroicon::OutlinedPhoto)
            ->schema([
                self::mediaSection(),
            ]);
    }

    private static function publishingTab(): Tab
    {
        return Tab::make('Xuất bản')
            ->icon(Heroicon::OutlinedGlobeAlt)
            ->schema([
                self::publishingSection(),
            ]);
    }

    private static function seoTab(): Tab
    {
        return Tab::make('SEO')
            ->icon(Heroicon::OutlinedMagnifyingGlass)
            ->schema([
                self::seoSection(),
            ]);
    }

    private static function statisticsTab(): Tab
    {
        return Tab::make('Thống kê')
            ->icon(Heroicon::OutlinedChartBar)
            ->schema([
                self::statisticsSection(),
            ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Sections
    // ═══════════════════════════════════════════════════════════════════════

    private static function titleSection(): Section
    {
        return Section::make('Tiêu đề & URL')
            ->schema([
                self::titleInput(),
                self::slugInput(),
                self::alternativeTitlesInput(),
            ])
            ->columns(2);
    }

    private static function classificationSection(): Section
    {
        return Section::make('Phân loại')
            ->schema([
                Grid::make(2)->schema([
                    self::statusSelect(),
                    self::originSelect(),
                ]),
                Grid::make(2)->schema([
                    self::categoriesSelect(),
                   self::primaryCategorySelect(),
                ]),
                Grid::make(2)->schema([
                    self::authorSelect(),
					self::tagsSelect(),
                ]),
            ])
            ->columns(1);
    }

    private static function descriptionSection(): Section
    {
        return Section::make('Mô tả & Nội dung')
            ->schema([
                self::descriptionTextarea(),
                self::contentEditor(),
            ])
            ->columns(1);
    }

    private static function mediaSection(): Section
    {
        return Section::make('Hình ảnh')
            ->description(new HtmlString('<span class="text-xs text-gray-500 dark:text-gray-400">Upload hình ảnh bìa và banner cho truyện</span>'))
            ->schema([
                Grid::make(2)->schema([
                    CuratorPicker::make('cover_image_id')
                        ->label('Ảnh bìa')
                        ->relationship('coverImage', 'id')
                        ->buttonLabel('Chọn ảnh bìa')
                        ->acceptedFileTypes(['image/*']),
                    CuratorPicker::make('banner_id')
                        ->label('Banner')
                        ->relationship('banner', 'id')
                        ->buttonLabel('Chọn banner')
                        ->acceptedFileTypes(['image/*']),
                ]),
            ]);
    }

    private static function publishingSection(): Section
    {
        return Section::make('Trạng thái xuất bản')
            ->schema([
                Grid::make(3)->schema([
                    self::publishedToggle(),
                    self::featuredToggle(),
                    self::hotToggle(),
                ]),
                Grid::make(2)->schema([
                    self::vipToggle(),
                    self::lockedToggle(),
                ]),
                self::publishedAtPicker(),
            ])
            ->columns(1);
    }

    private static function seoSection(): Section
    {
        return Section::make('Tối ưu SEO')
            ->schema([
                self::metaTitleInput(),
                self::metaDescriptionTextarea(),
                self::metaKeywordsInput(),
                self::canonicalUrlInput(),
            ])
            ->columns(1);
    }

    private static function statisticsSection(): Section
    {
        return Section::make('Thống kê truyện')
            ->description(new HtmlString('<span class="text-xs text-gray-500 dark:text-gray-400">Các số liệu được cập nhật tự động</span>'))
            ->schema([
                TextEntry::make('statistics_display')
                    ->hiddenLabel()
                    ->state(function (?Story $record): HtmlString {
                        if (! $record) {
                            return StatCard::empty();
                        }

                        return StatCard::grid([
                            // Row 1: Core metrics
                            [
                                StatCard::item('Số chương', $record->total_chapters, '📚', 'blue'),
                                StatCard::item('Số từ', $record->total_word_count, '✏️', 'cyan'),
                                StatCard::item('Lượt xem', $record->view_count, '👁️', 'green'),
                                StatCard::item('Yêu thích', $record->favorite_count, '❤️', 'red'),
                            ],
                            // Row 2: Time-based views + comments
                            [
                                StatCard::item('Xem hôm nay', $record->view_count_day, '📅', 'amber'),
                                StatCard::item('Xem tuần', $record->view_count_week, '📊', 'purple'),
                                StatCard::item('Xem tháng', $record->view_count_month, '📈', 'pink'),
                                StatCard::item('Bình luận', $record->comment_count, '💬', 'blue'),
                            ],
                            // Row 3: Ratings + latest chapter
                            [
                                StatCard::item('Điểm đánh giá', $record->rating ? number_format((float) $record->rating, 1) . ' ⭐' : null, '⭐', 'amber'),
                                StatCard::item('Số đánh giá', $record->rating_count, '📝', 'gray'),
                                StatCard::item('Chương mới nhất', $record->latest_chapter_title, '📖', 'green'),
                            ],
                        ]);
                    })
                    ->columnSpanFull(),
            ])
            ->columns(1);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Title Section Components
    // ═══════════════════════════════════════════════════════════════════════

    private static function titleInput(): TextInput
    {
        return TextInput::make('title')
            ->label('Tiêu đề truyện')
            ->required()
            ->maxLength(500)
            ->live(onBlur: true)
            ->afterStateUpdated(function ($state, callable $set, $get) {
                if (!$get('slug')) {
                    $set('slug', Str::slug($state));
                }
            })
            ->columnSpanFull();
    }

    private static function slugInput(): TextInput
    {
        return TextInput::make('slug')
            ->label('Slug (URL)')
            ->required()
            ->maxLength(500)
            ->unique(ignoreRecord: true);
    }

    private static function alternativeTitlesInput(): TagsInput
    {
        return TagsInput::make('alternative_titles')
            ->label('Tên khác')
            ->placeholder('Nhập tên khác...');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Classification Section Components
    // ═══════════════════════════════════════════════════════════════════════

    private static function statusSelect(): Select
    {
        return Select::make('status')
            ->label('Trạng thái')
            ->options(StoryStatus::options())
            ->default(StoryStatus::default()->value)
            ->required()
            ->native(false);
    }

    private static function originSelect(): Select
    {
        return Select::make('origin')
            ->label('Nguồn gốc')
            ->options(StoryOrigin::options())
            ->default(StoryOrigin::default()->value)
            ->required()
            ->native(false);
    }

    private static function authorSelect(): Select
    {
        return Select::make('author_id')
            ->label('Tác giả')
            ->relationship('author', 'name')
            ->searchable()
            ->preload()
            ->createOptionForm([
                TextInput::make('name')
                    ->label('Tên tác giả')
                    ->required(),
                TextInput::make('slug')
                    ->label('Slug')
                    ->required(),
            ]);
    }

    private static function categoriesSelect(): Select
    {
        return Select::make('categories')
            ->label('Thể loại')
            ->relationship('categories', 'name')
            ->multiple()
            ->searchable()
            ->preload()
            ->required()
            ->live()
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
            ]);
    }

    private static function primaryCategorySelect(): Select
    {
        return Select::make('primary_category_id')
            ->label('Thể loại điều hướng')
            ->relationship('primaryCategory', 'name')
            ->searchable()
            ->preload()
            ->required();
    }

    private static function tagsSelect(): Select
    {
        return Select::make('tags')
            ->label('Tags')
            ->relationship('tags', 'name')
            ->multiple()
            ->searchable()
            ->preload()
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
            ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Content Section Components
    // ═══════════════════════════════════════════════════════════════════════

    private static function descriptionTextarea(): Textarea
    {
        return Textarea::make('description')
            ->label('Mô tả ngắn')
            ->maxLength(1000)
            ->rows(3);
    }

    private static function contentEditor(): RichEditor
    {
        return RichEditor::make('content')
            ->label('Nội dung chi tiết')
            ->plugins([

            ])
            ->toolbarButtons([
                'bold',
                'italic',
                'underline',
                'strike',
                'link',
                'h2',
                'h3',
                'blockquote',
                'codeBlock',
                'bulletList',
                'orderedList',
                'table',
                'attachFiles',

                'undo',
                'redo',
            ])
            ->fileAttachmentsDisk('public')
            ->fileAttachmentsDirectory('story-content');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Media Section Components
    // ═══════════════════════════════════════════════════════════════════════



    // ═══════════════════════════════════════════════════════════════════════
    // Publishing Section Components
    // ═══════════════════════════════════════════════════════════════════════

    private static function publishedToggle(): Toggle
    {
        return Toggle::make('is_published')
            ->label('Đã xuất bản')
            ->default(false)
            ->helperText(new HtmlString('<span class="text-xs">Hiển thị công khai trên website</span>'));
    }

    private static function featuredToggle(): Toggle
    {
        return Toggle::make('is_featured')
            ->label('Nổi bật')
            ->default(false)
            ->helperText(new HtmlString('<span class="text-xs">Hiển thị ở trang chủ</span>'));
    }

    private static function hotToggle(): Toggle
    {
        return Toggle::make('is_hot')
            ->label('Truyện hot')
            ->default(false)
            ->helperText(new HtmlString('<span class="text-xs">Đánh dấu truyện hot</span>'));
    }

    private static function vipToggle(): Toggle
    {
        return Toggle::make('is_vip')
            ->label('VIP')
            ->default(false)
            ->helperText(new HtmlString('<span class="text-xs">Yêu cầu VIP để đọc</span>'));
    }

    private static function lockedToggle(): Toggle
    {
        return Toggle::make('is_locked')
            ->label('Khóa')
            ->default(false)
            ->helperText(new HtmlString('<span class="text-xs">Tạm khóa, không cho đọc</span>'));
    }

    private static function publishedAtPicker(): DateTimePicker
    {
        return DateTimePicker::make('published_at')
            ->label('Ngày xuất bản')
            ->helperText(new HtmlString('<span class="text-xs">Để trống sẽ dùng thời điểm lưu</span>'));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // SEO Section Components
    // ═══════════════════════════════════════════════════════════════════════

    private static function metaTitleInput(): TextInput
    {
        return TextInput::make('meta_title')
            ->label('Meta Title')
            ->maxLength(SeoLimits::MAX_TITLE)
            ->helperText(new HtmlString('<span class="text-xs">Tối đa ' . SeoLimits::MAX_TITLE . ' ký tự (Google hiển thị tối ưu ~' . SeoLimits::PROMPT_TITLE . ')</span>'));
    }

    private static function metaDescriptionTextarea(): Textarea
    {
        return Textarea::make('meta_description')
            ->label('Meta Description')
            ->maxLength(SeoLimits::MAX_DESCRIPTION)
            ->rows(2)
            ->helperText(new HtmlString('<span class="text-xs">Tối đa ' . SeoLimits::MAX_DESCRIPTION . ' ký tự (Google hiển thị tối ưu ~' . SeoLimits::PROMPT_DESCRIPTION . ')</span>'));
    }

    private static function metaKeywordsInput(): TextInput
    {
        return TextInput::make('meta_keywords')
            ->label('Meta Keywords')
            ->maxLength(500)
            ->helperText(new HtmlString('<span class="text-xs">Từ khóa SEO, cách nhau bằng dấu phẩy</span>'));
    }

    private static function canonicalUrlInput(): TextInput
    {
        return TextInput::make('canonical_url')
            ->label('Canonical URL')
            ->url()
            ->maxLength(500)
            ->helperText(new HtmlString('<span class="text-xs">URL chính thức (tùy chọn)</span>'));
    }
}
