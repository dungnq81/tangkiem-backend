<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use BezhanSalleh\FilamentShield\Resources\Roles\Pages\EditRole as BaseEditRole;
use Filament\Actions\DeleteAction;

class EditRole extends BaseEditRole
{
    protected static string $resource = RoleResource::class;

    protected function getActions(): array
    {
        return [
            DeleteAction::make()
                ->hidden(fn () => $this->record->name === 'super_admin'),
        ];
    }

    /**
     * Add page-specific CSS class for scoped styling.
     */
    public function getExtraBodyAttributes(): array
    {
        return [
            'class' => 'fi-page-shield-roles',
        ];
    }
}
