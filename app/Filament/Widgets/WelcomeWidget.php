<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Role;
use Filament\Widgets\Widget;

class WelcomeWidget extends Widget
{
    protected static ?int $sort = -3;

    protected int | string | array $columnSpan = 'full';

    protected string $view = 'filament.widgets.welcome-widget';

    /**
     * Get the greeting based on current time.
     */
    public function getGreeting(): string
    {
        $hour = (int) now()->format('H');

        return match (true) {
            $hour >= 5 && $hour < 12 => 'Chào buổi sáng',
            $hour >= 12 && $hour < 18 => 'Chào buổi chiều',
            $hour >= 18 && $hour < 22 => 'Chào buổi tối',
            default => 'Xin chào',
        };
    }

    /**
     * Get the user's primary role name (slug).
     */
    public function getUserRole(): string
    {
        $user = auth()->user();

        if (! $user) {
            return 'panel_user';
        }

        $role = $user->roles->first();

        return $role ? $role->name : 'panel_user';
    }

    /**
     * Get formatted role label from database.
     */
    public function getRoleLabel(): string
    {
        $user = auth()->user();

        if (! $user) {
            return 'Người dùng';
        }

        $role = $user->roles->first();

        return $role ? $role->display_label : 'Người dùng';
    }

    /**
     * Get icon for the user's current role.
     */
    public function getRoleIcon(): string
    {
        return 'heroicon-o-shield-check';
    }

    /**
     * Get today's formatted date.
     */
    public function getFormattedDate(): string
    {
        $dayOfWeek = match ((int) now()->dayOfWeek) {
            0 => 'Chủ nhật',
            1 => 'Thứ hai',
            2 => 'Thứ ba',
            3 => 'Thứ tư',
            4 => 'Thứ năm',
            5 => 'Thứ sáu',
            6 => 'Thứ bảy',
        };

        return $dayOfWeek . ', ngày ' . now()->format('d/m/Y');
    }
}
