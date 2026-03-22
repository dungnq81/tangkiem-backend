<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;

use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use App\Http\Middleware\WebCronMiddleware;
use Awcodes\Curator\CuratorPlugin;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->viteTheme('resources/css/filament/admin/index.css')
            ->login(\App\Filament\Pages\Auth\AdminLogin::class)
            ->brandName('Tàng Kiếm')
            ->brandLogo(null)
            ->favicon(null)
            ->colors([
                'primary' => Color::Indigo,
                'danger' => Color::Rose,
                'gray' => Color::Slate,
                'info' => Color::Sky,
                'success' => Color::Emerald,
                'warning' => Color::Amber,
            ])
            ->font('Inter')
            ->darkMode(true)
            ->defaultThemeMode(\Filament\Enums\ThemeMode::Dark)
            ->sidebarCollapsibleOnDesktop(true)
            ->sidebarFullyCollapsibleOnDesktop(true)
            ->maxContentWidth('full')
            ->navigationGroups([
                NavigationGroup::make()
                    ->label('Thư viện')
                    ->icon('heroicon-o-photo')
                    ->collapsed(true),
                NavigationGroup::make()
                    ->label('Danh mục')
                    ->icon('heroicon-o-tag')
                    ->collapsed(true),
                NavigationGroup::make()
                    ->label('Nội dung')
                    ->icon('heroicon-o-book-open')
                    ->collapsed(true),
                NavigationGroup::make()
                    ->label('Thu thập dữ liệu')
                    ->icon('heroicon-o-globe-alt')
                    ->collapsed(true),
                NavigationGroup::make()
                    ->label('Quản lý hệ thống')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->collapsed(true),
                NavigationGroup::make()
                    ->label('Phân quyền')
                    ->icon('heroicon-o-shield-check')
                    ->collapsed(true),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                WebCronMiddleware::class,
            ])
            ->plugins([
                FilamentShieldPlugin::make(),
                CuratorPlugin::make()
                    ->label('Media')
                    ->pluralLabel('Media')
                    ->navigationGroup('Thư viện')
                    ->navigationIcon('heroicon-o-photo')
                    ->navigationSort(99)
                    ->showBadge(),
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->renderHook('panels::head.end', fn () => view('filament.admin.admin-styles'))
            ->renderHook('panels::body.end', fn () => view('filament.admin.web-cron-heartbeat'))
            ->renderHook('panels::body.end', fn () => view('filament.roles.role-assets'));
    }
}
