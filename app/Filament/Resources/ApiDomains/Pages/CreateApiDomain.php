<?php

declare(strict_types=1);

namespace App\Filament\Resources\ApiDomains\Pages;

use App\Filament\Resources\ApiDomains\ApiDomainResource;
use Filament\Resources\Pages\CreateRecord;

class CreateApiDomain extends CreateRecord
{
    protected static string $resource = ApiDomainResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Convert checkbox groups to allowed_groups array
        $groups = [];
        foreach (array_keys(config('api.groups', [])) as $key) {
            if (! empty($data['allowed_groups_check'][$key])) {
                $groups[] = $key;
            }
        }
        $data['allowed_groups'] = $groups;
        unset($data['allowed_groups_check']);

        return $data;
    }
}
