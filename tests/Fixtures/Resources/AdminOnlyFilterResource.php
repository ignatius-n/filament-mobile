<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Resources\Resource;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Gait\FilamentMobile\MobileResource;
use Gait\FilamentMobile\Tests\Fixtures\Models\Slide;

/**
 * P24 Task 1: `->visible()` reads `request()->user()`, exactly as
 * `SlideResource`'s `authorizeReorder()` does — the fixture
 * `FilterDeclarationTest` uses to prove `FilterDeclaration` binds the
 * request around the whole `getFilters()` walk, not just the table lookup.
 */
class AdminOnlyFilterResource extends Resource
{
    protected static ?string $model = Slide::class;

    public static function mobile(): MobileResource
    {
        return MobileResource::make();
    }

    public static function table(Table $table): Table
    {
        return $table->filters([
            SelectFilter::make('vip')
                ->options(['1' => 'Yes'])
                ->visible(fn () => request()->user()?->email === 'admin@example.test'),
        ]);
    }
}
