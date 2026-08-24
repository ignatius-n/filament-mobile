<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Resources\TableFiltersResource;
use Gait\FilamentMobile\Tests\Fixtures\Models\Company;
use Gait\FilamentMobile\Tests\Fixtures\Resources\RelationshipFilterResource;

beforeEach(function (): void {
    config()->set('filament-mobile.resources', [
        TableFiltersResource::class,
        RelationshipFilterResource::class,
    ]);
});

it('searches an over-cap static filter without publishing all options', function (): void {
    $response = $this->actingAs(makeUser('admin'))->postJson(
        '/api/mobile-panel/table-filters/filter-options',
        ['filter' => 'too_many', 'q' => 'Option 5'],
    )->assertOk()->json();

    expect($response['options'])->toBe([
        ['value' => '5', 'label' => 'Option 5'],
        ['value' => '50', 'label' => 'Option 50'],
        ['value' => '51', 'label' => 'Option 51'],
    ])->and($response['hasMore'])->toBeFalse();
});

it('runs a dynamic filter search callback', function (): void {
    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/table-filters/filter-options', [
            'filter' => 'remote_search',
            'q' => 'et',
        ])
        ->assertOk()
        ->assertExactJson([
            'options' => [['value' => 'beta', 'label' => 'Beta']],
            'hasMore' => false,
        ]);
});

it('reuses Filament relationship search and normalises ids to strings', function (): void {
    $acme = Company::create(['name' => 'Acme']);
    Company::create(['name' => 'Globex']);

    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/relationship-filters/filter-options', [
            'filter' => 'company_id',
            'q' => 'acm',
        ])
        ->assertOk()
        ->assertExactJson([
            'options' => [['value' => (string) $acme->id, 'label' => 'Acme']],
            'hasMore' => false,
        ]);
});

it('refuses inline, unknown and non-searchable filters with the same response', function (string $filter): void {
    $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/table-filters/filter-options', ['filter' => $filter])
        ->assertStatus(422);
})->with(['status', 'missing', 'empty_opts']);

it('requires authentication', function (): void {
    $this->postJson('/api/mobile-panel/table-filters/filter-options', ['filter' => 'too_many'])
        ->assertUnauthorized();
});
