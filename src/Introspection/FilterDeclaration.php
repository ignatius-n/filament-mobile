<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Introspection;

use Illuminate\Http\Request;

/**
 * Reads a resource's `table()->filters()` headlessly and classifies each one
 * into a `PublishedFilter` or a refusal line naming why it cannot be served —
 * Task 1 only reads and classifies. Filament applies filters; this package
 * never re-implements a `where`.
 *
 * Class names are compared against string FQCNs
 * (`is_a($filter, 'Filament\\Tables\\Filters\\SelectFilter')`) rather than
 * imported classes, so — like `ReorderDeclaration` — this file never
 * references `Filament\Tables\Filters\*` and stays outside
 * `ArchitectureTest`'s three-file Livewire/Tables exemption.
 *
 * Order matters: `TrashedFilter` extends `TernaryFilter` extends
 * `SelectFilter`. Only the `TernaryFilter`-before-`SelectFilter` check is
 * load-bearing — without it a bare `TernaryFilter` would be read as a select
 * with no options and refused. `TrashedFilter` has no branch of its own: it
 * IS a `TernaryFilter` (`is_a()` matches it there too), and its distinct
 * labels/placeholder come for free from its own `getTrueLabel()` /
 * `getFalseLabel()` / `getPlaceholder()` overrides — a separate branch would
 * return exactly what the `TernaryFilter` branch already returns.
 * `MultiSelectFilter` has no branch of its own either, for the same reason:
 * it IS a `SelectFilter` whose own `isMultiple()` is hardcoded `true`, so
 * `classifyOne()`'s `SelectFilter` branch — which reads `isMultiple()` off
 * the filter — already answers `multiple: true` for it.
 */
final class FilterDeclaration
{
    /**
     * Every table filter this request can see, keyed by name — and the ONE
     * `Table` a request builds. `HeadlessTableHost::tableFor()` re-runs the
     * resource's whole `table()` closure on every call, so the result is
     * built here once and handed to BOTH classification (`classify()` below)
     * and application (`FilterApplier::apply()`), rather than each building
     * its own.
     *
     * Deliberately not memoised in a static, unlike `ReorderDeclaration`'s:
     * that one caches a class-static boolean, while these objects' own
     * `->options()` ARE the published wire contents and the 422 whitelist.
     * `SelectFilter::make('author')->options(User::pluck('name', 'id'))` under
     * a worker-lifetime static (Octane, Swoole) would freeze the first
     * request's option set forever — publishing stale options and 422-ing
     * new ones, while `FilterApplier` applied a fresh filter. See
     * `HeadlessTableHost`'s own class doc, which refuses exactly this.
     *
     * @param  class-string  $resourceClass
     * @return array<string, object>
     */
    public static function tableFilters(string $resourceClass, Request $request): array
    {
        return self::withRequest(
            $request,
            static fn (): array => HeadlessTableHost::tableFor($resourceClass)->getFilters(),
        );
    }

    /**
     * @param  class-string  $resourceClass
     * @param  list<string>  $only
     * @return list<PublishedFilter>
     */
    public static function for(string $resourceClass, Request $request, array $only = []): array
    {
        return self::classify(self::tableFilters($resourceClass, $request), $resourceClass, $request, $only)[0];
    }

    /**
     * @param  class-string  $resourceClass
     * @param  list<string>  $only
     * @return list<string>
     */
    public static function refusals(string $resourceClass, Request $request, array $only = []): array
    {
        return self::classify(self::tableFilters($resourceClass, $request), $resourceClass, $request, $only)[1];
    }

    /**
     * Public so a caller that already holds this request's `tableFilters()`
     * — the list endpoint, which also hands them to `FilterApplier`, and
     * `filament-mobile:doctor`, which probes them — classifies the same
     * objects instead of building a second `Table`.
     *
     * @param  array<string, object>  $filters
     * @param  class-string  $resourceClass
     * @param  list<string>  $only
     * @return array{0: list<PublishedFilter>, 1: list<string>}
     */
    public static function classify(array $filters, string $resourceClass, Request $request, array $only = []): array
    {
        return self::withRequest($request, static function () use ($filters, $resourceClass, $only): array {
            $base = class_basename($resourceClass);

            $published = [];
            $refusals = [];

            // Checked against the VISIBLE filter set only, deliberately: the
            // schema must never publish a filter this requester cannot see,
            // so an `$only` name for a filter that exists but is hidden from
            // this request reads identically to a name that was never
            // declared — both "no such table filter", one refusal line.
            foreach ($only as $name) {
                if (! array_key_exists($name, $filters)) {
                    $refusals[] = "{$base}: filter [{$name}] — declared in mobile()->filters() but no such table filter";
                }
            }

            foreach ($filters as $name => $filter) {
                if ($only !== [] && ! in_array($name, $only, true)) {
                    self::noteDivergence($filter, $base, $name, $refusals);

                    continue;
                }

                $result = self::classifyOne($filter, $name, $resourceClass, $base, $refusals);

                if (is_string($result)) {
                    $refusals[] = "{$base}: filter [{$name}] — {$result}";
                    self::noteDivergence($filter, $base, $name, $refusals);

                    continue;
                }

                $published[] = $result;
            }

            return [$published, $refusals];
        });
    }

    /**
     * Copied verbatim from `ReorderDeclaration::authorizes()`: binding
     * `request` into the container retriggers `AuthServiceProvider`'s
     * rebound listener, which overwrites `Request::setUserResolver()` —
     * clobbering whatever resolver the caller already set. Re-applying it
     * after the bind is what makes `request()->user()` inside a filter's
     * `->visible()` closure — or inside an `->options()` one — answer for
     * THIS request's own user.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $read
     * @return TReturn
     */
    public static function withRequest(Request $request, callable $read): mixed
    {
        $app = app();
        $previous = $app->bound('request') ? $app->make('request') : null;

        $resolver = $request->getUserResolver();
        $app->instance('request', $request);
        $request->setUserResolver($resolver);

        try {
            return $read();
        } finally {
            $previous === null ? $app->forgetInstance('request') : $app->instance('request', $previous);
        }
    }

    /**
     * A filter that never reaches the mobile client — refused (any reason)
     * or narrowed away by `mobile()->filters([...])` — but whose panel
     * default still applies on the web makes the mobile list observably
     * WIDER than the panel's: the web silently filters down to that
     * default, mobile has no way to. Folded into the same refusal lines
     * `refusals()` already reports, rather than a new doctor section, so a
     * reader finds the divergence right next to the refusal that causes it.
     *
     * @param  list<string>  $refusals
     */
    private static function noteDivergence(object $filter, string $base, string $name, array &$refusals): void
    {
        $default = $filter->getDefaultState();

        if ($default !== null && $default !== []) {
            $refusals[] = "{$base}: filter [{$name}] carries a default but is not served on mobile — the mobile list will be wider than the panel's";
        }
    }

    /** @param  list<string>  $refusals */
    private static function classifyOne(object $filter, string $name, string $resourceClass, string $base, array &$refusals): PublishedFilter|string
    {
        // Catches TrashedFilter too (it extends TernaryFilter) — see the
        // class doc for why that needs no branch of its own.
        if (is_a($filter, 'Filament\\Tables\\Filters\\TernaryFilter')) {
            return self::ternary($filter, $name);
        }

        // Catches MultiSelectFilter too (it extends SelectFilter and hardcodes
        // isMultiple() to true) — see the class doc for why that needs no
        // branch of its own.
        if (is_a($filter, 'Filament\\Tables\\Filters\\SelectFilter')) {
            return self::select($filter, $name, $filter->isMultiple(), $resourceClass, $base, $refusals);
        }

        if (is_a($filter, 'Filament\\Tables\\Filters\\QueryBuilder')) {
            return 'query builder — not supported on mobile';
        }

        return 'custom filter schema — deferred';
    }

    private static function ternary(object $filter, string $name): PublishedFilter
    {
        $raw = $filter->getDefaultState();

        return new PublishedFilter(
            name: $name,
            label: (string) $filter->getLabel(),
            kind: 'ternary',
            options: [
                ['value' => '1', 'label' => (string) $filter->getTrueLabel()],
                ['value' => '0', 'label' => (string) $filter->getFalseLabel()],
            ],
            multiple: false,
            placeholder: $filter->getPlaceholder(),
            default: $raw === null ? null : ($raw ? '1' : '0'),
        );
    }

    /** @param  list<string>  $refusals */
    private static function select(object $filter, string $name, bool $multiple, string $resourceClass, string $base, array &$refusals): PublishedFilter|string
    {
        $options = self::flatOptions($filter->getOptions());
        $cap = (int) config('filament-mobile.options_inline_max', 50);
        $searchable = (bool) $filter->getSearchable();

        if ($options === [] && ! $searchable) {
            return 'declares no options and is not searchable';
        }

        $remote = $options === [] || count($options) > $cap;

        return new PublishedFilter(
            name: $name,
            label: (string) $filter->getLabel(),
            kind: 'select',
            options: $options,
            multiple: $multiple,
            placeholder: null,
            default: $options === []
                ? self::remoteDefaultFor($filter->getDefaultState(), $multiple)
                : self::defaultFor($filter->getDefaultState(), $options, $multiple, $base, $name, $refusals),
            optionsUrl: $remote
                ? '/' . trim((string) config('filament-mobile.prefix'), '/')
                    . '/' . str_replace('/', '-', $resourceClass::getSlug()) . '/filter-options'
                : null,
        );
    }

    /** @return string|list<string>|null */
    private static function remoteDefaultFor(mixed $raw, bool $multiple): string|array|null
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return null;
        }

        $values = array_map(static fn (mixed $value): string => (string) $value, is_array($raw) ? $raw : [$raw]);

        return $multiple ? $values : $values[0];
    }

    /**
     * A published `default` is not decoration: the client seeds its filter
     * state from it and echoes it on its FIRST fetch, where this package's
     * own `ListQuery::filters()` validates it like any other value. So a
     * default that would fail that validation is a permanent hard 422 for
     * every client of the resource — the server publishing a value it will
     * itself reject.
     *
     * Normalised here, at the publish seam, rather than leniently re-read at
     * the parse seam: the wire contract stays one shape per arity, and
     * `FilterApplier::dataFor()` (which reads the PHP type) and the
     * `multiple` flag can no longer disagree.
     *
     * Three shapes are corrected: `->multiple()->default('a')` (published as
     * a list), `->default(['a'])` on a single-value filter (published as its
     * first value — `(string) ['a']` is not a value, it is a warning), and a
     * value the published options do not offer (dropped, with a doctor line
     * naming it). An empty default is no default at all, matching what
     * `noteDivergence()` already ruled for the refusal path.
     *
     * @param  list<array{value: string, label: string}>  $options
     * @param  list<string>  $refusals
     * @return string|list<string>|null
     */
    private static function defaultFor(mixed $raw, array $options, bool $multiple, string $base, string $name, array &$refusals): string|array|null
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return null;
        }

        $offered = array_column($options, 'value');
        $kept = [];

        foreach (is_array($raw) ? $raw : [$raw] as $value) {
            $value = (string) $value;

            if (in_array($value, $offered, true)) {
                $kept[] = $value;

                continue;
            }

            $refusals[] = "{$base}: filter [{$name}] default [{$value}] is not one of its options — dropped, or every client would 422 on its first request";
        }

        if ($kept === []) {
            return null;
        }

        return $multiple ? $kept : $kept[0];
    }

    /**
     * Mirrors `SchemaWalker::flatOptions()`'s shape (a form select's option
     * groups flatten the same way) without reusing its private method.
     *
     * @param  array<mixed>  $options
     * @return list<array{value: string, label: string}>
     */
    private static function flatOptions(array $options): array
    {
        $flat = [];

        foreach ($options as $value => $label) {
            if (is_array($label)) {
                $flat = [...$flat, ...self::flatOptions($label)];

                continue;
            }

            $flat[] = ['value' => (string) $value, 'label' => (string) $label];
        }

        return $flat;
    }
}
