<?php

declare(strict_types=1);

namespace App\Filament\Resources\Chapters\Schemas;

use App\Models\Chapter;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\RichEditor;
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
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class ChapterForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('Tabs')
                ->tabs([
                    self::infoTab(),
                    self::contentTab(),
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

    private static function infoTab(): Tab
    {
        return Tab::make('Thông tin chương')
            ->icon(Heroicon::OutlinedInformationCircle)
            ->schema([
                self::storyAndNumberSection(),
                self::titleSection(),
            ]);
    }

    private static function contentTab(): Tab
    {
        return Tab::make('Nội dung')
            ->icon(Heroicon::OutlinedDocumentText)
            ->schema([
                self::contentSection(),
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

    private static function storyAndNumberSection(): Section
    {
        return Section::make('Truyện & Số chương')
            ->schema([
                // Edit mode: show story as a prominent read-only label
                Placeholder::make('story_label')
                    ->label('Truyện')
                    ->content(fn (?Chapter $record): ?HtmlString => $record?->story
                        ? new HtmlString(
                            '<span class="text-lg font-semibold text-primary-600 dark:text-primary-400">'
                            . '📖 ' . e($record->story->title)
                            . '</span>'
                        )
                        : null)
                    ->columnSpanFull()
                    ->visibleOn('edit'),

                // Create mode: searchable story select
                self::storySelect(),

                Grid::make(4)->schema([
                    self::chapterNumberInput(),
                    self::subChapterInput(),
                    self::volumeNumberInput(),
                    self::wordCountInput(),
                ]),
            ]);
    }

    private static function titleSection(): Section
    {
        return Section::make('Tiêu đề')
            ->schema([
                self::titleInput(),
                self::slugInput(),
            ])
            ->columns(2);
    }

    private static function contentSection(): Section
    {
        return Section::make('Nội dung chương')
            ->relationship('content')
            ->description(new HtmlString('<span class="text-xs text-gray-500 dark:text-gray-400">Nội dung sẽ được lưu trong bảng chapter_contents</span>'))
            ->schema([
                self::contentEditor(),
            ])
            ->columns(1);
    }

    private static function publishingSection(): Section
    {
        return Section::make('Trạng thái xuất bản')
            ->schema([
                Grid::make(3)->schema([
                    self::publishedToggle(),
                    self::vipToggle(),
                    self::freePreviewToggle(),
                ]),
                Grid::make(2)->schema([
                    self::publishedAtPicker(),
                    self::scheduledAtPicker(),
                ]),
            ])
            ->columns(1);
    }

    private static function seoSection(): Section
    {
        return Section::make('Tối ưu SEO')
            ->schema([
                self::metaTitleInput(),
                self::metaDescriptionTextarea(),
            ])
            ->columns(1);
    }

    private static function statisticsSection(): Section
    {
        return Section::make('Thống kê chương')
            ->description(new HtmlString('<span class="text-xs text-gray-500 dark:text-gray-400">Các số liệu được cập nhật tự động</span>'))
            ->schema([
                Grid::make(3)->schema([
                    self::viewCountDisplay(),
                    self::commentCountDisplay(),
                    self::wordCountDisplay(),
                ]),
            ])
            ->columns(1);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Info Tab Components
    // ═══════════════════════════════════════════════════════════════════════

    private static function storySelect(): Select
    {
        return Select::make('story_id')
            ->label('Truyện')
            ->relationship('story', 'title')
            ->searchable()
            ->preload()
            ->required()
            ->columnSpanFull()
            ->hiddenOn('edit');
    }

    private static function chapterNumberInput(): TextInput
    {
        return TextInput::make('chapter_number')
            ->label('Số chương')
            ->required()
            ->maxLength(20)
            ->rules(['regex:/^\d+(\.\d+)?[a-zA-Z]?$/'])
            ->helperText(new HtmlString('<span class="text-xs">VD: 1, 1.5, 1a, 2</span>'));
    }

    private static function subChapterInput(): TextInput
    {
        return TextInput::make('sub_chapter')
            ->label('Phần')
            ->numeric()
            ->default(0)
            ->helperText(new HtmlString('<span class="text-xs">0 = không có phần</span>'));
    }

    private static function volumeNumberInput(): TextInput
    {
        return TextInput::make('volume_number')
            ->label('Quyển')
            ->numeric()
            ->default(1)
            ->helperText(new HtmlString('<span class="text-xs">Số quyển/tập</span>'));
    }

    private static function wordCountInput(): TextInput
    {
        return TextInput::make('word_count')
            ->label('Số từ')
            ->numeric()
            ->default(0)
            ->helperText(new HtmlString('<span class="text-xs">Tự động đếm</span>'));
    }

    private static function titleInput(): TextInput
    {
        return TextInput::make('title')
            ->label('Tiêu đề chương')
            ->maxLength(500)
            ->live(onBlur: true)
            ->afterStateUpdated(function ($state, callable $set, $get) {
                if (!$get('slug') && $state) {
                    $set('slug', Str::slug($state));
                }
            })
            ->helperText(new HtmlString('<span class="text-xs">Có thể để trống nếu chỉ dùng số chương</span>'));
    }

    private static function slugInput(): TextInput
    {
        return TextInput::make('slug')
            ->label('Slug (URL)')
            ->maxLength(500)
            ->helperText(new HtmlString('<span class="text-xs">Tự động tạo từ tiêu đề</span>'));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Content Tab Components
    // ═══════════════════════════════════════════════════════════════════════

    private static function contentEditor(): RichEditor
    {
        return RichEditor::make('content')
            ->label('Nội dung')
            ->helperText(new HtmlString('<span class="text-xs">Nội dung chi tiết của chương</span>'));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Publishing Tab Components
    // ═══════════════════════════════════════════════════════════════════════

    private static function publishedToggle(): Toggle
    {
        return Toggle::make('is_published')
            ->label('Đã xuất bản')
            ->default(false)
            ->helperText(new HtmlString('<span class="text-xs">Hiển thị công khai trên website</span>'));
    }

    private static function vipToggle(): Toggle
    {
        return Toggle::make('is_vip')
            ->label('Chương VIP')
            ->default(false)
            ->helperText(new HtmlString('<span class="text-xs">Yêu cầu trả phí để đọc</span>'));
    }

    private static function freePreviewToggle(): Toggle
    {
        return Toggle::make('is_free_preview')
            ->label('Xem trước miễn phí')
            ->default(false)
            ->helperText(new HtmlString('<span class="text-xs">Cho phép xem trước miễn phí</span>'));
    }

    private static function publishedAtPicker(): DateTimePicker
    {
        return DateTimePicker::make('published_at')
            ->label('Ngày xuất bản')
            ->helperText(new HtmlString('<span class="text-xs">Để trống sẽ dùng thời điểm lưu</span>'));
    }

    private static function scheduledAtPicker(): DateTimePicker
    {
        return DateTimePicker::make('scheduled_at')
            ->label('Lên lịch xuất bản')
            ->helperText(new HtmlString('<span class="text-xs">Đặt lịch tự động xuất bản</span>'));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // SEO Tab Components
    // ═══════════════════════════════════════════════════════════════════════

    private static function metaTitleInput(): TextInput
    {
        return TextInput::make('meta_title')
            ->label('Meta Title')
            ->maxLength(60)
            ->helperText(new HtmlString('<span class="text-xs">Tối đa 60 ký tự</span>'));
    }

    private static function metaDescriptionTextarea(): Textarea
    {
        return Textarea::make('meta_description')
            ->label('Meta Description')
            ->maxLength(160)
            ->rows(2)
            ->helperText(new HtmlString('<span class="text-xs">Tối đa 160 ký tự</span>'));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Statistics Tab Components
    // ═══════════════════════════════════════════════════════════════════════

    private static function viewCountDisplay(): TextInput
    {
        return TextInput::make('view_count')
            ->label('Lượt xem')
            ->disabled()
            ->dehydrated(false);
    }

    private static function commentCountDisplay(): TextInput
    {
        return TextInput::make('comment_count')
            ->label('Bình luận')
            ->disabled()
            ->dehydrated(false);
    }

    private static function wordCountDisplay(): TextInput
    {
        return TextInput::make('word_count')
            ->label('Số từ')
            ->disabled()
            ->dehydrated(false);
    }
}
