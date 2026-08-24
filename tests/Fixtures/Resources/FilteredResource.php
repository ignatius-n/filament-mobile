<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Resources\Resource;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Gait\FilamentMobile\MobileResource;
use Gait\FilamentMobile\Tests\Fixtures\Models\Slide;

/**
 * P24 Task 1: the plain case — one `SelectFilter` with two inline options,
 * no `->multiple()`, no default. `FilterDeclarationTest`'s base assertion
 * that `FilterDeclaration::for()` reads exactly this shape.
 */
class FilteredResource extends Resource
{
    protected static ?string $model = Slide::class;

    // P24 Task 2: explicit, so /schema tests can address it by a known key
    // rather than guessing Filament's pluralization of "Filtered".
    protected static ?string $slug = 'filtered';

    public static function mobile(): MobileResource
    {
        return MobileResource::make();
    }

    public static function table(Table $table): Table
    {
        return $table->filters([
            SelectFilter::make('status')->options([
                'draft' => 'Draft',
                'published' => 'Published',
            ]),
        ]);
    }
}
