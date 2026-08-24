<?php

declare(strict_types=1);

it('omits polling unless the host opts in', function () {
    config()->set('filament-mobile.poll', false);

    expect(schemaDocument()['panel'])->not->toHaveKey('poll');
});

it('publishes the conservative defaults for a true opt in', function () {
    config()->set('filament-mobile.poll', true);

    expect(schemaDocument()['panel']['poll'])->toBe([
        'lists' => 15,
        'detail' => 15,
        'dashboard' => 5,
    ]);
});

it('publishes explicit positive polling intervals', function () {
    config()->set('filament-mobile.poll', [
        'lists' => 30,
        'detail' => 20,
        'dashboard' => 10,
    ]);

    expect(schemaDocument()['panel']['poll'])->toBe([
        'lists' => 30,
        'detail' => 20,
        'dashboard' => 10,
    ]);
});

it('fails closed for malformed polling intervals', function (mixed $value) {
    config()->set('filament-mobile.poll', ['lists' => $value]);

    expect(schemaDocument()['panel'])->not->toHaveKey('poll');
})->with([0, -1, 1.5, '15', 3601]);
