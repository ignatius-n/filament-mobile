<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Models\Banner;

beforeEach(function () {
    enableTestRealtime();
});

it('builds a private minimal invalidation event after commit', function () {
    $banner = new Banner(['name' => 'Private']);
    $banner->id = 42;

    $event = $banner->newBroadcastableModelEvent('updated');
    $channels = $event->broadcastOn();

    expect($channels)->toHaveCount(1)
        ->and($channels[0]->name)->toBe('private-mobile.banners')
        ->and($event->broadcastAs())->toBe('filament-mobile.changed')
        ->and($event->broadcastWith())->toBe([
            'key' => 'banners',
            'id' => 42,
            'event' => 'updated',
        ])
        ->and($event->afterCommit)->toBeTrue()
        ->and($event->shouldBroadcastNow())->toBeFalse();
});

it('maps soft-delete lifecycle names to list semantics', function (string $source, string $expected) {
    $banner = new Banner();
    $banner->id = 7;

    expect($banner->newBroadcastableModelEvent($source)->broadcastWith()['event'])
        ->toBe($expected);
})->with([
    ['trashed', 'deleted'],
    ['restored', 'created'],
]);

it('carries X-Socket-ID so Laravel excludes the originating connection', function () {
    request()->headers->set('X-Socket-ID', '123.456');
    $banner = new Banner();
    $banner->id = 7;

    expect($banner->newBroadcastableModelEvent('updated')->socket)->toBe('123.456');
});
