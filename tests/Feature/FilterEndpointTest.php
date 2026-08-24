<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Models\Story;
use Gait\FilamentMobile\Tests\Fixtures\Resources\AdminOnlyFilterResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\DefaultedStoryResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\EscapingFilterResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\NarrowedFilterResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\StoryResource;
use Gait\FilamentMobile\Tests\Fixtures\Resources\TableFiltersResource;

/**
 * P24 Task 3: the filters actually narrowing a list. Every case asserts on
 * the RETURNED RECORD IDS rather than on generated SQL — ids are what a user
 * sees, and a SQL assertion passes while the semantics are wrong.
 *
 * Not the shared TestCase resource list: these two fixtures exist for this
 * slice only, the same reasoning `ReorderListTest`'s beforeEach gives.
 */
beforeEach(function () {
    config()->set('filament-mobile.resources', [
        StoryResource::class,
        DefaultedStoryResource::class,
        EscapingFilterResource::class,
        NarrowedFilterResource::class,
        AdminOnlyFilterResource::class,
    ]);
});

function seedStory(string $title, array $attributes = []): Story
{
    return Story::create(['title' => $title, ...$attributes]);
}

/** The `id` of every record the response carried, in response order. */
function idsIn(array $response): array
{
    return array_column($response['data'], 'id');
}

it('narrows a list to the records a select filter names', function () {
    $one = seedStory('One', ['status' => 'draft']);
    $two = seedStory('Two', ['status' => 'draft']);
    seedStory('Three', ['status' => 'published']);

    $response = test()->actingAs(makeUser('admin'))
        ->getJson('/api/mobile-panel/stories?filter[status]=draft')
        ->assertOk()
        ->json();

    expect(idsIn($response))->toBe([$one->id, $two->id])
        ->and($response['meta']['total'])->toBe(2);
});

it('serves the union of the values a multiple filter names', function () {
    $a = seedStory('A', ['tags' => 'a']);
    $b = seedStory('B', ['tags' => 'b']);
    seedStory('C', ['tags' => null]);

    $response = test()->actingAs(makeUser('admin'))
        ->getJson('/api/mobile-panel/stories?filter[tags][0]=a&filter[tags][1]=b')
        ->assertOk()
        ->json();

    expect(idsIn($response))->toBe([$a->id, $b->id]);
});

it('serves complementary sets for the two ternary values and everything when it is omitted', function () {
    $featured = seedStory('Featured', ['featured' => true]);
    $plain = seedStory('Plain', ['featured' => false]);

    $ids = fn (string $query): array => idsIn(
        test()->actingAs(makeUser('admin'))
            ->getJson("/api/mobile-panel/stories{$query}")
            ->assertOk()
            ->json(),
    );

    expect($ids('?filter[featured]=1'))->toBe([$featured->id])
        ->and($ids('?filter[featured]=0'))->toBe([$plain->id])
        ->and($ids(''))->toBe([$featured->id, $plain->id]);
});

it('hides a soft-deleted record by default and serves it under the trashed filter', function () {
    $live = seedStory('Live');
    $deleted = seedStory('Deleted');
    $deleted->delete();

    $ids = fn (string $query): array => idsIn(
        test()->actingAs(makeUser('admin'))
            ->getJson("/api/mobile-panel/stories{$query}")
            ->assertOk()
            ->json(),
    );

    expect($ids(''))->toBe([$live->id])
        ->and($ids('?filter[trashed]=1'))->toBe([$live->id, $deleted->id])
        ->and($ids('?filter[trashed]=0'))->toBe([$deleted->id]);
});

it('serves a directly addressed soft-deleted record whatever the trashed filter would do', function () {
    $deleted = seedStory('Deleted');
    $deleted->delete();

    // No `filter` parameter at all: the record endpoint is not a filtered
    // list, and `TrashedFilter` excludes itself when resolving a record.
    test()->actingAs(makeUser('admin'))
        ->getJson("/api/mobile-panel/stories/{$deleted->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $deleted->id);
});

it('keeps the filters applied in reorder mode', function () {
    $draft = seedStory('Draft', ['status' => 'draft']);
    seedStory('Published', ['status' => 'published']);

    $response = test()->actingAs(makeUser('admin'))
        ->getJson('/api/mobile-panel/stories?reorder=1&filter[status]=draft')
        ->assertOk()
        ->json();

    expect(idsIn($response))->toBe([$draft->id])
        ->and($response['meta']['total'])->toBe(1);
});

it('applies a published default when the request sends no filter parameter', function () {
    $draft = seedStory('Draft', ['status' => 'draft']);
    seedStory('Published', ['status' => 'published']);

    expect(idsIn(
        test()->actingAs(makeUser('admin'))
            ->getJson('/api/mobile-panel/defaulted-stories')
            ->assertOk()
            ->json(),
    ))->toBe([$draft->id]);
});

it('reads an explicit empty value as "any" rather than falling back to the default', function () {
    $draft = seedStory('Draft', ['status' => 'draft']);
    $published = seedStory('Published', ['status' => 'published']);

    expect(idsIn(
        test()->actingAs(makeUser('admin'))
            ->getJson('/api/mobile-panel/defaulted-stories?filter[status]=')
            ->assertOk()
            ->json(),
    ))->toBe([$draft->id, $published->id]);
});

it('never narrows a directly addressed record by a default filter', function () {
    $published = seedStory('Published', ['status' => 'published']);

    test()->actingAs(makeUser('admin'))
        ->getJson("/api/mobile-panel/defaulted-stories/{$published->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $published->id);
});

it('refuses a bad filter parameter with 422 and names it', function (string $query, string $message) {
    test()->actingAs(makeUser('admin'))
        ->getJson("/api/mobile-panel/stories?{$query}")
        ->assertStatus(422)
        ->assertJsonPath('message', $message);
})->with([
    'not an array' => ['filter=draft', '[filter] must be an array.'],
    'unpublished name' => ['filter[nope]=x', 'Unknown filter [nope].'],
    'list for a single filter' => ['filter[status][0]=draft', 'Filter [status] takes a single value.'],
    'scalar for a multiple filter' => ['filter[tags]=a', 'Filter [tags] takes a list of values.'],
    'unknown value' => ['filter[status]=archived', 'Unknown value for filter [status].'],
    'unknown value in a list' => ['filter[tags][0]=z', 'Unknown value for filter [tags].'],
    'unknown ternary value' => ['filter[featured]=maybe', 'Unknown value for filter [featured].'],
]);

it('refuses a filter mobile()->filters() narrowed away', function () {
    // `NarrowedFilterResource`'s table declares `status` AND `category`, but
    // `mobile()->filters(['status', 'nope'])` publishes only `status` — so
    // `category` exists on the table and is still unknown here.
    test()->actingAs(makeUser('admin'))
        ->getJson('/api/mobile-panel/' . NarrowedFilterResource::getSlug() . '?filter[category]=a')
        ->assertStatus(422)
        ->assertJsonPath('message', 'Unknown filter [category].');
});

it('refuses a filter this requester cannot see, so a hidden filter stays hidden', function () {
    // `AdminOnlyFilterResource`'s `vip` filter is `->visible()` on the admin
    // email alone. For anyone else the schema never published it, and the
    // list endpoint must answer identically to a filter that was never
    // declared — the only leak-shaped case in this slice.
    test()->actingAs(makeUser('other'))
        ->getJson('/api/mobile-panel/' . AdminOnlyFilterResource::getSlug() . '?filter[vip]=1')
        ->assertStatus(422)
        ->assertJsonPath('message', 'Unknown filter [vip].');
});

it('404s a record a bare Filter\'s ->default() hides, exactly as the panel does', function () {
    // The other half of the show() comment: `FilterApplier::dataFor()` reads
    // `getDefaultState()` for a bare `Filter` whatever state is passed, so
    // `or_featured`'s `->default()` narrows record resolution too. "Keep C"
    // (featured = false) served 200 before P24 and 404s now — web's own
    // `resolveTableRecord()` hides it identically. A future reader must not
    // "fix" this into a 200.
    $hidden = seedStory('Keep C', ['status' => 'published', 'featured' => false]);
    $shown = seedStory('Keep A', ['status' => 'published', 'featured' => true]);

    test()->actingAs(makeUser('admin'))
        ->getJson("/api/mobile-panel/escaping-filters/{$hidden->id}")
        ->assertStatus(404);

    test()->actingAs(makeUser('admin'))
        ->getJson("/api/mobile-panel/escaping-filters/{$shown->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $shown->id);
});

it('500s a list whose TrashedFilter runs against a model with no SoftDeletes', function () {
    // Pinned, not fixed. `TrashedFilter::baseQuery()` calls `withTrashed()`
    // on a plain Builder, which is a BadMethodCallException — the web panel
    // throws on the same table, so the 200 this endpoint served before P24
    // was mobile listing rows the panel cannot render at all.
    // `filament-mobile:doctor` names it (`DoctorFiltersTest`).
    //
    // Whoever is tempted to wrap `FilterApplier::apply()` in a try/catch:
    // that fails OPEN. It would serve the list with the filter silently
    // skipped — publishing exactly the rows a broken filter was supposed to
    // hide. Fix the panel, not this.
    config()->set('filament-mobile.resources', [TableFiltersResource::class]);

    test()->actingAs(makeUser('admin'))
        ->getJson('/api/mobile-panel/table-filters')
        ->assertStatus(500);

    // ...and it is `withTrashed()` that does it, not some unrelated 500 this
    // fixture might grow later.
    test()->withoutExceptionHandling();

    expect(fn () => test()->actingAs(makeUser('admin'))->getJson('/api/mobile-panel/table-filters'))
        ->toThrow(BadMethodCallException::class);
});

it('never lets an unpublished filter widen the list past the resource scope', function () {
    // One assertion, three defects: each of the three seeded rows below is
    // excluded by a different half of `FilterApplier::apply()`, and each
    // reappears if that half breaks. See `EscapingFilterResource`.
    $kept = seedStory('Keep A', ['status' => 'published', 'featured' => true]);
    // Reachable only by the `orWhere` escaping the resource's own scope.
    seedStory('Keep B', ['status' => 'draft', 'featured' => true]);
    // Reachable only if the `->default()` bare filter is read as unchecked.
    seedStory('Keep C', ['status' => 'published', 'featured' => false]);
    // Reachable only if the schema'd filter is forced off.
    seedStory('Skip D', ['status' => 'published', 'featured' => true]);

    expect(idsIn(
        test()->actingAs(makeUser('admin'))
            ->getJson('/api/mobile-panel/escaping-filters')
            ->assertOk()
            ->json(),
    ))->toBe([$kept->id]);
});
