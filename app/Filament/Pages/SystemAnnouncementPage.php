<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\User;
use App\Notifications\SystemAnnouncementNotification;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Notification;

/**
 * Send system-wide announcements to users.
 *
 * Located under "Quản lý hệ thống" navigation group.
 * Provides a simple modal form to compose and broadcast
 * system notifications to all active users.
 */
class SystemAnnouncementPage extends Page
{
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationLabel = 'Thông Báo Hệ Thống';

    protected static ?string $title = 'Thông Báo Hệ Thống';

    protected static ?string $slug = 'system-announcements';

    protected static string | \UnitEnum | null $navigationGroup = 'Quản lý hệ thống';

    protected static ?int $navigationSort = 50;

    protected string $view = 'filament.admin.system-announcements';

    /**
     * Get header actions — the "Send" button that opens the modal.
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('sendAnnouncement')
                ->label('Gửi thông báo')
                ->icon('heroicon-o-paper-airplane')
                ->color('primary')
                ->form([
                    TextInput::make('title')
                        ->label('Tiêu đề')
                        ->required()
                        ->maxLength(255)
                        ->placeholder('VD: Bảo trì hệ thống ngày 10/03'),

                    Textarea::make('message')
                        ->label('Nội dung')
                        ->required()
                        ->maxLength(1000)
                        ->rows(4)
                        ->placeholder('Nội dung thông báo gửi đến người dùng...'),

                    TextInput::make('action_url')
                        ->label('Link (tùy chọn)')
                        ->url()
                        ->maxLength(500)
                        ->placeholder('https://tangkiem.xyz/tin-tuc/...'),
                ])
                ->modalHeading('Gửi thông báo hệ thống')
                ->modalDescription('Thông báo sẽ được gửi đến tất cả người dùng đang hoạt động.')
                ->modalSubmitActionLabel('Gửi ngay')
                ->requiresConfirmation()
                ->action(function (array $data): void {
                    $this->sendAnnouncementToUsers($data);
                }),
        ];
    }

    /**
     * Send the announcement notification to all active users.
     */
    protected function sendAnnouncementToUsers(array $data): void
    {
        $users = User::query()
            ->where('is_active', true)
            ->where('is_banned', false)
            ->get();

        if ($users->isEmpty()) {
            FilamentNotification::make()
                ->title('Không có người dùng nào.')
                ->warning()
                ->send();

            return;
        }

        $notification = new SystemAnnouncementNotification(
            title: $data['title'],
            message: $data['message'],
            actionUrl: $data['action_url'] ?? null,
        );

        Notification::send($users, $notification);

        FilamentNotification::make()
            ->title('Đã gửi thông báo')
            ->body("Gửi thành công đến {$users->count()} người dùng.")
            ->success()
            ->send();
    }

    /**
     * Get recent system announcements for display.
     *
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        // Get recent system notifications (latest 20)
        $recentAnnouncements = \Illuminate\Notifications\DatabaseNotification::query()
            ->whereJsonContains('data->type', 'system')
            ->orderByDesc('created_at')
            ->groupBy('data', 'created_at', 'id')
            ->limit(20)
            ->get()
            ->unique(fn ($n) => $n->data['title'] . '|' . $n->created_at->format('Y-m-d H:i'))
            ->values();

        return [
            'recentAnnouncements' => $recentAnnouncements,
        ];
    }
}
