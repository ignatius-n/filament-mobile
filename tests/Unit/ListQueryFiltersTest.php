<?php

declare(strict_types=1);

use Gait\FilamentMobile\Introspection\PublishedFilter;
use Gait\MobileCore\ListQuery;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * P24 Task 3: `ListQuery::filters()` — the pure half, parsing `?filter[...]`
 * and validating it against what the schema published.
 *
 * It lives in `gait/laravel-mobile-core`, but its test lives HERE: that
 * package's own suite has neither `illuminate/http` nor an application
 * container in `vendor/`, and this method needs both (a `Request` to read,
 * and the container `abort()` resolves through). filament-mobile requires
 * mobile-core, so the class under test is the same one either way.
 */

/** @param array<string, mixed> $query */
function filterRequest(array $query): Request
{
    return Request::create('/', 'GET', $query);
}

/** The `status` select as `PublishedFilter` publishes it. */
function publishedStatus(string|array|null $default = null): array
{
    return (new PublishedFilter(
        name: 'status',
        label: 'Status',
        kind: 'select',
        options: [['value' => 'draft', 'label' => 'Draft'], ['value' => 'published', 'label' => 'Published']],
        multiple: false,
        placeholder: null,
        default: $default,
    ))->toValidatorArray();
}

function publishedTags(string|array|null $default = null): array
{
    return (new PublishedFilter(
        name: 'tags',
        label: 'Tags',
        kind: 'select',
        options: [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']],
        multiple: true,
        placeholder: null,
        default: $default,
    ))->toValidatorArray();
}

function publishedFeatured(?string $default = null): array
{
    return (new PublishedFilter(
        name: 'featured',
        label: 'Featured',
        kind: 'ternary',
        options: [['value' => '1', 'label' => 'Yes'], ['value' => '0', 'label' => 'No']],
        multiple: false,
        placeholder: null,
        default: $default,
    ))->toValidatorArray();
}

it('returns an empty state for a request with no filter parameter and no defaults', function () {
    expect(ListQuery::filters(filterRequest([]), [publishedStatus(), publishedTags()]))->toBe([]);
});

it('reads a published filter the request names', function () {
    expect(ListQuery::filters(
        filterRequest(['filter' => ['status' => 'draft']]),
        [publishedStatus()],
    ))->toBe(['status' => 'draft']);
});

it('reads a list for a multiple filter and re-indexes it', function () {
    expect(ListQuery::filters(
        filterRequest(['filter' => ['tags' => [1 => 'a', 3 => 'b']]]),
        [publishedTags()],
    ))->toBe(['tags' => ['a', 'b']]);
});

it('accepts string values for a dynamic remote filter while preserving arity checks', function () {
    $single = [...publishedStatus(), 'options' => [], 'remote' => true];
    $multiple = [...publishedTags(), 'options' => [], 'remote' => true];

    expect(ListQuery::filters(
        filterRequest(['filter' => ['status' => 'server-key']]),
        [$single],
    ))->toBe(['status' => 'server-key'])
        ->and(ListQuery::filters(
            filterRequest(['filter' => ['tags' => ['one', 'two']]]),
            [$multiple],
        ))->toBe(['tags' => ['one', 'two']]);
});

it('validates a ternary against exactly 1 and 0', function () {
    expect(ListQuery::filters(filterRequest(['filter' => ['featured' => '1']]), [publishedFeatured()]))
        ->toBe(['featured' => '1'])
        ->and(ListQuery::filters(filterRequest(['filter' => ['featured' => '0']]), [publishedFeatured()]))
        ->toBe(['featured' => '0']);
});

it('fills in a published default the request omits', function () {
    expect(ListQuery::filters(
        filterRequest([]),
        [publishedStatus(default: 'draft'), publishedTags(default: ['a', 'b']), publishedFeatured(default: '1')],
    ))->toBe(['status' => 'draft', 'tags' => ['a', 'b'], 'featured' => '1']);
});

it('lets an explicit empty value beat the default rather than falling back to it', function () {
    expect(ListQuery::filters(
        filterRequest(['filter' => ['status' => '']]),
        [publishedStatus(default: 'draft')],
    ))->toBe(['status' => '']);
});

it('reads the null ConvertEmptyStringsToNull leaves behind as that same empty value', function () {
    // The shape `?filter[status]=` ACTUALLY has by the time it reaches here:
    // Laravel's default middleware has already rewritten '' to null.
    expect(ListQuery::filters(
        filterRequest(['filter' => ['status' => null]]),
        [publishedStatus(default: 'draft')],
    ))->toBe(['status' => '']);
});

it('lets an explicit empty value clear a multiple filter default too', function () {
    expect(ListQuery::filters(
        filterRequest(['filter' => ['tags' => '']]),
        [publishedTags(default: ['a'])],
    ))->toBe(['tags' => []]);
});

it('does not let one named filter suppress another filter default', function () {
    expect(ListQuery::filters(
        filterRequest(['filter' => ['status' => 'published']]),
        [publishedStatus(default: 'draft'), publishedFeatured(default: '1')],
    ))->toBe(['status' => 'published', 'featured' => '1']);
});

it('aborts 422 with the matrix message', function (array $query, string $message) {
    expect(fn () => ListQuery::filters(
        filterRequest($query),
        [publishedStatus(), publishedTags(), publishedFeatured()],
    ))->toThrow(HttpException::class, $message);
})->with([
    'filter is not an array' => [['filter' => 'draft'], '[filter] must be an array.'],
    'unpublished name' => [['filter' => ['nope' => 'x']], 'Unknown filter [nope].'],
    'list for a single filter' => [['filter' => ['status' => ['draft']]], 'Filter [status] takes a single value.'],
    'scalar for a multiple filter' => [['filter' => ['tags' => 'a']], 'Filter [tags] takes a list of values.'],
    'unknown value' => [['filter' => ['status' => 'archived']], 'Unknown value for filter [status].'],
    'unknown value in a list' => [['filter' => ['tags' => ['a', 'z']]], 'Unknown value for filter [tags].'],
    'unknown ternary value' => [['filter' => ['featured' => 'yes']], 'Unknown value for filter [featured].'],
    'non-string scalar' => [['filter' => ['status' => 1]], 'Unknown value for filter [status].'],
    'non-string scalar in a list' => [['filter' => ['tags' => [1]]], 'Unknown value for filter [tags].'],
    'nested array in a list' => [['filter' => ['tags' => [['a']]]], 'Unknown value for filter [tags].'],
]);

it('aborts with status 422, not 500', function () {
    try {
        ListQuery::filters(filterRequest(['filter' => ['nope' => 'x']]), [publishedStatus()]);
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(422);

        return;
    }

    throw new RuntimeException('No HttpException was thrown.');
});
