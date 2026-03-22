<?php

declare(strict_types=1);

namespace App\Filament\Resources\ApiDomains\Pages;

use App\Filament\Resources\ApiDomains\ApiDomainResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditApiDomain extends EditRecord
{
    protected static string $resource = ApiDomainResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('regenerateKeys')
                ->label('Tạo lại Keys')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Tạo lại API Keys?')
                ->modalDescription('Các ứng dụng đang sử dụng keys cũ sẽ không thể kết nối. Bạn cần cập nhật keys mới cho các ứng dụng.')
                ->action(function () {
                    $this->record->regenerateKeys();
                    $this->refreshFormData(['public_key', 'secret_key']);

                    Notification::make()
                        ->title('API Keys đã được tạo lại')
                        ->success()
                        ->send();
                }),

            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Make secret_key visible (it's hidden in model's $hidden)
        $data['secret_key'] = $this->record->makeVisible('secret_key')->secret_key;

        // Convert allowed_groups array to checkbox states
        $groups = $data['allowed_groups'] ?? [];
        $data['allowed_groups_check'] = [];
        foreach (array_keys(config('api.groups', [])) as $key) {
            $data['allowed_groups_check'][$key] = in_array($key, $groups);
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
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
