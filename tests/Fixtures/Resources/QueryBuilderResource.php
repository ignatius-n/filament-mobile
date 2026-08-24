<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Resources\Resource;
use Filament\Tables\Filters\QueryBuilder;
use Filament\Tables\Table;
use Gait\FilamentMobile\MobileResource;
use Gait\FilamentMobile\Tests\Fixtures\Models\Slide;

/**
 * P24 Task 1: `QueryBuilder` is Filament's own filter type with no mobile
 * equivalent — `FilterDeclaration` refuses it by name rather than trying to
 * translate its constraint tree.
 */
class QueryBuilderResource extends Resource
{
    protected static ?string $model = Slide::class;

    // P24 Task 2: explicit, so doctor tests can register it by a known key.
    protected static ?string $slug = 'query-builder';

    public static function mobile(): MobileResource
    {
        return MobileResource::make();
    }

    public static function table(Table $table): Table
    {
        return $table->filters([
            QueryBuilder::make('advanced'),
        ]);
    }
}
