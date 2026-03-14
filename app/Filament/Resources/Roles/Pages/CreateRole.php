<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use BezhanSalleh\FilamentShield\Resources\Roles\Pages\CreateRole as BaseCreateRole;

class CreateRole extends BaseCreateRole
{
    protected static string $resource = RoleResource::class;

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
