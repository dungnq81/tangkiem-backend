<?php

declare(strict_types=1);

namespace App\Filament\Resources\ApiDomains;

use App\Filament\Resources\ApiDomains\Pages\CreateApiDomain;
use App\Filament\Resources\ApiDomains\Pages\EditApiDomain;
use App\Filament\Resources\ApiDomains\Pages\ListApiDomains;
use App\Filament\Resources\ApiDomains\Schemas\ApiDomainForm;
use App\Filament\Resources\ApiDomains\Tables\ApiDomainsTable;
use App\Filament\Concerns\HasCachedNavigationBadge;
use App\Models\ApiDomain;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ApiDomainResource extends Resource
{
    use HasCachedNavigationBadge;
    protected static ?string $model = ApiDomain::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static ?string $recordTitleAttribute = 'name';

    protected static \UnitEnum|string|null $navigationGroup = 'Quản lý hệ thống';

    protected static ?int $navigationSort = 90;

    public static function getModelLabel(): string
    {
        return 'API Domain';
    }

    public static function getPluralModelLabel(): string
    {
        return 'API Domains';
    }

    public static function form(Schema $schema): Schema
    {
        return ApiDomainForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ApiDomainsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApiDomains::route('/'),
            'create' => CreateApiDomain::route('/create'),
            'edit' => EditApiDomain::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getCachedValue('active', fn () => static::getModel()::where('is_active', true)->count());
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'success';
    }
}
