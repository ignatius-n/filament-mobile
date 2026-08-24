<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Resources\FilteredResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\NarrowedFilterResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\QueryBuilderResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\StaticFilterResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\TableFiltersResource;

/**
 * P24 Task 2: doctor's `Filters` section — every reason
 * `FilterDeclaration::refusals()` can name, plus the behavioural
 * `->static()` probe this package cannot reflect for directly (see
 * `Introspection/ReorderDeclaration.php`'s class doc). Sectioned like
 * `Reordering` (`DoctorCommand.php:98`): printed only when at least one
 * problem applies, never folded into the actionable exit-code count — a
 * refused filter is informational, the same as a refused reorder column.
 */
it('names a QueryBuilder filter as unsupported', function () {
    config()->set('filament-mobile.resources', [QueryBuilderResource::class]);

    $this->artisan('filament-mobile:doctor')
        ->expectsOutputToContain('QueryBuilderResource: filter [advanced] — query builder — not supported on mobile')
        ->assertExitCode(0);
});

it('names an empty select filter that cannot search remotely', function () {
    config()->set('filament-mobile.resources', [TableFiltersResource::class]);

    $this->artisan('filament-mobile:doctor')
        ->expectsOutputToContain('TableFiltersResource: filter [empty_opts] — declares no options and is not searchable')
        ->assertExitCode(0);
});

it('names a mobile()->filters() name that resolves to no table filter', function () {
    config()->set('filament-mobile.resources', [NarrowedFilterResource::class]);

    $this->artisan('filament-mobile:doctor')
        ->expectsOutputToContain('NarrowedFilterResource: filter [nope] — declared in mobile()->filters() but no such table filter')
        ->assertExitCode(0);
});

it('prints one Filters section naming all three problems across a panel', function () {
    config()->set('filament-mobile.resources', [
        QueryBuilderResource::class,
        TableFiltersResource::class,
        NarrowedFilterResource::class,
    ]);

    // No standalone 'Filters' assertion here: Laravel's expectsOutputToContain
    // matches one Mockery expectation per printed line, first-declared-first-
    // matched, and "Filters" is itself a substring of "TableFiltersResource"
    // — declaring it would silently swallow that line's own, more specific,
    // assertion below. The three resource-scoped lines already prove the
    // section printed.
    $this->artisan('filament-mobile:doctor')
        ->expectsOutputToContain('QueryBuilderResource: filter [advanced] — query builder — not supported on mobile')
        ->expectsOutputToContain('TableFiltersResource: filter [empty_opts] — declares no options and is not searchable')
        ->expectsOutputToContain('NarrowedFilterResource: filter [nope] — declared in mobile()->filters() but no such table filter')
        ->assertExitCode(0);
});

it('names a published select filter that narrows nothing, behaviourally', function () {
    config()->set('filament-mobile.resources', [StaticFilterResource::class]);

    $this->artisan('filament-mobile:doctor')
        ->expectsOutputToContain('StaticFilterResource: filter [status] narrows nothing — is it ->static()?')
        ->assertExitCode(0);
});

it('names a published filter that throws when applied, distinctly from a static no-op', function () {
    // TableFiltersResource's TrashedFilter runs against Slide, which has no
    // SoftDeletes trait — apply() throws BadMethodCallException calling
    // withTrashed(). This is a worse bug than a static no-op (the same
    // throw hits the real list endpoint once a request applies the
    // filter), so it gets its own line, never the "narrows nothing" one.
    config()->set('filament-mobile.resources', [TableFiltersResource::class]);

    $this->artisan('filament-mobile:doctor')
        ->expectsOutputToContain('TableFiltersResource: filter [trashed] threw when applied — BadMethodCallException: Call to undefined method Illuminate\Database\Eloquent\Builder::withTrashed()')
        ->assertExitCode(0);
});

it('does not report an over-cap remote filter as widening the mobile list', function () {
    config()->set('filament-mobile.resources', [TableFiltersResource::class]);

    $this->artisan('filament-mobile:doctor')
        ->doesntExpectOutputToContain("TableFiltersResource: filter [too_many] carries a default but is not served on mobile — the mobile list will be wider than the panel's")
        ->assertExitCode(0);
});

it('names a filter narrowed away by mobile()->filters() that carries a default', function () {
    config()->set('filament-mobile.resources', [NarrowedFilterResource::class]);

    $this->artisan('filament-mobile:doctor')
        ->expectsOutputToContain("NarrowedFilterResource: filter [category] carries a default but is not served on mobile — the mobile list will be wider than the panel's")
        ->assertExitCode(0);
});

it('produces no Filters heading for a clean resource', function () {
    config()->set('filament-mobile.resources', [FilteredResource::class]);

    $this->artisan('filament-mobile:doctor')
        ->doesntExpectOutputToContain('Filters')
        ->assertExitCode(0);
});
