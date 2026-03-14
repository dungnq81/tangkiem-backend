<?php

declare(strict_types=1);

namespace App\Filament\Resources\Categories\Schemas;

use App\Filament\Support\StatCard;
use App\Models\Category;
use Awcodes\Curator\Components\Forms\CuratorPicker;
use Filament\Forms\Components\ColorPicker;
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
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class CategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('Tabs')
                ->tabs([
                    self::basicInfoTab(),
                    self::appearanceTab(),
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

    private static function appearanceTab(): Tab
    {
        return Tab::make('Giao diện')
            ->icon(Heroicon::OutlinedPaintBrush)
            ->schema([
                self::appearanceSection(),
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
                    self::parentSelect(),
                    self::sortOrderInput(),
                ]),
                self::descriptionTextarea(),
            ])
            ->columns(1);
    }

    private static function appearanceSection(): Section
    {
        return Section::make()
            ->schema([
                Grid::make(2)->schema([
                    self::iconInput(),
                    self::colorPicker(),
                    self::imageIdInput(),
                ]),
            ])
            ->columns(1);
    }

    private static function seoSection(): Section
    {
        return Section::make()
            ->schema([
                self::metaTitleInput(),
                self::metaDescriptionTextarea(),
            ])
            ->columns(1);
    }

    private static function statusSection(): Section
    {
        return Section::make()
            ->schema([
                Grid::make(3)->schema([
                    self::activeToggle(),
                    self::featuredToggle(),
                    self::showInMenuToggle(),
                ]),
                TextEntry::make('category_statistics')
                    ->hiddenLabel()
                    ->state(function (?Category $record): HtmlString {
                        if (! $record) {
                            return StatCard::empty();
                        }

                        return StatCard::grid([
                            [
                                StatCard::item('Số truyện', $record->stories_count ?? 0, '📚', 'blue'),
                                StatCard::item('Số thể loại con', $record->children_count ?? 0, '📂', 'purple'),
                                StatCard::item('Cấp độ', $record->depth ?? 0, '🎯', 'gray'),
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
            ->label('Tên thể loại')
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

    private static function parentSelect(): Select
    {
        return Select::make('parent_id')
            ->label('Thể loại cha')
            ->relationship('parent', 'name')
            ->searchable()
            ->preload()
            ->placeholder('-- Thể loại gốc --')
            ->helperText(new HtmlString('<span class="text-xs">Để trống nếu là thể loại gốc</span>'));
    }

    private static function sortOrderInput(): TextInput
    {
        return TextInput::make('sort_order')
            ->label('Thứ tự')
            ->numeric()
            ->default(0)
            ->helperText(new HtmlString('<span class="text-xs">Số nhỏ hiển thị trước</span>'));
    }

    private static function descriptionTextarea(): Textarea
    {
        return Textarea::make('description')
            ->label('Mô tả')
            ->maxLength(500)
            ->rows(3)
            ->columnSpanFull()
            ->helperText(new HtmlString('<span class="text-xs">Mô tả ngắn về thể loại</span>'));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Appearance Components
    // ═══════════════════════════════════════════════════════════════════════

    private static function iconInput(): TextInput
    {
        return TextInput::make('icon')
            ->label('Icon')
            ->maxLength(100)
            ->placeholder('heroicon-o-sparkles')
            ->helperText(new HtmlString('<span class="text-xs">Heroicon name hoặc emoji</span>'));
    }

    private static function colorPicker(): ColorPicker
    {
        return ColorPicker::make('color')
            ->label('Màu sắc');
    }

    private static function imageIdInput(): CuratorPicker
    {
        return CuratorPicker::make('image_id')
            ->label('Ảnh đại diện')
            ->buttonLabel('Chọn ảnh')
            ->size('sm')
            ->constrained();
    }

    // ═══════════════════════════════════════════════════════════════════════
    // SEO Components
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
    // Status Components
    // ═══════════════════════════════════════════════════════════════════════

    private static function activeToggle(): Toggle
    {
        return Toggle::make('is_active')
            ->label('Kích hoạt')
            ->default(true)
            ->helperText(new HtmlString('<span class="text-xs">Hiển thị trên website</span>'));
    }

    private static function featuredToggle(): Toggle
    {
        return Toggle::make('is_featured')
            ->label('Nổi bật')
            ->default(false)
            ->helperText(new HtmlString('<span class="text-xs">Hiển thị ở trang chủ</span>'));
    }

    private static function showInMenuToggle(): Toggle
    {
        return Toggle::make('show_in_menu')
            ->label('Hiển thị menu')
            ->default(true)
            ->helperText(new HtmlString('<span class="text-xs">Hiển thị trong menu điều hướng</span>'));
    }
}
