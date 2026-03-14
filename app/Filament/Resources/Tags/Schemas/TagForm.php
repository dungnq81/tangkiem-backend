<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tags\Schemas;

use App\Enums\TagType;
use App\Filament\Support\StatCard;
use App\Models\Tag;
use Filament\Forms\Components\ColorPicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class TagForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            self::basicInfoSection(),
            self::statusSection(),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Sections
    // ═══════════════════════════════════════════════════════════════════════

    private static function basicInfoSection(): Section
    {
        return Section::make('Thông tin cơ bản')
            ->schema([
                Grid::make(2)->schema([
                    self::nameInput(),
                    self::slugInput(),
                    self::typeSelect(),
                    self::colorPicker(),
                ]),
                self::descriptionTextarea(),
            ])
            ->columns(1);
    }

    private static function statusSection(): Section
    {
        return Section::make('Trạng thái & Thống kê')
            ->schema([
                Grid::make(2)->schema([
                    self::activeToggle(),
                    TextEntry::make('tag_statistics')
                        ->hiddenLabel()
                        ->state(function (?Tag $record): HtmlString {
                            if (! $record) {
                                return StatCard::empty();
                            }

                            return StatCard::grid([
                                [
                                    StatCard::item('Số truyện', $record->stories_count ?? 0, '📚', 'blue'),
                                ],
                            ]);
                        }),
                ]),
            ])
            ->columns(1);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Form Components
    // ═══════════════════════════════════════════════════════════════════════

    private static function nameInput(): TextInput
    {
        return TextInput::make('name')
            ->label('Tên thẻ')
            ->required()
            ->maxLength(100)
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
            ->maxLength(100)
            ->unique(ignoreRecord: true)
            ->helperText(new HtmlString('<span class="text-xs">Tự động tạo từ tên, có thể chỉnh sửa</span>'));
    }

    private static function typeSelect(): Select
    {
        return Select::make('type')
            ->label('Loại thẻ')
            ->options(TagType::options())
            ->default(TagType::default()->value)
            ->required()
            ->native(false)
            ->helperText(new HtmlString('
                <ul class="text-xs list-disc pl-4 space-y-0.5 mt-1">
                    <li><strong>Tag</strong> — thẻ thường (mô tả nội dung)</li>
                    <li><strong>Cảnh báo</strong> — đánh dấu nội dung nhạy cảm</li>
                    <li><strong>Thuộc tính</strong> — đặc điểm truyện (VD: xuyên không, trọng sinh...)</li>
                </ul>
            '));
    }

    private static function colorPicker(): ColorPicker
    {
        return ColorPicker::make('color')
            ->label('Màu sắc')
            ->helperText(new HtmlString('<span class="text-xs">Màu hiển thị của thẻ trên website (tùy chọn)</span>'));
    }

    private static function descriptionTextarea(): Textarea
    {
        return Textarea::make('description')
            ->label('Mô tả')
            ->maxLength(500)
            ->rows(3)
            ->columnSpanFull()
            ->helperText(new HtmlString('<span class="text-xs">Mô tả ngắn về thẻ này</span>'));
    }

    private static function activeToggle(): Toggle
    {
        return Toggle::make('is_active')
            ->label('Kích hoạt')
            ->default(true)
            ->helperText(new HtmlString('<span class="text-xs">Hiển thị thẻ trên website</span>'));
    }
}
