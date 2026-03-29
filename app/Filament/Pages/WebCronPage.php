<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Setting;
use App\Models\WebCronLog;
use App\Services\WebCron\WebCronManager;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use UnitEnum;

/**
 * Web Cron management page.
 *
 * Features:
 * - Real-time status dashboard (on/off, last run, running state)
 * - Configuration (enable/disable, ping interval)
 * - Manual trigger ("Run Now")
 * - Lock management ("Clear Lock")
 * - Execution history log with task details
 * - Statistics (success rate, average duration)
 */
class WebCronPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Web Cron';

    protected static string | UnitEnum | null $navigationGroup = 'Quản lý hệ thống';

    protected static ?int $navigationSort = 12;

    protected static ?string $title = 'Web Cron';

    protected static ?string $slug = 'web-cron';

    protected string $view = 'filament.pages.web-cron';

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    // ═══════════════════════════════════════════════════════════════
    // Lifecycle
    // ═══════════════════════════════════════════════════════════════

    public function mount(): void
    {
        $this->form->fill([
            'web_cron_enabled'    => WebCronManager::isEnabled(),
            'web_cron_background' => WebCronManager::isBackgroundEnabled(),
            'web_cron_interval'   => (string) WebCronManager::getInterval(),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Form schema
    // ═══════════════════════════════════════════════════════════════

    public function form(Schema $form): Schema
    {
        return $form
            ->schema([
                self::configSection(),
            ])
            ->statePath('data');
    }

    private static function configSection(): Section
    {
        return Section::make('Cấu hình Web Cron')
            ->description('Tự động chạy tác vụ nền (scrape, AI, queue, dọn dẹp) theo lịch khi admin panel đang mở.')
            ->icon('heroicon-o-cog-6-tooth')
            ->schema([
                Toggle::make('web_cron_enabled')
                    ->label('Kích hoạt Web Cron')
                    ->helperText('Tự động ping server và chạy tác vụ khi tab admin đang mở.')
                    ->columnSpanFull(),

                Toggle::make('web_cron_background')
                    ->label('Chạy nền')
                    ->helperText('Tiếp tục chạy khi chuyển tab hoặc minimize cửa sổ. Chỉ dừng khi đóng tab hoặc tắt trình duyệt.')
                    ->visible(fn (callable $get) => $get('web_cron_enabled'))
                    ->columnSpanFull(),

                Select::make('web_cron_interval')
                    ->label('Chu kỳ kiểm tra')
                    ->options([
                        '30'  => '⚡ 30 giây — phản hồi nhanh nhất',
                        '60'  => '🔄 60 giây — cân bằng (khuyến nghị)',
                        '120' => '⏱ 2 phút — tiết kiệm tài nguyên',
                        '180' => '⏱ 3 phút',
                        '300' => '🐢 5 phút — tiết kiệm nhất',
                    ])
                    ->helperText('Khoảng cách giữa các lần ping. Ngắn hơn = phản hồi nhanh, nhưng tốn tài nguyên hơn.')
                    ->visible(fn (callable $get) => $get('web_cron_enabled'))
                    ->required(fn (callable $get) => $get('web_cron_enabled'))
                    ->columnSpanFull(),
            ]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Content (page layout)
    // ═══════════════════════════════════════════════════════════════

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')
                            ->label('Lưu cài đặt')
                            ->submit('save')
                            ->icon('heroicon-o-check')
                            ->keyBindings(['mod+s']),

                        Action::make('runNow')
                            ->label('Chạy ngay')
                            ->color('success')
                            ->icon('heroicon-o-play')
                            ->requiresConfirmation()
                            ->modalIcon('heroicon-o-play')
                            ->modalHeading('Chạy Web Cron ngay?')
                            ->modalDescription('Hệ thống sẽ chạy tất cả tác vụ scheduled ngay lập tức. Quá trình có thể mất vài phút.')
                            ->action(fn () => $this->runNow()),

                        Action::make('clearLock')
                            ->label('Xóa Lock')
                            ->color('gray')
                            ->icon('heroicon-o-lock-open')
                            ->outlined()
                            ->requiresConfirmation()
                            ->modalIcon('heroicon-o-exclamation-triangle')
                            ->modalHeading('Xóa lock Web Cron?')
                            ->modalDescription('Chỉ dùng khi cron bị kẹt (stuck). Nếu đang chạy bình thường, xóa lock có thể gây chạy trùng.')
                            ->action(fn () => $this->clearLock()),

                        Action::make('clearHistory')
                            ->label('Xóa lịch sử')
                            ->color('danger')
                            ->icon('heroicon-o-trash')
                            ->outlined()
                            ->requiresConfirmation()
                            ->modalIcon('heroicon-o-exclamation-triangle')
                            ->modalHeading('Xóa lịch sử Web Cron?')
                            ->modalDescription('Chọn số lượng bản ghi muốn giữ lại. Các bản ghi cũ hơn sẽ bị xóa vĩnh viễn.')
                            ->form([
                                Select::make('keep_count')
                                    ->label('Giữ lại')
                                    ->options([
                                        '0'   => '🗑️ Xóa toàn bộ',
                                        '30'  => '📋 30 bản ghi gần nhất',
                                        '50'  => '📋 50 bản ghi gần nhất',
                                        '100' => '📋 100 bản ghi gần nhất',
                                        '200' => '📋 200 bản ghi gần nhất',
                                    ])
                                    ->default('30')
                                    ->required()
                                    ->native(false),
                            ])
                            ->action(fn (array $data) => $this->clearHistory((int) $data['keep_count'])),
                    ])
                        ->alignment($this->getFormActionsAlignment())
                        ->key('form-actions'),
                ]),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Actions
    // ═══════════════════════════════════════════════════════════════

    public function save(): void
    {
        $data = $this->form->getState();
        $userId = auth()->id();

        Setting::set('system.web_cron_enabled', (bool) ($data['web_cron_enabled'] ?? false), $userId);
        Setting::set('system.web_cron_background', (bool) ($data['web_cron_background'] ?? false), $userId);
        Setting::set('system.web_cron_interval', (int) ($data['web_cron_interval'] ?? 60), $userId);

        Notification::make()
            ->title('Đã lưu cài đặt Web Cron')
            ->success()
            ->send();
    }

    public function runNow(): void
    {
        try {
            $log = WebCronManager::runManually();

            $duration = $log->duration_ms < 1000
                ? "{$log->duration_ms}ms"
                : number_format($log->duration_ms / 1000, 1) . 's';

            $statusIcon = $log->status === 'success' ? '✅' : ($log->status === 'partial' ? '⚠️' : '❌');

            Notification::make()
                ->title("{$statusIcon} Web Cron hoàn tất")
                ->body("Kết quả: {$log->status} — Thời gian: {$duration}")
                ->success()
                ->duration(5000)
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('❌ Web Cron thất bại')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function clearLock(): void
    {
        WebCronManager::clearLock();

        Notification::make()
            ->title('Đã xóa lock Web Cron')
            ->body('Lock và throttle đã được reset. Lần ping tiếp theo sẽ trigger cron.')
            ->success()
            ->send();
    }

    public function clearHistory(int $keepCount): void
    {
        if ($keepCount === 0) {
            $deleted = WebCronLog::query()->count();
            WebCronLog::query()->delete();
        } else {
            $deleted = WebCronLog::cleanup($keepCount);
        }

        Notification::make()
            ->title('Đã xóa lịch sử Web Cron')
            ->body("Đã xóa {$deleted} bản ghi cũ." . ($keepCount > 0 ? " Giữ lại {$keepCount} bản ghi gần nhất." : ''))
            ->success()
            ->send();
    }

    // ═══════════════════════════════════════════════════════════════
    // View data
    // ═══════════════════════════════════════════════════════════════

    /**
     * Expose status and logs to the Blade view.
     *
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'status' => WebCronManager::getStatus(),
            'logs'   => WebCronManager::getRecentLogs(30),
        ];
    }

    /**
     * Navigation badge: show "ON" or "OFF".
     */
    public static function getNavigationBadge(): ?string
    {
        return WebCronManager::isEnabled() ? 'ON' : 'OFF';
    }

    /**
     * Badge color based on status.
     */
    public static function getNavigationBadgeColor(): string|array|null
    {
        return WebCronManager::isEnabled() ? 'success' : 'gray';
    }
}
