<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Dotswan\MapPicker\Fields\Map;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Gait\FilamentMobile\MobileCard;
use Gait\FilamentMobile\MobileResource;
use Gait\FilamentMobile\Tests\Fixtures\Models\Banner;
use Ysfkaya\FilamentPhoneInput\Forms\PhoneInput;

final class MapPhoneProblemResource extends Resource
{
    protected static ?string $model = Banner::class;

    public static function mobile(): MobileResource
    {
        return MobileResource::make()->card(
            fn (MobileCard $card): MobileCard => $card->title('name'),
        );
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name'),
            Map::make('location')->geoMan(),
            Map::make('split_map'),
            PhoneInput::make('phone'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('name')]);
    }
}
