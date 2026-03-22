<?php

declare(strict_types=1);

namespace App\Filament\Resources\ApiDomains\Pages;

use App\Filament\Pages\ListRecords;
use App\Filament\Resources\ApiDomains\ApiDomainResource;
use Filament\Actions\CreateAction;

class ListApiDomains extends ListRecords
{
    protected static string $resource = ApiDomainResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
