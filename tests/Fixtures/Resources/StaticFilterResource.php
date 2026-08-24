<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Resources\Resource;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Gait\FilamentMobile\MobileResource;
use Gait\FilamentMobile\Tests\Fixtures\Models\Slide;

/**
 * P24 Task 2: a published `->static()` select — Filament's own escape hatch
 * for a filter whose form control renders but whose `apply()` is a no-op.
 * `SelectFilter::$isStatic` has no public accessor (see
 * `Introspection/ReorderDeclaration.php`'s class doc for why this package
 * refuses to reflect it), so `filament-mobile:doctor` catches it
 * behaviourally instead — see `DoctorCommand::filterProblems()`.
 */
class StaticFilterResource extends Resource
{
    protected static ?string $model = Slide::class;

    protected static ?string $slug = 'static-filter';

    public static function mobile(): MobileResource
    {
        return MobileResource::make();
    }

    public static function table(Table $table): Table
    {
        return $table->filters([
            SelectFilter::make('status')
                ->options(['draft' => 'Draft', 'published' => 'Published'])
                ->static(),
        ]);
    }
}
