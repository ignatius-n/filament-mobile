<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Introspection;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Hands the validated filter state to the resource's OWN Filament filter
 * objects. This package never writes a filter's `where` itself: a
 * `SelectFilter` may query a relationship, a `TernaryFilter` carries three
 * closures a resource can replace wholesale, and a `TrashedFilter` strips a
 * global scope — reimplementing any of that would be a second, divergent
 * filter engine.
 *
 * The orchestration below is `HasFilters::applyFiltersToTableQuery()`
 * (`vendor/filament/tables/src/Concerns/HasFilters.php:169-207`) reproduced
 * in Filament's own order, including its `$isResolvingRecord` branch:
 *
 * - the base-query pass first and UNWRAPPED, because `TrashedFilter`'s
 *   `baseQuery()` removes the `SoftDeletingScope` and a scope removal nested
 *   inside a `where()` group would strip nothing;
 * - then every filter inside ONE `where()` group, so a filter's own
 *   `orWhere` cannot escape the resource's scopes and widen the result set —
 *   the same rule `ListQuery::applySearch()` follows one seam over.
 *
 * Like `FilterDeclaration` and `ReorderDeclaration`, this file names no
 * `Filament\Tables\*` class: the filters are handed in by the caller (from
 * `FilterDeclaration::tableFilters()`, so a request builds exactly one
 * `Table`) and each is an `object` whose methods are called by name, so
 * `tests/Unit/ArchitectureTest.php`'s three-file exemption stays a three-file
 * exemption.
 */
final class FilterApplier
{
    /**
     * @param  array<string, object>  $filters  this request's own
     *                                          `FilterDeclaration::tableFilters()`
     *                                          — the SAME objects the
     *                                          declaration classified, never a
     *                                          second `Table` build
     * @param  array<string, string|list<string>>  $state  `ListQuery::filters()`'s output
     * @param  bool  $isResolvingRecord  true for the record endpoint, where a
     *                                   filter that declares
     *                                   `excludeWhenResolvingRecord()` (every
     *                                   `TrashedFilter` does) must not hide a
     *                                   record addressed by id
     */
    public static function apply(
        Builder $query,
        array $filters,
        Request $request,
        array $state,
        bool $isResolvingRecord = false,
    ): void {
        // A filter's query closure may call auth() just as a ->visible() one
        // may, so the request is bound around the whole walk — including the
        // where() group below, whose closure runs immediately. Verbatim from
        // `ReorderDeclaration::authorizes()`: binding `request` retriggers
        // `AuthServiceProvider`'s rebound listener, which overwrites the
        // request's user resolver, so it is re-applied afterwards.
        $app = app();
        $previous = $app->bound('request') ? $app->make('request') : null;

        $resolver = $request->getUserResolver();
        $app->instance('request', $request);
        $request->setUserResolver($resolver);

        try {
            foreach ($filters as $name => $filter) {
                $filter->applyToBaseQuery($query, self::dataFor($filter, $state[$name] ?? null));
            }

            $query->where(function (Builder $group) use ($filters, $state, $isResolvingRecord): void {
                foreach ($filters as $name => $filter) {
                    if ($isResolvingRecord && $filter->shouldExcludeWhenResolvingRecord()) {
                        continue;
                    }

                    $filter->apply($group, self::dataFor($filter, $state[$name] ?? null));
                }
            });
        } finally {
            $previous === null ? $app->forgetInstance('request') : $app->instance('request', $previous);
        }
    }

    /**
     * One filter's `$data`, in the shape Filament's own filter form would
     * have produced: a select reads `values`/`value`, a ternary reads
     * `value`, and a plain `Filter` reads `isActive`.
     *
     * @param  string|list<string>|null  $value  null when this request named no
     *                                          value for the filter
     * @return array<string, mixed>
     */
    private static function dataFor(object $filter, string|array|null $value): array
    {
        if (is_array($value)) {
            return ['values' => $value];
        }

        if ($value !== null) {
            // '' included, deliberately: Filament reads a blank value as
            // "any" — a select no-ops on it and a ternary takes its blank
            // branch — which is exactly what an explicitly cleared filter
            // means.
            return ['value' => $value];
        }

        // Nothing named this filter, so it gets the state a freshly-mounted
        // Filament filters form would hold for it — which is NOT one state.
        //
        // A select or ternary takes Filament's own empty `$data`
        // (`getTableFilterState() ?? []`): `$data['value'] ?? null` is null,
        // so a select no-ops and a ternary takes its blank branch — the
        // `withoutTrashed()` a TrashedFilter owes an unfiltered list.
        // ponytail: `is_a()` against the string FQCN, like FilterDeclaration
        // — one class name, no import, no new architecture exemption.
        if (is_a($filter, 'Filament\\Tables\\Filters\\SelectFilter')) {
            return [];
        }

        // A `Filter` with its own `->schema()` has no `isActive` field at all
        // (`Concerns/HasSchema::getSchemaComponents():53-59` returns the
        // author's schema instead), so on web `$data['isActive'] ?? true`
        // wins and its `query()` closure RUNS. Forcing it off here would list
        // rows on the phone that the panel hides. Filament draws this exact
        // line itself in `Filters/Filter::getResetState():66-73`.
        if ($filter->hasSchema()) {
            return [];
        }

        // A bare `Filter` is a checkbox: `Filters/Filter::getFormField():55-62`
        // builds `Checkbox::make('isActive')` and seeds it from
        // `getDefaultState()`, so it is unchecked by default and PRE-checked
        // when the author declared `->default()`. Reading the default rather
        // than hardcoding false is what keeps both directions honest: an
        // ordinary custom filter no longer narrows a list the panel does not
        // narrow, and a defaulted one still does.
        return ['isActive' => (bool) $filter->getDefaultState()];
    }
}
