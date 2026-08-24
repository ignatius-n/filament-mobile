<?php

declare(strict_types=1);

use Gait\FilamentMobile\Introspection\FilterDeclaration;
use Gait\FilamentMobile\Tests\Fixtures\Resources\AdminOnlyFilterResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\FilteredResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\NarrowedFilterResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\QueryBuilderResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\TableFiltersResource;

it('publishes a select filter with its inline options', function (): void {
    $filters = FilterDeclaration::for(FilteredResource::class, request());

    expect($filters)->toHaveCount(1)
        ->and($filters[0]->toNode())->toBe([
            'type' => 'select',
            'name' => 'status',
            'label' => 'Status',
            'config' => ['options' => [
                ['value' => 'draft', 'label' => 'Draft'],
                ['value' => 'published', 'label' => 'Published'],
            ]],
        ]);
});

it('refuses a query builder and names it', function (): void {
    expect(FilterDeclaration::for(QueryBuilderResource::class, request()))->toBe([])
        ->and(FilterDeclaration::refusals(QueryBuilderResource::class, request()))
        ->toContain('QueryBuilderResource: filter [advanced] — query builder — not supported on mobile');
});

it('marks a select filter multiple() as multiple', function (): void {
    $filters = FilterDeclaration::for(TableFiltersResource::class, request(), only: ['category']);

    expect($filters)->toHaveCount(1)
        ->and($filters[0]->toNode())->toBe([
            'type' => 'select',
            'name' => 'category',
            'label' => 'Category',
            'config' => [
                'options' => [
                    ['value' => 'a', 'label' => 'A'],
                    ['value' => 'b', 'label' => 'B'],
                ],
                'multiple' => true,
            ],
        ]);
});

it('marks a MultiSelectFilter as multiple without calling ->multiple()', function (): void {
    $filters = FilterDeclaration::for(TableFiltersResource::class, request(), only: ['tags']);

    expect($filters)->toHaveCount(1)
        ->and($filters[0]->multiple)->toBeTrue()
        ->and($filters[0]->toNode()['config']['multiple'])->toBeTrue();
});

it('shapes a TernaryFilter as a select with the 1/0 pair and its placeholder', function (): void {
    $filters = FilterDeclaration::for(TableFiltersResource::class, request(), only: ['is_active']);

    expect($filters)->toHaveCount(1)
        ->and($filters[0]->toNode())->toBe([
            'type' => 'select',
            'name' => 'is_active',
            'label' => 'Is Active',
            'config' => [
                'options' => [
                    ['value' => '1', 'label' => 'Yes'],
                    ['value' => '0', 'label' => 'No'],
                ],
                'placeholder' => '-',
            ],
        ]);
});

it('shapes a TrashedFilter the same way, with Filament\'s own three labels', function (): void {
    $filters = FilterDeclaration::for(TableFiltersResource::class, request(), only: ['trashed']);

    expect($filters)->toHaveCount(1)
        ->and($filters[0]->toNode())->toBe([
            'type' => 'select',
            'name' => 'trashed',
            'label' => __('filament-tables::table.filters.trashed.label'),
            'config' => [
                'options' => [
                    ['value' => '1', 'label' => __('filament-tables::table.filters.trashed.with_trashed')],
                    ['value' => '0', 'label' => __('filament-tables::table.filters.trashed.only_trashed')],
                ],
                'placeholder' => __('filament-tables::table.filters.trashed.without_trashed'),
            ],
        ]);
});

it('carries a select ->default() through to the node', function (): void {
    $filters = FilterDeclaration::for(TableFiltersResource::class, request(), only: ['status']);

    expect($filters[0]->toNode()['default'])->toBe('draft');
});

it('normalises a ternary ->default(true) to \'1\'', function (): void {
    $filters = FilterDeclaration::for(TableFiltersResource::class, request(), only: ['is_featured']);

    expect($filters[0]->toNode()['default'])->toBe('1');
});

/**
 * P24 final wave, finding 1. A published `default` is not decoration: the
 * client seeds its filter state from it and echoes it on the very first
 * fetch, where `ListQuery::filters()` validates it like any other value. A
 * default that fails that validation is a permanent hard 422 for every
 * client of that resource, so it is normalised HERE, at the publish seam —
 * never leniently re-read at the parse seam, which would only move the
 * divergence.
 */
it('normalises a multiple filter\'s scalar ->default() to a list', function (): void {
    $filters = FilterDeclaration::for(TableFiltersResource::class, request(), only: ['multi_scalar']);

    expect($filters[0]->toNode()['default'])->toBe(['a'])
        ->and($filters[0]->toValidatorArray()['default'])->toBe(['a']);
});

it('normalises a single filter\'s array ->default() to a scalar', function (): void {
    $filters = FilterDeclaration::for(TableFiltersResource::class, request(), only: ['single_list']);

    expect($filters[0]->toNode()['default'])->toBe('a')
        ->and($filters[0]->toValidatorArray()['default'])->toBe('a');
});

it('drops a ->default() that is not one of the published options, and names it', function (): void {
    $filters = FilterDeclaration::for(TableFiltersResource::class, request(), only: ['stray_default']);

    expect($filters[0]->toNode())->not->toHaveKey('default')
        ->and($filters[0]->toValidatorArray()['default'])->toBeNull()
        ->and(FilterDeclaration::refusals(TableFiltersResource::class, request(), only: ['stray_default']))
        ->toContain('TableFiltersResource: filter [stray_default] default [nope] is not one of its options — dropped, or every client would 422 on its first request');
});

it('publishes an empty-array ->default() as no default at all', function (): void {
    // The same reading `noteDivergence()` already applies to the refusal
    // path: `->default([])` narrows nothing on the web either. Left
    // verbatim it seeded a phantom active filter on the client — a badge
    // reading 1 over a blank, deletable chip.
    $filters = FilterDeclaration::for(TableFiltersResource::class, request(), only: ['empty_list_default']);

    expect($filters[0]->toNode())->not->toHaveKey('default')
        ->and($filters[0]->toValidatorArray()['default'])->toBeNull();
});

it('converts a single select to its validator array', function (): void {
    $filters = FilterDeclaration::for(TableFiltersResource::class, request(), only: ['status']);

    expect($filters[0]->toValidatorArray())->toBe([
        'name' => 'status',
        'kind' => 'select',
        'multiple' => false,
        'options' => ['draft', 'published'],
        'default' => 'draft',
    ]);
});

it('converts a multiple select to its validator array', function (): void {
    $filters = FilterDeclaration::for(TableFiltersResource::class, request(), only: ['featured_categories']);

    expect($filters[0]->toValidatorArray())->toBe([
        'name' => 'featured_categories',
        'kind' => 'select',
        'multiple' => true,
        'options' => ['a', 'b'],
        'default' => ['a', 'b'],
    ]);
});

it('converts a ternary to its validator array', function (): void {
    $filters = FilterDeclaration::for(TableFiltersResource::class, request(), only: ['is_featured']);

    expect($filters[0]->toValidatorArray())->toBe([
        'name' => 'is_featured',
        'kind' => 'ternary',
        'multiple' => false,
        'options' => ['1', '0'],
        'default' => '1',
    ]);
});

it('refuses a bare Filter with a custom query as an unsupported schema', function (): void {
    expect(FilterDeclaration::for(TableFiltersResource::class, request(), only: ['notes']))->toBe([])
        ->and(FilterDeclaration::refusals(TableFiltersResource::class, request(), only: ['notes']))
        ->toContain('TableFiltersResource: filter [notes] — custom filter schema — deferred');
});

it('refuses a select filter with no options to inline', function (): void {
    expect(FilterDeclaration::for(TableFiltersResource::class, request(), only: ['empty_opts']))->toBe([])
        ->and(FilterDeclaration::refusals(TableFiltersResource::class, request(), only: ['empty_opts']))
        ->toContain('TableFiltersResource: filter [empty_opts] — declares no options and is not searchable');
});

it('publishes a select filter over the inline cap through remote options', function (): void {
    $filter = FilterDeclaration::for(TableFiltersResource::class, request(), only: ['too_many'])[0];

    expect($filter->toNode()['config'])->toBe([
        'optionsUrl' => '/api/mobile-panel/table-filters/filter-options',
        'searchable' => true,
    ])->and($filter->toValidatorArray()['options'])->toHaveCount(51)
        ->and($filter->toValidatorArray()['remote'])->toBeTrue();
});

it('publishes a dynamic searchable filter with no inline options', function (): void {
    $filter = FilterDeclaration::for(TableFiltersResource::class, request(), only: ['remote_search'])[0];

    expect($filter->toNode()['config'])->toBe([
        'optionsUrl' => '/api/mobile-panel/table-filters/filter-options',
        'searchable' => true,
    ])->and($filter->toValidatorArray()['options'])->toBe([])
        ->and($filter->toValidatorArray()['remote'])->toBeTrue();
});

it('narrows to just the named filter via $only', function (): void {
    $filters = FilterDeclaration::for(TableFiltersResource::class, request(), only: ['status']);

    expect($filters)->toHaveCount(1)
        ->and($filters[0]->name)->toBe('status');
});

it('refuses an $only name that matches no table filter', function (): void {
    expect(FilterDeclaration::refusals(TableFiltersResource::class, request(), only: ['no_such_filter']))
        ->toContain('TableFiltersResource: filter [no_such_filter] — declared in mobile()->filters() but no such table filter');
});

it('serves an over-cap filter default without a widening refusal', function (): void {
    expect(FilterDeclaration::refusals(TableFiltersResource::class, request(), only: ['too_many']))
        ->not->toContain("TableFiltersResource: filter [too_many] carries a default but is not served on mobile — the mobile list will be wider than the panel's");
});

it('names a filter narrowed away by mobile()->filters() that carries a default', function (): void {
    expect(FilterDeclaration::refusals(NarrowedFilterResource::class, request(), only: ['status', 'nope']))
        ->toContain("NarrowedFilterResource: filter [category] carries a default but is not served on mobile — the mobile list will be wider than the panel's");
});

it('does not flag a refused filter with no default', function (): void {
    $refusals = FilterDeclaration::refusals(QueryBuilderResource::class, request());

    expect(array_filter($refusals, fn (string $line): bool => str_contains($line, 'carries a default')))
        ->toBe([]);
});

it('does not flag a refused filter whose default is an empty array — it narrows nothing on the web either', function (): void {
    // No $only here, deliberately: passing only: ['empty_opts'] would also
    // narrow every OTHER fixture filter away, and several of those carry
    // real defaults — their own "carries a default" lines would drown out
    // the one assertion this test exists for.
    $refusals = FilterDeclaration::refusals(TableFiltersResource::class, request());

    expect($refusals)->toContain('TableFiltersResource: filter [empty_opts] — declares no options and is not searchable')
        ->and(array_filter($refusals, fn (string $line): bool => str_contains($line, 'filter [empty_opts] carries a default')))
        ->toBe([]);
});

it('publishes a ->visible() filter for an admin request and withholds it from a guest, proving the request binding', function (): void {
    $admin = makeUser('admin');
    $guest = makeUser('guest');

    expect(FilterDeclaration::for(AdminOnlyFilterResource::class, requestAs($admin)))->toHaveCount(1)
        ->and(FilterDeclaration::for(AdminOnlyFilterResource::class, requestAs($guest)))->toBe([]);
});
