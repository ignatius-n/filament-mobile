<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Forms\Components\Checkbox;
use Filament\Resources\Resource;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Gait\FilamentMobile\MobileCard;
use Gait\FilamentMobile\MobileResource;
use Gait\FilamentMobile\Tests\Fixtures\Models\Story;
use Illuminate\Database\Eloquent\Builder;

/**
 * P24 Task 3: the three ways applying filters can WIDEN a list, one fixture,
 * one assertion on ids (see `FilterEndpointTest`'s escape test). Each defect
 * flips exactly one seeded row into the response:
 *
 * - a filter's `orWhere` escaping the resource's own scope, if
 *   `FilterApplier` stops wrapping the filters in ONE `where()` group;
 * - a bare `Filter` declared `->default()` — pre-checked on web
 *   (`Filters/Filter::getFormField():55-62`) — read as unchecked;
 * - a `Filter` with its own `->schema()` — which has no `isActive` field, so
 *   its query runs on web (`Concerns/HasSchema::getSchemaComponents():53-59`,
 *   and Filament's own `Filter::getResetState():66-73`) — forced off.
 *
 * Neither filter is publishable ("custom filter schema — deferred"), which
 * is the point: what an unpublished filter contributes to the query is
 * exactly what nothing else in the suite pins.
 */
class EscapingFilterResource extends Resource
{
    protected static ?string $model = Story::class;

    protected static ?string $slug = 'escaping-filters';

    /** The scope a filter's `orWhere` must not escape. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('status', 'published');
    }

    public static function mobile(): MobileResource
    {
        return MobileResource::make()->card(fn (MobileCard $card) => $card->title('title'));
    }

    public static function table(Table $table): Table
    {
        return $table->filters([
            Filter::make('or_featured')
                ->query(fn (Builder $query) => $query->orWhere('featured', true))
                ->default(),
            Filter::make('kept_titles')
                ->schema([Checkbox::make('anything')])
                ->query(fn (Builder $query) => $query->where('title', 'like', 'Keep%')),
        ]);
    }
}
