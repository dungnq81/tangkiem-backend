<?php

declare(strict_types=1);

namespace App\Filament\Resources\ApiDomains\Tables;

use App\Filament\Resources\ApiDomains\ApiDomainResource;
use App\Models\ApiDomain;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ApiDomainsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordUrl(null)
            ->recordClasses('fi-clickable')
            ->columns([
                TextColumn::make('name')
                    ->label('Tên')
                    ->weight(FontWeight::SemiBold)
                    ->searchable()
                    ->sortable()
                    ->url(fn ($record) => ApiDomainResource::getUrl('edit', ['record' => $record])),

                TextColumn::make('domain')
                    ->label('Domain')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->copyMessage('Đã copy domain'),

                TextColumn::make('allowed_groups')
                    ->label('Nhóm API')
                    ->badge()
                    ->formatStateUsing(fn ($state) => is_array($state) ? implode(', ', $state) : $state),

                TextColumn::make('valid_until')
                    ->label('Hết hạn')
                    ->sortable()
                    ->default('PERMANENT')
                    ->formatStateUsing(function ($state) {
                        if ($state === 'PERMANENT') {
                            return '<span class="fi-badge flex items-center justify-center gap-x-1 rounded-md text-xs font-medium ring-1 ring-inset px-1.5 py-0.5 bg-success-50 text-success-600 ring-success-600/10 dark:bg-success-400/10 dark:text-success-400 dark:ring-success-400/30">Vĩnh viễn</span>';
                        }
                        return \Carbon\Carbon::parse($state)->format('d/m/Y');
                    })
                    ->html()
                    ->color(fn (ApiDomain $record) => $record->valid_until?->isPast() ? 'danger' : null),

                IconColumn::make('is_active')
                    ->label('Trạng thái')
                    ->boolean()
                    ->trueIcon(Heroicon::CheckBadge)
                    ->falseIcon(Heroicon::OutlinedMinusCircle)
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->sortable()
                    ->alignCenter()
                    ->tooltip(fn ($record) => $record->is_active ? 'Đang hoạt động' : 'Đã vô hiệu hóa'),

                TextColumn::make('created_at')
                    ->label('Tạo lúc')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Trạng thái')
                    ->trueLabel('Đang hoạt động')
                    ->falseLabel('Đã tắt')
                    ->placeholder('Tất cả'),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()
                        ->label('Chỉnh sửa')
                        ->icon(Heroicon::OutlinedPencilSquare)
                        ->color('primary'),

                    DeleteAction::make()
                        ->label('Xóa')
                        ->icon(Heroicon::OutlinedTrash)
                        ->color('danger'),
                ])
				->icon(Heroicon::OutlinedEllipsisVertical)
				->tooltip('Hành động')
				->dropdownPlacement('bottom-end'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
