<?php

declare(strict_types=1);

namespace App\Filament\Resources\Authors\Schemas;

use App\Filament\Support\StatCard;
use App\Models\Author;
use Awcodes\Curator\Components\Forms\CuratorPicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
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

class AuthorForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('Tabs')
                ->tabs([
                    self::basicInfoTab(),
                    self::biographyTab(),
                    self::seoTab(),
                    self::statusTab(),
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
                self::basicInfoSection(),
            ]);
    }

    private static function biographyTab(): Tab
    {
        return Tab::make('Tiểu sử & Mạng xã hội')
            ->icon(Heroicon::OutlinedDocumentText)
            ->schema([
                self::biographySection(),
                self::socialLinksSection(),
            ]);
    }

    private static function seoTab(): Tab
    {
        return Tab::make('SEO')
            ->icon(Heroicon::OutlinedGlobeAlt)
            ->schema([
                self::seoSection(),
            ]);
    }

    private static function statusTab(): Tab
    {
        return Tab::make('Trạng thái')
            ->icon(Heroicon::OutlinedChartBar)
            ->schema([
                self::statusSection(),
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
                    self::nameInput(),
                    self::slugInput(),
                    self::originalNameInput(),
                ]),
                self::avatarPicker(),
            ])
            ->columns(1);
    }

    private static function biographySection(): Section
    {
        return Section::make('Tiểu sử & Mô tả')
            ->schema([
                self::bioEditor(),
                self::descriptionEditor(),
            ])
            ->columns(1);
    }

    private static function socialLinksSection(): Section
    {
        return Section::make('Liên kết mạng xã hội')
            ->schema([
                self::socialLinksRepeater(),
            ])
            ->columns(1);
    }

    private static function seoSection(): Section
    {
        return Section::make()
            ->schema([
                self::metaTitleInput(),
                self::metaDescriptionInput(),
            ])
            ->columns(1);
    }

    private static function statusSection(): Section
    {
        return Section::make()
            ->schema([
                Grid::make(2)->schema([
                    self::activeToggle(),
                    self::verifiedToggle(),
                ]),
                TextEntry::make('author_statistics')
                    ->hiddenLabel()
                    ->state(function (?Author $record): HtmlString {
                        if (! $record) {
                            return StatCard::empty();
                        }

                        return StatCard::grid([
                            [
                                StatCard::item('Số truyện', $record->stories_count ?? 0, '📚', 'blue'),
                                StatCard::item('Tổng lượt xem', $record->total_views ?? 0, '👁️', 'green'),
                                StatCard::item('Tổng số chương', $record->total_chapters ?? 0, '📖', 'cyan'),
                            ],
                        ]);
                    })
                    ->columnSpanFull(),
            ])
            ->columns(1);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Basic Info Components
    // ═══════════════════════════════════════════════════════════════════════

    private static function nameInput(): TextInput
    {
        return TextInput::make('name')
            ->label('Tên tác giả')
            ->required()
            ->maxLength(255)
            ->live(onBlur: true)
            ->afterStateUpdated(function ($state, callable $set, $get) {
                if (!$get('slug')) {
                    $set('slug', Str::slug($state));
                }
            });
    }

    private static function slugInput(): TextInput
    {
        return TextInput::make('slug')
            ->label('Slug (URL)')
            ->required()
            ->maxLength(255)
            ->unique(ignoreRecord: true)
            ->helperText(new HtmlString('<span class="text-xs">Tự động tạo từ tên, có thể chỉnh sửa</span>'));
    }

    private static function originalNameInput(): TextInput
    {
        return TextInput::make('original_name')
            ->label('Tên gốc')
            ->maxLength(255)
            ->helperText(new HtmlString('<span class="text-xs">Tên tiếng nước ngoài (nếu có)</span>'));
    }

    private static function avatarPicker(): CuratorPicker
    {
        return CuratorPicker::make('avatar_id')
            ->label('Avatar')
            ->relationship('avatar', 'id')
            ->buttonLabel('Chọn Avatar')
            ->acceptedFileTypes(['image/*'])
            ->helperText(new HtmlString('<span class="text-xs">Chọn hoặc tải lên hình ảnh đại diện</span>'));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Biography Components
    // ═══════════════════════════════════════════════════════════════════════

    private static function bioEditor(): RichEditor
    {
        return RichEditor::make('bio')
            ->label('Tiểu sử ngắn')
            ->maxLength(500)
            ->columnSpanFull()
            ->helperText(new HtmlString('<span class="text-xs">Mô tả ngắn gọn về tác giả (hiển thị trong danh sách)</span>'));
    }

    private static function descriptionEditor(): RichEditor
    {
        return RichEditor::make('description')
            ->label('Mô tả chi tiết')
            ->columnSpanFull()
            ->helperText(new HtmlString('<span class="text-xs">Thông tin chi tiết về tác giả (hiển thị trong trang chi tiết)</span>'));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Social Links Components
    // ═══════════════════════════════════════════════════════════════════════

    private static function socialLinksRepeater(): Repeater
    {
        return Repeater::make('social_links')
            ->label('Social Links')
            ->schema([
                TextInput::make('platform')
                    ->label('Nền tảng')
                    ->required()
                    ->placeholder('Facebook, Twitter, Website...'),
                TextInput::make('url')
                    ->label('URL')
                    ->required()
                    ->url()
                    ->placeholder('https://...'),
            ])
            ->columns(2)
            ->defaultItems(0)
            ->collapsible()
            ->itemLabel(fn (array $state): ?string => $state['platform'] ?? null);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // SEO Components
    // ═══════════════════════════════════════════════════════════════════════

    private static function metaTitleInput(): TextInput
    {
        return TextInput::make('meta_title')
            ->label('Meta Title')
            ->maxLength(SeoLimits::MAX_TITLE)
            ->helperText(new HtmlString('<span class="text-xs">Tối đa ' . SeoLimits::MAX_TITLE . ' ký tự (Google hiển thị tối ưu ~' . SeoLimits::PROMPT_TITLE . '). Để trống sẽ dùng tên tác giả</span>'));
    }

    private static function metaDescriptionInput(): TextInput
    {
        return TextInput::make('meta_description')
            ->label('Meta Description')
            ->maxLength(SeoLimits::MAX_DESCRIPTION)
            ->helperText(new HtmlString('<span class="text-xs">Tối đa ' . SeoLimits::MAX_DESCRIPTION . ' ký tự (Google hiển thị tối ưu ~' . SeoLimits::PROMPT_DESCRIPTION . ')</span>'));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Status Components
    // ═══════════════════════════════════════════════════════════════════════

    private static function activeToggle(): Toggle
    {
        return Toggle::make('is_active')
            ->label('Kích hoạt')
            ->default(true)
            ->helperText(new HtmlString('<span class="text-xs">Hiển thị tác giả trên website</span>'));
    }

    private static function verifiedToggle(): Toggle
    {
        return Toggle::make('is_verified')
            ->label('Xác minh')
            ->default(false)
            ->helperText(new HtmlString('<span class="text-xs">Tác giả đã được xác minh</span>'));
    }
}
