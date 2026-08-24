<?php

declare(strict_types=1);

use Dotswan\MapPicker\Fields\Map;
use Dotswan\MapPicker\Infolists\MapEntry;
use Filament\Schemas\Schema;
use Gait\FilamentMobile\Introspection\ComponentTypeMap;
use Gait\FilamentMobile\Introspection\HeadlessSchemaHost;
use Gait\FilamentMobile\Introspection\SchemaWalker;
use Gait\FilamentMobile\PanelSchemaBuilder;
use Gait\FilamentMobile\ResourceRegistry;
use Gait\FilamentMobile\Tests\Fixtures\Models\Banner;
use Gait\FilamentMobile\Tests\Fixtures\Resources\BannerResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\MapPhoneProblemResource;
use Gait\MobileCore\WalkWarnings;
use Ysfkaya\FilamentPhoneInput\Forms\PhoneInput;
use Ysfkaya\FilamentPhoneInput\PhoneInputNumberType;

it('maps the optional plugin classes into the first-class vocabulary', function () {
    expect(ComponentTypeMap::forClassName(Map::class))->toBe('map_point')
        ->and(ComponentTypeMap::forClassName(MapEntry::class))->toBe('map_point_entry')
        ->and(ComponentTypeMap::forClassName(PhoneInput::class))->toBe('phone');
});

it('publishes the configured map and phone nodes from a real resource schema', function () {
    $builder = new PanelSchemaBuilder(new ResourceRegistry());
    $document = $builder->build(null);
    $banner = collect($document['resources'])->firstWhere('key', 'banners');

    expect(collect($builder->warnings()->all())->where('component', 'location')->all())->toBe([])
        ->and(collect($banner['form'])->pluck('type'))->toContain('map_point', 'phone')
        ->and(collect($banner['infolist'])->pluck('type'))->toContain('map_point_entry');
});

it('publishes map interaction and camera hints and phone format asymmetry', function () {
    $components = BannerResource::form(
        Schema::make(new HeadlessSchemaHost())->model(Banner::class)->operation('create'),
    )->getComponents(withHidden: true);

    $nodes = (new SchemaWalker(new WalkWarnings()))->walk($components, 'BannerResource', 'banners', Banner::class);
    $map = collect($nodes)->firstWhere('type', 'map_point');
    $phone = collect($nodes)->firstWhere('type', 'phone');

    expect($map['config'])->toMatchArray([
        'draggable' => true,
        'clickable' => true,
        'showMarker' => true,
        'zoom' => 12,
        'minZoom' => 3,
        'maxZoom' => 19,
        'default' => ['lat' => 30.0444, 'lng' => 31.2357],
    ])->and($phone['config'])->toMatchArray([
        'format' => 'national',
        'countryPath' => 'phone_country',
        'defaultCountry' => 'EG',
    ]);
});

it('refuses GeoMan maps and phone fields that rely on Livewire IP lookup', function () {
    $warnings = new WalkWarnings();
    $schema = Schema::make(new HeadlessSchemaHost())->model(Banner::class)->operation('create')->components([
        Map::make('location')->geoMan(),
        PhoneInput::make('phone'),
    ]);

    $nodes = (new SchemaWalker($warnings))->walk(
        $schema->getComponents(withHidden: true),
        'BannerResource',
        'banners',
        Banner::class,
    );

    expect($nodes)->toBe([])
        ->and(array_column($warnings->all(), 'reason'))->each->toBeString()
        ->and(json_encode($warnings->all()))->toContain('GeoMan')->toContain('IP lookup');
});

it('refuses a map whose own state path is not a persisted attribute', function () {
    $warnings = new WalkWarnings();
    $schema = Schema::make(new HeadlessSchemaHost())->model(Banner::class)->operation('create')->components([
        Map::make('map')->afterStateUpdated(static fn (): null => null),
    ]);

    $nodes = (new SchemaWalker($warnings))->walk(
        $schema->getComponents(withHidden: true),
        'BannerResource',
        'banners',
        Banner::class,
    );

    expect($nodes)->toBe([])
        ->and($warnings->all()[0]['reason'])->toContain('split latitude/longitude');
});

it('normalises unsupported phone formats to e164', function () {
    $field = PhoneInput::make('phone')->disableLookup();
    $property = new ReflectionProperty($field, 'inputNumberFormat');
    $property->setValue($field, 'sideways');

    $schema = Schema::make(new HeadlessSchemaHost())->model(Banner::class)->operation('create')->components([$field]);
    $nodes = (new SchemaWalker(new WalkWarnings()))->walk(
        $schema->getComponents(withHidden: true),
        'BannerResource',
        'banners',
        Banner::class,
    );

    expect($nodes[0]['config']['format'])->toBe('e164');
});

it('keeps phone validation in the existing rule extractor path', function () {
    $field = PhoneInput::make('phone')
        ->disableLookup()
        ->inputNumberFormat(PhoneInputNumberType::E164)
        ->validateFor('EG');

    expect($field->getValidationRules())->not->toBeEmpty();
});

it('normalises stored numeric strings in a map record payload', function () {
    $banner = seedBannerWith([
        'location' => ['lat' => '30.0444', 'lng' => '31.2357'],
    ]);

    $this->actingAs(makeUser('admin'))
        ->getJson("/api/mobile-panel/banners/{$banner->id}")
        ->assertOk()
        ->assertJsonPath('data.location.lat', 30.0444)
        ->assertJsonPath('data.location.lng', 31.2357);
});

it('writes a map point as numbers through the ordinary form path', function () {
    $banner = seedBannerWith(['body_html' => '<p>Body</p>']);

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", [
            'name' => 'Banner',
            'body_html' => '<p>Body</p>',
            'location' => ['lat' => 24.7136, 'lng' => 46.6753],
        ])
        ->assertOk();

    expect($banner->refresh()->location)->toBe(['lat' => 24.7136, 'lng' => 46.6753]);
});

it('returns the phone plugins server-side validation as a field-keyed 422', function () {
    $banner = seedBannerWith(['body_html' => '<p>Body</p>']);

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", [
            'name' => 'Banner',
            'body_html' => '<p>Body</p>',
            'phone' => 'definitely not a phone number',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('phone');
});

it('names every fail-closed plugin shape in doctor output', function () {
    config()->set('filament-mobile.resources', [MapPhoneProblemResource::class]);

    $this->artisan('filament-mobile:doctor')
        ->expectsOutputToContain('location: GeoMan drawing state is not supported')
        ->expectsOutputToContain('split_map: map state path is not a persisted model attribute')
        ->expectsOutputToContain('phone: phone IP lookup requires Livewire')
        ->assertFailed();
});
