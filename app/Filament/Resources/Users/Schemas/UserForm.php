<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Schemas;

use Awcodes\Curator\Components\Forms\CuratorPicker;
use App\Models\Role;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rules\Password;


class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('Tabs')
                ->tabs([
                    self::accountTab(),
                    self::securityTab(),
                    self::permissionTab(),
                ])
                ->columnSpanFull()
                ->persistTabInQueryString(),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Tabs
    // ═══════════════════════════════════════════════════════════════════════

    private static function accountTab(): Tab
    {
        return Tab::make('Thông tin tài khoản')
            ->icon(Heroicon::OutlinedUserCircle)
            ->schema([
                self::accountSection(),
            ]);
    }

    private static function securityTab(): Tab
    {
        return Tab::make('Bảo mật')
            ->icon(Heroicon::OutlinedShieldCheck)
            ->schema([
                self::passwordSection(),
            ]);
    }

    private static function permissionTab(): Tab
    {
        return Tab::make('Phân quyền')
            ->icon(Heroicon::OutlinedKey)
            ->schema([
                self::permissionSection(),
            ])
            ->visible(fn () => Auth::user()?->hasRole('super_admin') || Auth::user()?->can('assign_roles'));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Sections
    // ═══════════════════════════════════════════════════════════════════════

    private static function accountSection(): Section
    {
        return Section::make()
            ->schema([
                self::avatarUpload(),
                self::nameInput(),
                self::emailInput(),
                self::activeToggle(),
                self::vipToggle(),
                self::authorToggle(),
            ])
            ->columns(2);
    }

    private static function passwordSection(): Section
    {
        return Section::make()
            ->schema([
                self::passwordInput(),
                self::passwordConfirmationInput(),
            ])
            ->columns(2);
    }

    private static function permissionSection(): Section
    {
        return Section::make()
            ->schema([
                self::rolesSelect(),
            ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Account Components
    // ═══════════════════════════════════════════════════════════════════════

    private static function avatarUpload(): CuratorPicker
    {
        return CuratorPicker::make('avatar_id')
            ->label('Avatar')
            ->relationship('avatar', 'id')
            ->buttonLabel('Chọn Avatar')
            ->acceptedFileTypes(['image/*'])
            ->columnSpanFull();
    }

    private static function nameInput(): TextInput
    {
        return TextInput::make('name')
            ->label('Họ tên')
            ->required()
            ->maxLength(255);
    }

    private static function emailInput(): TextInput
    {
        return TextInput::make('email')
            ->label('Email')
            ->email()
            ->required()
            ->unique(ignoreRecord: true)
            ->maxLength(255);
    }

    private static function activeToggle(): Toggle
    {
        return Toggle::make('is_active')
            ->label('Kích hoạt')
            ->default(true)
            ->helperText(new HtmlString('<span class="text-xs">Cho phép đăng nhập và sử dụng</span>'));
    }

    private static function vipToggle(): Toggle
    {
        return Toggle::make('is_vip')
            ->label('VIP')
            ->helperText(new HtmlString('<span class="text-xs">Quyền truy cập nội dung VIP</span>'));
    }

    private static function authorToggle(): Toggle
    {
        return Toggle::make('is_author')
            ->label('Là tác giả')
            ->helperText(new HtmlString('<span class="text-xs">Cho phép đăng truyện và quản lý chương</span>'));
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Password Components
    // ═══════════════════════════════════════════════════════════════════════

    private static function passwordInput(): TextInput
    {
        return TextInput::make('password')
            ->label('Mật khẩu')
            ->password()
            ->revealable()
            ->dehydrateStateUsing(fn (string $state): string => Hash::make($state))
            ->dehydrated(fn (?string $state): bool => filled($state))
            ->required(fn (string $operation): bool => $operation === 'create')
            ->rule(Password::default());
    }

    private static function passwordConfirmationInput(): TextInput
    {
        return TextInput::make('password_confirmation')
            ->label('Xác nhận mật khẩu')
            ->password()
            ->revealable()
            ->same('password')
            ->requiredWith('password');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Permission Components
    // ═══════════════════════════════════════════════════════════════════════

    private static function rolesSelect(): Select
    {
        return Select::make('roles')
            ->label('Vai trò')
            ->relationship('roles', 'name')
            ->getOptionLabelFromRecordUsing(fn ($record) => $record->display_label)
            ->multiple()
            ->preload()
            ->searchable()
            ->disabled(fn ($record) => $record?->id === Auth::id())
            ->helperText(
				fn ($record) => $record?->id === Auth::id()
                	? new HtmlString('<span class="text-xs text-danger-500">Bạn không thể thay đổi vai trò của chính mình.</span>')
                	: new HtmlString('<span class="text-xs">Gán vai trò để phân quyền truy cập</span>')
			);
    }
}
