<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Filament\Resources\Resource;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Gait\FilamentMobile\MobileCard;
use Gait\FilamentMobile\MobileResource;
use Gait\FilamentMobile\Tests\Fixtures\Models\Story;

/**
 * P24 Task 3: one of each APPLICABLE filter shape over a real, seedable
 * table — the endpoint half of `TableFiltersResource`, which classifies
 * every shape but sits on a model whose `TrashedFilter` throws.
 *
 * Reorderable on purpose: the "?reorder=1 keeps the filters" case needs a
 * resource that has both a reorder column and filters, and that pairing is
 * the whole point of applying filters ABOVE the reorder branch.
 */
class StoryResource extends Resource
{
    protected static ?string $model = Story::class;

    protected static ?string $slug = 'stories';

    public static function mobile(): MobileResource
    {
        return MobileResource::make()
            ->card(fn (MobileCard $card) => $card->title('title'))
            ->searchable(['title']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderable('id')
            ->filters([
                SelectFilter::make('status')
                    ->options(['draft' => 'Draft', 'published' => 'Published']),
                SelectFilter::make('tags')
                    ->options(['a' => 'A', 'b' => 'B'])
                    ->multiple(),
                TernaryFilter::make('featured'),
                TrashedFilter::make(),
            ]);
    }
}
