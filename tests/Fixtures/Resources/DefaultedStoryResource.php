<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Resources\Resource;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Gait\FilamentMobile\MobileCard;
use Gait\FilamentMobile\MobileResource;
use Gait\FilamentMobile\Tests\Fixtures\Models\Story;

/**
 * P24 Task 3: a filter with a `->default()`, over the same seedable table —
 * the proof that a request carrying no `filter` parameter at all still
 * arrives narrowed, exactly as the web panel's own pre-filled filter form
 * narrows it.
 */
class DefaultedStoryResource extends Resource
{
    protected static ?string $model = Story::class;

    protected static ?string $slug = 'defaulted-stories';

    public static function mobile(): MobileResource
    {
        return MobileResource::make()
            ->card(fn (MobileCard $card) => $card->title('title'));
    }

    public static function table(Table $table): Table
    {
        return $table->filters([
            SelectFilter::make('status')
                ->options(['draft' => 'Draft', 'published' => 'Published'])
                ->default('draft'),
        ]);
    }
}
