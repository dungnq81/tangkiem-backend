<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Tables;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;
use Awcodes\Curator\Components\Tables\CuratorColumn;
use App\Models\Role;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Forms\Components\Select;

class UsersTable
{
    /**
     * Generate URL to Users page filtered by role.
     */
    private static function getRoleFilterUrl(int $roleId): string
    {
        $baseUrl = UserResource::getUrl('index');
        $query = http_build_query([
            'filters' => [
                'roles' => ['values' => [$roleId]],
            ],
        ]);

        return "{$baseUrl}?{$query}";
    }

    /**
     * Render role badges with filter links.
     */
    private static function renderRoleBadges(User $record): HtmlString
    {
        if ($record->roles->isEmpty()) {
            return new HtmlString('<span class="text-gray-400">-</span>');
        }

        $badges = $record->roles->map(function ($role) {
            $label = $role->display_label;

            return sprintf(
                '<a href="%s" class="fi-badge no-underline inline-flex items-center rounded-md text-xs font-medium ring-1 ring-inset px-1.5 py-0.5 transition-opacity hover:opacity-80" style="background-color: rgba(59, 130, 246, 0.1); color: rgb(59, 130, 246); border-color: rgba(59, 130, 246, 0.3);">%s</a>',
                self::getRoleFilterUrl($role->id),
                e($label)
            );
        })->implode('');

        return new HtmlString('<div class="flex flex-wrap gap-1">' . $badges . '</div>');
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['avatar', 'roles']))
			->recordUrl(null)
            ->recordClasses('fi-clickable')
            ->columns([
                CuratorColumn::make('avatar')
                    ->label('🖼️')
                    ->size(50)
                    ->ring(2)
                    ->overlap(2)
                    ->limit(1)
					->url(fn ($record) => UserResource::getUrl('edit', ['record' => $record]))
                    ->extraAttributes(['class' => 'py-2'])
                    ->alignCenter(),
                TextColumn::make('name')
                    ->label('Họ tên')
                    ->searchable()
					->url(fn ($record) => UserResource::getUrl('edit', ['record' => $record])),
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable(),
                IconColumn::make('is_active')
                    ->label('Kích hoạt')
                    ->boolean()
                    ->trueIcon(Heroicon::CheckBadge)
                    ->falseIcon(Heroicon::OutlinedMinusCircle)
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->sortable()
                    ->alignCenter()
                    ->tooltip(fn ($record) => $record->is_active ? 'Đang kích hoạt' : 'Đã vô hiệu hóa'),
                IconColumn::make('is_vip')
                    ->label('VIP')
                    ->boolean()
                    ->trueIcon(Heroicon::Sparkles)
                    ->falseIcon(Heroicon::OutlinedSparkles)
                    ->trueColor('warning')
                    ->falseColor('gray')
                    ->sortable()
                    ->alignCenter()
                    ->tooltip(fn ($record) => $record->is_vip ? 'Thành viên VIP' : 'Thành viên thường'),
                IconColumn::make('is_author')
                    ->label('Tác giả')
                    ->boolean()
                    ->trueIcon(Heroicon::CheckBadge)
                    ->falseIcon(Heroicon::OutlinedMinusCircle)
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->sortable()
                    ->alignCenter()
                    ->tooltip(fn ($record) => $record->is_author ? 'Là tác giả' : 'Không phải tác giả'),
                TextColumn::make('roles')
                    ->label('Vai trò')
                    ->getStateUsing(fn (User $record) => self::renderRoleBadges($record))
                    ->html(),
                TextColumn::make('email_verified_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('roles')
                    ->label('Vai trò')
                    ->relationship('roles', 'name')
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->display_label)
                    ->multiple()
                    ->preload()
                    ->searchable(),

                TernaryFilter::make('is_active')
                    ->label('Kích hoạt')
                    ->placeholder('Tất cả')
                    ->trueLabel('Đang kích hoạt')
                    ->falseLabel('Đã vô hiệu hóa'),

                TernaryFilter::make('is_vip')
                    ->label('VIP')
                    ->placeholder('Tất cả')
                    ->trueLabel('Thành viên VIP')
                    ->falseLabel('Thành viên thường'),

                TernaryFilter::make('is_author')
                    ->label('Tác giả')
                    ->placeholder('Tất cả')
                    ->trueLabel('Là tác giả')
                    ->falseLabel('Không phải tác giả'),
            ])
            ->filtersFormColumns(2)
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()
                        ->label('Chỉnh sửa')
                        ->icon(Heroicon::OutlinedPencilSquare)
                        ->color('primary'),

                    Action::make('activate')
                        ->label('Kích hoạt')
                        ->icon(Heroicon::OutlinedCheckCircle)
                        ->color('success')
                        ->visible(fn ($record) => !$record->is_active)
                        ->requiresConfirmation()
                        ->modalHeading('Kích hoạt người dùng')
                        ->modalDescription('Bạn có chắc muốn kích hoạt người dùng này?')
                        ->action(fn ($record) => $record->update(['is_active' => true])),

                    Action::make('deactivate')
                        ->label('Hủy kích hoạt')
                        ->icon(Heroicon::OutlinedXCircle)
                        ->color('warning')
                        ->visible(fn ($record) => $record->is_active)
                        ->requiresConfirmation()
                        ->modalHeading('Hủy kích hoạt người dùng')
                        ->modalDescription('Bạn có chắc muốn hủy kích hoạt người dùng này?')
                        ->action(fn ($record) => $record->update(['is_active' => false])),

                    Action::make('toggle_vip')
                        ->label(fn ($record) => $record->is_vip ? 'Hủy VIP' : 'Đặt VIP')
                        ->icon(Heroicon::OutlinedSparkles)
                        ->color('warning')
                        ->action(fn ($record) => $record->update(['is_vip' => !$record->is_vip])),

                    Action::make('toggle_author')
                        ->label(fn ($record) => $record->is_author ? 'Hủy Tác giả' : 'Đặt Tác giả')
                        ->icon(Heroicon::OutlinedUserCircle)
                        ->color('success')
                        ->action(fn ($record) => $record->update(['is_author' => !$record->is_author])),

                    Action::make('changeRoles')
                        ->label('Đổi vai trò')
                        ->icon(Heroicon::OutlinedShieldCheck)
                        ->color('info')
                        ->fillForm(fn ($record) => ['roles' => $record->roles->pluck('id')->toArray()])
                        ->form([
                            Select::make('roles')
                                ->label('Vai trò')
                                ->options(
                                    Role::all()
                                        ->pluck('display_label', 'id')
                                        ->toArray()
                                )
                                ->multiple()
                                ->searchable()
                                ->preload(),
                        ])
                        ->modalHeading('Đổi vai trò người dùng')
                        ->modalDescription(fn ($record) => "Cập nhật vai trò cho: {$record->name}")
                        ->modalSubmitActionLabel('Cập nhật vai trò')
                        ->action(function ($record, array $data): void {
                            $record->roles()->sync($data['roles'] ?? []);
                        })
                        ->successNotificationTitle('Đã cập nhật vai trò'),

                    DeleteAction::make()
                        ->label('Xóa')
                        ->icon(Heroicon::OutlinedTrash)
                        ->color('danger')
                        ->modalHeading('Xóa người dùng')
                        ->modalDescription('Bạn có chắc muốn xóa người dùng này? Hành động này không thể hoàn tác.'),
                ])
                    ->icon(Heroicon::OutlinedEllipsisVertical)
                    ->tooltip('Hành động')
                    ->dropdownPlacement('bottom-end'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('activateAll')
                        ->label('Kích hoạt')
                        ->icon(Heroicon::OutlinedCheckCircle)
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Kích hoạt các người dùng đã chọn')
                        ->modalDescription('Bạn có chắc muốn kích hoạt tất cả các người dùng được chọn?')
                        ->action(fn (Collection $records) => $records->each->update(['is_active' => true]))
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('deactivateAll')
                        ->label('Hủy kích hoạt')
                        ->icon(Heroicon::OutlinedXCircle)
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Hủy kích hoạt các người dùng đã chọn')
                        ->modalDescription('Bạn có chắc muốn hủy kích hoạt tất cả các người dùng được chọn?')
                        ->action(fn (Collection $records) => $records->each->update(['is_active' => false]))
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('setVipAll')
                        ->label('Đặt VIP')
                        ->icon(Heroicon::Sparkles)
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Đặt VIP cho các người dùng đã chọn')
                        ->modalDescription('Bạn có chắc muốn đặt VIP cho tất cả các người dùng được chọn?')
                        ->action(fn (Collection $records) => $records->each->update(['is_vip' => true]))
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('removeVipAll')
                        ->label('Hủy VIP')
                        ->icon(Heroicon::OutlinedSparkles)
                        ->color('gray')
                        ->requiresConfirmation()
                        ->modalHeading('Hủy VIP cho các người dùng đã chọn')
                        ->modalDescription('Bạn có chắc muốn hủy VIP cho tất cả các người dùng được chọn?')
                        ->action(fn (Collection $records) => $records->each->update(['is_vip' => false]))
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('setAuthorAll')
                        ->label('Đặt Tác giả')
                        ->icon(Heroicon::UserCircle)
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Đặt Tác giả cho các người dùng đã chọn')
                        ->modalDescription('Bạn có chắc muốn đặt Tác giả cho tất cả các người dùng được chọn?')
                        ->action(fn (Collection $records) => $records->each->update(['is_author' => true]))
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('removeAuthorAll')
                        ->label('Hủy Tác giả')
                        ->icon(Heroicon::OutlinedUserCircle)
                        ->color('gray')
                        ->requiresConfirmation()
                        ->modalHeading('Hủy Tác giả cho các người dùng đã chọn')
                        ->modalDescription('Bạn có chắc muốn hủy Tác giả cho tất cả các người dùng được chọn?')
                        ->action(fn (Collection $records) => $records->each->update(['is_author' => false]))
                        ->deselectRecordsAfterCompletion(),

                    DeleteBulkAction::make()
                        ->label('Xóa')
                        ->icon(Heroicon::OutlinedTrash)
                        ->color('danger'),
                ]),
            ])
            ->columnToggleFormColumns(2)
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([10, 25, 50, 100]);
    }
}
