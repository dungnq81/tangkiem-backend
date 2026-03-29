<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * System-wide announcement from admin.
 *
 * Recipients: all users or specific user groups.
 * Channel: database.
 */
class SystemAnnouncementNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected string $title,
        protected string $message,
        protected ?string $actionUrl = null,
    ) {}

    /**
     * @return string[]
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type'       => 'system',
            'title'      => $this->title,
            'message'    => $this->message,
            'action_url' => $this->actionUrl,
        ];
    }
}
