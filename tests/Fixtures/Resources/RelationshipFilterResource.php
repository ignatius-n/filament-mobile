<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Resources\Resource;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Gait\FilamentMobile\MobileResource;
use Gait\FilamentMobile\Tests\Fixtures\Models\Banner;

class RelationshipFilterResource extends Resource
{
    protected static ?string $model = Banner::class;

    protected static ?string $slug = 'relationship-filters';

    public static function mobile(): MobileResource
    {
        return MobileResource::make();
    }

    public static function table(Table $table): Table
    {
        return $table->filters([
            SelectFilter::make('company_id')
                ->relationship('company', 'name')
                ->searchable(),
        ]);
    }
}
