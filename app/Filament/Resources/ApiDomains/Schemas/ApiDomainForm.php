<?php

declare(strict_types=1);

namespace App\Filament\Resources\ApiDomains\Schemas;

use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DateTimePicker;
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

class ApiDomainForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('Tabs')
                ->tabs([
                    self::basicInfoTab(),
                    self::settingsTab(),
                    self::apiGroupsTab(),
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

    private static function settingsTab(): Tab
    {
        return Tab::make('Cài đặt')
            ->icon(Heroicon::OutlinedCog6Tooth)
            ->schema([
                self::settingsSection(),
            ]);
    }

    private static function apiGroupsTab(): Tab
    {
        return Tab::make('Nhóm API')
            ->icon(Heroicon::OutlinedSquares2x2)
            ->schema([
                self::apiGroupsSection(),
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
                    TextInput::make('name')
                        ->label('Tên')
                        ->placeholder('TangKiem Frontend')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('domain')
                        ->label('Domain')
                        ->placeholder('tangkiem.com')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(255)
                        ->helperText(new HtmlString('<span class="text-xs">Không bao gồm <code class="bg-gray-100 dark:bg-gray-700 px-1 rounded">http://</code> hoặc <code class="bg-gray-100 dark:bg-gray-700 px-1 rounded">https://</code></span>')),
                ]),

                Section::make('API Keys')
                    ->schema([
                        TextInput::make('public_key')
                            ->label('Public Key (Browser)')
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText(new HtmlString('<span class="text-xs">Dùng cho client-side (browser). Frontend gửi kèm trong header để xác thực.</span>')),

                        TextInput::make('secret_key')
                            ->label('Secret Key (Server)')
                            ->disabled()
                            ->dehydrated(false)
                            ->password()
                            ->revealable()
                            ->helperText(new HtmlString('<span class="text-xs">Dùng cho server-to-server. Lưu trong <code class="bg-gray-100 dark:bg-gray-700 px-1 rounded">.env</code> của ứng dụng sử dụng API.</span>')),
                    ])
                    ->columns(2)
                    ->visibleOn('edit'),

                Textarea::make('notes')
                    ->label('Ghi chú')
                    ->rows(2),
            ])
            ->columns(1);
    }

    private static function settingsSection(): Section
    {
        return Section::make()
            ->schema([
                Grid::make(3)->schema([
                    Toggle::make('is_active')
                        ->label('Hoạt động')
                        ->default(true)
                        ->helperText(new HtmlString('<span class="text-xs">Tắt để vô hiệu hóa ngay lập tức</span>')),

                    DateTimePicker::make('valid_from')
                        ->label('Có hiệu lực từ')
                        ->native(false)
                        ->displayFormat('d/m/Y H:i'),

                    DateTimePicker::make('valid_until')
                        ->label('Hết hạn lúc')
                        ->native(false)
                        ->displayFormat('d/m/Y H:i')
                        ->helperText(new HtmlString('<span class="text-xs">Để trống = vĩnh viễn</span>')),
                ]),
            ])
            ->columns(1);
    }

    private static function apiGroupsSection(): Section
    {
        return Section::make()
            ->description(new HtmlString('<span class="text-xs text-gray-500 dark:text-gray-400">Chọn các nhóm API mà domain này được phép truy cập</span>'))
            ->schema(
                collect(config('api.groups', []))
                    ->map(fn ($group, $key) => Checkbox::make("allowed_groups_check.{$key}")
                        ->label($group['label'] ?? $key)
                        ->helperText(new HtmlString('<span class="text-xs">' . e($group['description'] ?? '') . '</span>'))
                    )
                    ->toArray()
            )
            ->columns(3);
    }
}

