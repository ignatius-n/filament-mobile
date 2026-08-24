<?php

declare(strict_types=1);

use Filament\Forms\Components\KeyValue;
use Gait\FilamentMobile\PanelSchemaBuilder;
use Gait\FilamentMobile\ResourceRegistry;
use Gait\FilamentMobile\Validation\RuleExtractor;

/**
 * P7 Task 4: `KeyValue` becomes the walker's fourth mapped type since P6
 * closed, and the last field of this slice. Before this, it was unmapped —
 * dropped with an `unsupported component type` warning, and a dropped field
 * gets no validation rule, so a NOT NULL column behind one failed at the
 * database rather than at validation.
 *
 * The trap: the setters are `editableKeys()` / `editableValues()`, but the
 * getters are **`canEditKeys()` / `canEditValues()`**, alongside
 * `isAddable()` / `isDeletable()` — verified in
 * vendor/filament/forms/src/Components/KeyValue.php. Reading the setter name
 * through the walker's guarded reader returns null, which read() converts to
 * its fallback of `true` — so a locked field would publish as editable, with
 * no error anywhere. `restricted_meta` below is built so each of the four
 * gates disagrees with its neighbour, which is what makes that failure
 * visible: a swapped accessor reads the WRONG gate's value, not merely a
 * degraded one.
 */
function keyvalueNode(string $name): array
{
    return findFormNode(schemaFor('banners'), $name);
}

it('publishes a keyvalue field with its labels, placeholders and default gates', function () {
    $node = keyvalueNode('meta');

    expect($node)->not->toBeNull()
        ->and($node['type'])->toBe('keyvalue')
        ->and($node['config']['keyLabel'])->toBe('Attribute')
        ->and($node['config']['valueLabel'])->toBe('Detail')
        ->and($node['config']['keyPlaceholder'])->toBe('e.g. env')
        ->and($node['config']['valuePlaceholder'])->toBe('e.g. production')
        // Never overridden on this fixture, so these read the vendor
        // defaults — all four gates start open.
        ->and($node['config']['addable'])->toBeTrue()
        ->and($node['config']['deletable'])->toBeTrue()
        ->and($node['config']['editableKeys'])->toBeTrue()
        ->and($node['config']['editableValues'])->toBeTrue();
});

it('never configured reads keyLabel/valueLabel/placeholders as null', function () {
    $config = keyvalueNode('restricted_meta')['config'];

    expect($config['keyPlaceholder'])->toBeNull()
        ->and($config['valuePlaceholder'])->toBeNull();
});

// Four separate tests, one per gate, against a PAIR of fixtures where each
// gate disagrees with its neighbour AND has a real `false` case of its own
// (see `restricted_meta_2`'s doc comment: `restricted_meta` alone cannot
// prove `deletable`/`editableValues` aren't hard-coded `true`, because both
// already read `true` there). A swapped accessor (reading `deletable` for
// `addable`, or `canEditValues` for `canEditKeys`) reds exactly the test
// naming the gate it corrupted, never the others — and so does an accessor
// hard-coded to `true`.
it('publishes addable false when the panel refuses it', function () {
    expect(keyvalueNode('restricted_meta')['config']['addable'])->toBeFalse();
});

it('publishes deletable true alongside a false addable', function () {
    expect(keyvalueNode('restricted_meta')['config']['deletable'])->toBeTrue();
});

it('publishes editableKeys false through canEditKeys() — the getter trap this task exists to catch', function () {
    expect(keyvalueNode('restricted_meta')['config']['editableKeys'])->toBeFalse();
});

it('publishes editableValues true through canEditValues(), alongside a false editableKeys', function () {
    expect(keyvalueNode('restricted_meta')['config']['editableValues'])->toBeTrue();
});

it('publishes addable true alongside a false deletable', function () {
    expect(keyvalueNode('restricted_meta_2')['config']['addable'])->toBeTrue();
});

it('publishes deletable false when the panel refuses it', function () {
    expect(keyvalueNode('restricted_meta_2')['config']['deletable'])->toBeFalse();
});

it('publishes editableKeys true alongside a false editableValues', function () {
    expect(keyvalueNode('restricted_meta_2')['config']['editableKeys'])->toBeTrue();
});

it('publishes editableValues false through canEditValues() — the getter trap this task exists to catch', function () {
    expect(keyvalueNode('restricted_meta_2')['config']['editableValues'])->toBeFalse();
});

it('degrades a throwing addable closure to the vendor default, not a failed document', function () {
    // Every config value goes through the walker's guarded reader: one
    // field's closure throwing costs that field's `addable`, never the
    // whole /schema document. The fallback is `true` — KeyValue::isAddable()'s
    // own default — so this is distinguishable from `restricted_meta`'s
    // explicit `false` above.
    $node = keyvalueNode('exploding_meta');

    expect($node)->not->toBeNull()
        ->and($node['type'])->toBe('keyvalue')
        ->and($node['config']['addable'])->toBeTrue();
});

it('refuses a keyvalue field inside a disabled section', function () {
    // A gate that cannot answer refuses; a disabled ancestor is a gate —
    // the same rule `locked_plan` proves for radio.
    expect(keyvalueNode('locked_meta')['disabled'])->toBeTrue();
});

it('constrains a keyvalue field to an array, and only that', function () {
    // Its keys and values are strings by construction (the design spec) —
    // no `list`, unlike a `tags` field's own array rule, because a
    // KeyValue's wire shape is a MAP, not a list.
    $rules = RuleExtractor::fromComponents([KeyValue::make('meta')]);

    expect($rules)->toHaveKey('meta')
        ->and($rules['meta'])->toBe(['array']);
});

it('persists a submitted map through the unmodified write path', function () {
    // Two pairs, not one: a fixture with a single pair can never show pair
    // 2's edit leaking into pair 1 — the flat-state bug this field's Dart
    // widget test exists to catch. Distinct keys AND distinct values from
    // the seed, so an update that silently kept the old map would be
    // visible too.
    $banner = seedBannerWith(['meta' => ['env' => 'staging']]);

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", [
            'name' => $banner->name,
            'body_html' => '<p>Body</p>',
            'meta' => ['env' => 'production', 'region' => 'eu'],
        ])
        ->assertOk();

    expect($banner->fresh()->meta)->toBe(['env' => 'production', 'region' => 'eu']);
});

it('rejects adding a pair when addable is false', function () {
    $banner = seedBannerWith(['restricted_meta' => ['a' => '1', 'b' => '2']]);

    $response = $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", [
            'name' => $banner->name,
            'body_html' => '<p>Body</p>',
            'restricted_meta' => ['a' => '1', 'b' => '2', 'c' => '3'],
        ])
        ->assertStatus(422);

    expect($response->json('errors.restricted_meta.0'))
        ->toBe('Pairs cannot be added to this field.')
        ->and($banner->fresh()->restricted_meta)->toBe(['a' => '1', 'b' => '2']);
});

it('enforces structural gates against trusted form defaults on create', function () {
    $response = $this->actingAs(makeUser('admin'))
        ->postJson('/api/mobile-panel/banners', [
            'name' => 'Created banner',
            'body_html' => '<p>Body</p>',
            // The trusted create baseline is the fixture's default a/b map,
            // not an empty record. Only c is an addition, and addable(false)
            // must reject it before create() reaches the database.
            'restricted_meta' => ['a' => '1', 'b' => '2', 'c' => '3'],
        ])
        ->assertStatus(422);

    expect($response->json('errors.restricted_meta.0'))
        ->toBe('Pairs cannot be added to this field.')
        ->and(\Gait\FilamentMobile\Tests\Fixtures\Models\Banner::query()
            ->where('name', 'Created banner')->exists())->toBeFalse();
});

it('allows a pure deletion when keys are locked but deletable is true', function () {
    $banner = seedBannerWith(['restricted_meta' => ['a' => '1', 'b' => '2']]);

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", [
            'name' => $banner->name,
            'body_html' => '<p>Body</p>',
            'restricted_meta' => ['b' => '2'],
        ])
        ->assertOk();

    expect($banner->fresh()->restricted_meta)->toBe(['b' => '2']);
});

it('rejects an ambiguous rename when key editing is false', function () {
    $banner = seedBannerWith(['restricted_meta' => ['a' => '1', 'b' => '2']]);

    $response = $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", [
            'name' => $banner->name,
            'body_html' => '<p>Body</p>',
            'restricted_meta' => ['renamed' => '1', 'b' => '2'],
        ])
        ->assertStatus(422);

    expect($response->json('errors.restricted_meta.0'))
        ->toBe('Keys cannot be renamed for this field.')
        ->and($banner->fresh()->restricted_meta)->toBe(['a' => '1', 'b' => '2']);
});

it('rejects deleting a pair when deletable is false', function () {
    $banner = seedBannerWith(['restricted_meta_2' => ['a' => '1', 'b' => '2']]);

    $response = $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", [
            'name' => $banner->name,
            'body_html' => '<p>Body</p>',
            'restricted_meta_2' => ['b' => '2'],
        ])
        ->assertStatus(422);

    expect($response->json('errors.restricted_meta_2.0'))
        ->toBe('Pairs cannot be removed from this field.')
        ->and($banner->fresh()->restricted_meta_2)->toBe(['a' => '1', 'b' => '2']);
});

it('rejects changing an existing value when editable values is false', function () {
    $banner = seedBannerWith(['restricted_meta_2' => ['a' => '1', 'b' => '2']]);

    $response = $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", [
            'name' => $banner->name,
            'body_html' => '<p>Body</p>',
            'restricted_meta_2' => ['a' => 'changed', 'b' => '2'],
        ])
        ->assertStatus(422);

    expect($response->json('errors.restricted_meta_2.0'))
        ->toBe('Existing values cannot be changed for this field.')
        ->and($banner->fresh()->restricted_meta_2)->toBe(['a' => '1', 'b' => '2']);
});

it('allows adding a new pair with its initial value while existing values are locked', function () {
    $banner = seedBannerWith(['restricted_meta_2' => ['a' => '1', 'b' => '2']]);

    $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", [
            'name' => $banner->name,
            'body_html' => '<p>Body</p>',
            'restricted_meta_2' => ['a' => '1', 'b' => '2', 'c' => '3'],
        ])
        ->assertOk();

    expect($banner->fresh()->restricted_meta_2)->toBe(['a' => '1', 'b' => '2', 'c' => '3']);
});

it('fails closed when a keyvalue permission closure throws', function () {
    $banner = seedBannerWith(['exploding_meta' => []]);

    $response = $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", [
            'name' => $banner->name,
            'body_html' => '<p>Body</p>',
            'exploding_meta' => ['a' => '1'],
        ])
        ->assertStatus(422);

    expect($response->json('errors.exploding_meta.0'))
        ->toBe('The key/value permissions could not be evaluated. Refresh and try again.')
        ->and($banner->fresh()->exploding_meta)->toBe([]);
});

it('publishes an empty keyvalue default as a JSON object, not a JSON list', function () {
    // Fix round 1, Finding 4. `locked_meta` and `exploding_meta` both never
    // call ->default() — so KeyValue::setUp()'s own `$this->default([])`
    // (vendor) is what `getDefaultState()` answers, and PHP's `[]` is
    // ambiguous between an empty list and an empty map. The contract's
    // declared shape for `keyvalue` is `Map<String, String>` in every case,
    // so this must round-trip as `{}` on the wire, not `[]`.
    //
    // json_decode() collapses `{}` and `[]` back to the identical PHP `[]`,
    // so an assertion against the decoded `/schema` response (as every other
    // test in this file uses) cannot tell the two apart — this asserts the
    // raw JSON string instead, the same discipline
    // `it never emits _actions` (ContractSnapshotTest.php) already uses.
    $json = json_encode(
        (new PanelSchemaBuilder(new ResourceRegistry()))->build(null),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    );

    expect($json)->toContain('"name":"locked_meta"')
        ->and($json)->toMatch('/"name":"locked_meta".*?"default":\{\}/')
        ->and($json)->toContain('"name":"exploding_meta"')
        ->and($json)->toMatch('/"name":"exploding_meta".*?"default":\{\}/');
});

it('422s on a keyvalue field that is not an array', function () {
    $banner = seedBanner();

    $response = $this->actingAs(makeUser('admin'))
        ->putJson("/api/mobile-panel/banners/{$banner->id}", [
            'name' => $banner->name,
            'body_html' => '<p>Body</p>',
            'meta' => 'not-an-array',
        ])
        ->assertStatus(422);

    expect(array_keys($response->json('errors')))->toContain('meta');
});
