<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Resources\Resource;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Gait\FilamentMobile\MobileResource;
use Gait\FilamentMobile\Tests\Fixtures\Models\Slide;

/**
 * P24 Task 2: the table declares two filters (`status`, `category`) but
 * `mobile()->filters()` opts into only `status`, plus a third name
 * (`nope`) that matches no table filter at all — one fixture proving both
 * the schema-endpoint narrowing (`SchemaEndpointTest`) and the doctor's
 * unresolvable-name refusal (`DoctorCommandTest`).
 *
 * P24 Task 6: `category` carries a `->default()` deliberately — it is the
 * fixture for `FilterDeclaration`'s silent-divergence diagnostic, proving a
 * filter narrowed away by `mobile()->filters()` (not just a refused one)
 * still gets named when its default would widen the mobile list.
 */
class NarrowedFilterResource extends Resource
{
    protected static ?string $model = Slide::class;

    protected static ?string $slug = 'narrowed-filters';

    public static function mobile(): MobileResource
    {
        return MobileResource::make()->filters(['status', 'nope']);
    }

    public static function table(Table $table): Table
    {
        return $table->filters([
            SelectFilter::make('status')->options([
                'draft' => 'Draft',
                'published' => 'Published',
            ]),
            SelectFilter::make('category')->options([
                'a' => 'A',
                'b' => 'B',
            ])->default('a'),
        ]);
    }
}
