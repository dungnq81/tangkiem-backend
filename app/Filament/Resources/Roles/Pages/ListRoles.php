<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use BezhanSalleh\FilamentShield\Resources\Roles\Pages\ListRoles as BaseListRoles;
use Filament\Actions\CreateAction;

class ListRoles extends BaseListRoles
{
    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
