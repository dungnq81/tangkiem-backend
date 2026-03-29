<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords as BaseListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;

/**
 * Base ListRecords with reusable utilities.
 *
 * Provides:
 * - emptyTrashAction(): shared "Dọn thùng rác" button (DRY for all SoftDelete resources)
 */
abstract class ListRecords extends BaseListRecords
{
    // ═══════════════════════════════════════════════════════════════
    // Shared Trash Action
    // ═══════════════════════════════════════════════════════════════

    /**
     * Create a reusable "Dọn thùng rác" header action.
     *
     * @param  class-string<Model&SoftDeletes>  $modelClass  e.g. Story::class
     * @param  string  $label  Vietnamese noun, e.g. 'truyện', 'chương', 'tác giả'
     */
    protected static function emptyTrashAction(string $modelClass, string $label): Action
    {
        return Action::make('emptyTrash')
            ->label('Dọn thùng rác')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->visible(fn (): bool => Cache::remember(
                'trash_exists:' . class_basename($modelClass),
                60,
                fn () => $modelClass::onlyTrashed()->exists()
            ))
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedExclamationTriangle)
            ->modalHeading('Dọn sạch thùng rác')
            ->modalDescription(function () use ($modelClass, $label): string {
                $count = $modelClass::onlyTrashed()->count();

                return "Bạn có chắc muốn xóa VĨNH VIỄN {$count} {$label} trong thùng rác? Hành động này không thể hoàn tác!";
            })
            ->action(function () use ($modelClass, $label): void {
                $count = $modelClass::onlyTrashed()->count();

                // Bulk delete without loading into memory
                $modelClass::onlyTrashed()->forceDelete();

                Notification::make()
                    ->title('Đã dọn sạch thùng rác')
                    ->body("Đã xóa vĩnh viễn {$count} {$label}.")
                    ->success()
                    ->send();
            });
    }
}
