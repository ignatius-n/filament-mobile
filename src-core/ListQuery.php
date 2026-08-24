<?php

declare(strict_types=1);

namespace Gait\MobileCore;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The query-parameter machinery every paginated list endpoint shares: the
 * resource index (`MobilePanelController::index`) and, since P11, the
 * relation endpoint (`RelationController::__invoke`). One mechanism, not two
 * — the call sites differ only in where the searchable columns and the
 * sorts/default triple come from (the resource's declarations, or one
 * relation's).
 *
 * Everything here runs AFTER the endpoint's full gate sequence: a 422 for a
 * bad parameter must never leak whether a resource, relation or record
 * exists to a caller the gates would have refused.
 */
final class ListQuery
{
    /**
     * The one place a query parameter becomes a PHP value. `?sort[]=x` binds an
     * array, and every scalar string function below would fatal on it — a 500
     * for input a client fully controls. Non-strings are rejected with the same
     * 422 the sort path already promises, and an empty value is read as absent
     * (`?sort=` is an ordinary way to say "no sort", not an unknown key).
     */
    public static function stringQuery(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        abort_if($value !== null && ! is_string($value), 422, "[{$key}] must be a string.");

        return ($value === null || $value === '') ? null : $value;
    }

    /** @param list<string> $columns */
    public static function applySearch(Builder $query, ?string $search, array $columns): void
    {
        if ($search === null || $columns === []) {
            return;
        }

        // `%` and `_` are LIKE metacharacters: unescaped, `?search=%` matches
        // every row and the filter stops filtering. The ESCAPE clause is
        // explicit because SQLite — unlike MySQL and Postgres — has no default
        // escape character.
        //
        // The escape character is `!`, not the conventional backslash. MySQL
        // treats a backslash as an escape *inside string literals*, so the
        // clause `escape '\'` is an unterminated literal and every search
        // request is a 1064 syntax error — which SQLite, where the package's
        // tests run, parses happily as a literal backslash. Writing `escape
        // '\\'` would fix MySQL and break SQLite, which reads it as a
        // two-character escape string and rejects it. `!` needs no escaping in
        // a string literal on any of the three drivers, so one clause is
        // correct everywhere.
        $term = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';

        // One where group, not a chain of top-level orWheres: a bare orWhere
        // would escape any scope the resource query already applied and widen
        // the result set past it. On a relation query that scope is the
        // relationship's own constraint, and the group must not escape it
        // either — the same rule, one nesting level down.
        // ponytail: plain columns only. A dotted searchable() would need
        // whereHas — add it the day a resource declares one.
        $query->where(function (Builder $group) use ($term, $columns): void {
            foreach ($columns as $column) {
                $group->orWhereRaw(
                    $group->getGrammar()->wrap($column) . " like ? escape '!'",
                    [$term],
                );
            }
        });
    }

    /**
     * `?filter[name]=value` / `?filter[name][]=value`, validated against
     * exactly what the schema published for THIS requester — nothing here
     * touches a query, and nothing here can see a Filament filter object:
     * the caller converts each `PublishedFilter` with `toValidatorArray()`
     * first, because mobile-core must not depend on the panel package.
     *
     * A name that was not published is a 422, never a silently ignored
     * parameter — the same posture `applySort()` takes for an undeclared
     * sort key, and for the same reason: a client narrowing by something
     * the server ignores would show its user a list that is not the list
     * they asked for.
     *
     * An EMPTY value means "any" and beats the filter's default: it is how a
     * client clears a pre-filled filter, and reading it as "absent" would
     * make a defaulted filter impossible to turn off. Every other absent
     * filter falls back to its published default, which is what the web
     * panel's own pre-filled filter form does.
     *
     * @param  list<array{name: string, kind: string, multiple: bool, options: list<string>, default: mixed, remote?: bool}>  $published
     * @return array<string, string|list<string>>
     */
    public static function filters(Request $request, array $published): array
    {
        $requested = $request->query('filter');

        abort_if($requested !== null && ! is_array($requested), 422, '[filter] must be an array.');

        $declarations = [];

        foreach ($published as $declaration) {
            $declarations[$declaration['name']] = $declaration;
        }

        $state = [];

        foreach ($requested ?? [] as $name => $value) {
            $declaration = $declarations[$name] ?? abort(422, "Unknown filter [{$name}].");

            $state[$name] = self::filterValue($declaration, $value);
        }

        foreach ($declarations as $name => $declaration) {
            if (! array_key_exists($name, $state) && $declaration['default'] !== null) {
                $state[$name] = $declaration['default'];
            }
        }

        return $state;
    }

    /**
     * @param  array{name: string, kind: string, multiple: bool, options: list<string>, default: mixed, remote?: bool}  $declaration
     * @return string|list<string>
     */
    private static function filterValue(array $declaration, mixed $value): string|array
    {
        $name = $declaration['name'];

        // A ternary is always the fixed 1/0 pair, whatever its options list
        // says — the same pair `PublishedFilter` puts on the wire for it.
        $options = $declaration['kind'] === 'ternary' ? ['1', '0'] : $declaration['options'];
        // A dynamic remote filter cannot publish its complete option set.
        // It still enforces scalar/list shape below; the filter's own
        // Filament query is the authority for what the submitted key means.
        // Large STATIC remote filters keep their full server-side options in
        // this declaration and therefore remain strictly whitelisted.
        $allowsDynamic = ($declaration['remote'] ?? false) && $options === [];

        // `?filter[status]=` — named, with no value: "any", and the one
        // scalar a MULTIPLE filter accepts too, because a query string
        // cannot express an empty list and a defaulted filter of either kind
        // would otherwise be impossible to clear.
        //
        // null, not just '': Laravel's own `ConvertEmptyStringsToNull`
        // middleware — on by default in every skeleton, and on in this
        // package's own route stack — has already rewritten the empty string
        // by the time any of this runs, so reading only `''` makes clearing
        // a filter a 422 on a real HTTP request while passing in a unit test.
        if ($value === null || $value === '') {
            return $declaration['multiple'] ? [] : '';
        }

        if ($declaration['multiple']) {
            abort_unless(is_array($value), 422, "Filter [{$name}] takes a list of values.");

            foreach ($value as $item) {
                abort_unless(
                    is_string($item) && ($allowsDynamic || in_array($item, $options, true)),
                    422,
                    "Unknown value for filter [{$name}].",
                );
            }

            return array_values($value);
        }

        abort_if(is_array($value), 422, "Filter [{$name}] takes a single value.");

        // Every query-string scalar is a string, so a non-string here is a
        // caller that built the Request itself — one message either way,
        // because "not a value this filter offers" is exactly what it is.
        abort_unless(is_string($value), 422, "Unknown value for filter [{$name}].");

        abort_unless($allowsDynamic || in_array($value, $options, true), 422, "Unknown value for filter [{$name}].");

        return $value;
    }

    /**
     * The resolved declarations, not the MobileResource: the resource index
     * passes the resource's own triple, the relation endpoint passes one
     * relation's, and this method cannot tell the difference.
     *
     * @param  array<string, string>  $sorts  sort key => label
     */
    public static function applySort(
        Builder $query,
        Request $request,
        array $sorts,
        ?string $defaultSortKey,
        string $defaultSortDirection,
    ): void {
        $requested = self::stringQuery($request, 'sort');
        $key = $requested ?? $defaultSortKey;

        if ($key === null) {
            return;
        }

        // An undeclared key is an error, never a silently ignored parameter:
        // a client sorting by a column that does not exist must find out.
        // This holds even when NOTHING is declared — the parameter claimed a
        // capability, the search parameter did not.
        abort_unless(array_key_exists($key, $sorts), 422, "Unknown sort key [{$key}].");

        $direction = self::stringQuery($request, 'direction')
            ?? ($key === $defaultSortKey ? $defaultSortDirection : 'asc');

        $query->orderBy($key, strtolower($direction) === 'desc' ? 'desc' : 'asc');
    }
}
