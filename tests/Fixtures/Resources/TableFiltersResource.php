<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Resources\Resource;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\MultiSelectFilter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Gait\FilamentMobile\MobileResource;
use Gait\FilamentMobile\Tests\Fixtures\Models\Slide;
use Illuminate\Database\Eloquent\Builder;

/**
 * P24 Task 1: the kitchen-sink fixture — one of every filter shape
 * `FilterDeclaration` classifies, so each `FilterDeclarationTest` case can
 * pick the one it needs with `$only` rather than needing its own resource.
 */
class TableFiltersResource extends Resource
{
    protected static ?string $model = Slide::class;

    // P24 Task 2: explicit, so doctor tests can register it by a known key.
    protected static ?string $slug = 'table-filters';

    public static function mobile(): MobileResource
    {
        return MobileResource::make();
    }

    public static function table(Table $table): Table
    {
        return $table->filters([
            SelectFilter::make('status')
                ->options(['draft' => 'Draft', 'published' => 'Published'])
                ->default('draft'),
            SelectFilter::make('category')
                ->options(['a' => 'A', 'b' => 'B'])
                ->multiple(),
            SelectFilter::make('featured_categories')
                ->options(['a' => 'A', 'b' => 'B'])
                ->multiple()
                ->default(['a', 'b']),
            MultiSelectFilter::make('tags')
                ->options(['x' => 'X', 'y' => 'Y']),
            TernaryFilter::make('is_active')
                ->label('Is Active'),
            TernaryFilter::make('is_featured')
                ->label('Is Featured')
                ->default(true),
            TrashedFilter::make(),
            Filter::make('notes')
                ->query(fn (Builder $query) => $query),
            SelectFilter::make('empty_opts')
                ->options([])
                ->default([]),
            SelectFilter::make('remote_search')
                ->searchable()
                ->getSearchResultsUsing(fn (?string $search): array => array_filter(
                    ['alpha' => 'Alpha', 'beta' => 'Beta', 'gamma' => 'Gamma'],
                    fn (string $label): bool => $search === null || $search === '' || str_contains(strtolower($label), strtolower($search)),
                )),
            // P24 final wave, finding 1: three `->default()` shapes a panel
            // can genuinely declare and that used to be published verbatim,
            // then rejected by this package's OWN 422 matrix the moment a
            // client echoed them back — a permanent hard 422 per resource.
            SelectFilter::make('multi_scalar')
                ->options(['a' => 'A', 'b' => 'B'])
                ->multiple()
                ->default('a'),
            SelectFilter::make('single_list')
                ->options(['a' => 'A', 'b' => 'B'])
                ->default(['a']),
            SelectFilter::make('stray_default')
                ->options(['a' => 'A', 'b' => 'B'])
                ->default('nope'),
            SelectFilter::make('empty_list_default')
                ->options(['a' => 'A', 'b' => 'B'])
                ->multiple()
                ->default([]),
            SelectFilter::make('too_many')
                ->options(array_combine(
                    range(1, 51),
                    array_map(fn (int $n): string => "Option {$n}", range(1, 51)),
                ))
                ->default('1'),
        ]);
    }
}
