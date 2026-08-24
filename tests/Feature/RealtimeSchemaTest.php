<?php

declare(strict_types=1);

use Gait\FilamentMobile\Realtime;
use Illuminate\Support\Facades\Broadcast;

it('registers the private resource channel authorization rule', function () {
    expect(Broadcast::connection()->getChannels())
        ->toHaveKey('mobile.{resourceKey}');
});

it('omits realtime and resource channels unless the host opts in', function () {
    expect(schemaDocument()['panel'])->not->toHaveKey('realtime')
        ->and(schemaFor('banners'))->not->toHaveKey('channel');
});

it('publishes public Reverb settings and opted-in resource channels', function () {
    enableTestRealtime();

    $document = schemaDocument();

    expect($document['panel']['realtime'])->toBe([
        'driver' => 'reverb',
        'key' => 'public-app-key',
        'host' => 'ws.example.test',
        'port' => 443,
        'scheme' => 'wss',
        'authEndpoint' => '/broadcasting/auth',
    ])->not->toHaveKey('secret')
        ->and(collect($document['resources'])->firstWhere('key', 'banners')['channel'])
        ->toBe('mobile.banners')
        ->and(collect($document['resources'])->firstWhere('key', 'posts'))
        ->not->toHaveKey('channel');
});

it('fails closed when the active broadcaster is not Reverb', function () {
    enableTestRealtime();
    config()->set('broadcasting.default', 'log');
    config()->set('broadcasting.connections.log', ['driver' => 'log']);

    expect(schemaDocument()['panel'])->not->toHaveKey('realtime');
});

it('does not trust a connection merely named reverb', function () {
    enableTestRealtime();
    config()->set('broadcasting.connections.reverb.driver', 'pusher');

    expect(schemaDocument()['panel'])->not->toHaveKey('realtime');
});

it('fails closed for malformed public settings', function (array $overrides) {
    enableTestRealtime($overrides);

    expect(schemaDocument()['panel'])->not->toHaveKey('realtime');
})->with([
    [['port' => 0]],
    [['port' => '443']],
    [['scheme' => 'https']],
    [['authEndpoint' => 'broadcasting/auth']],
    [['key' => '']],
]);

it('authorizes only opted-in resources through their existing viewAny gate', function () {
    enableTestRealtime();

    expect(Realtime::authorizes(makeUser('admin'), 'banners'))->toBeTrue()
        ->and(Realtime::authorizes(makeUser('admin-2'), 'posts'))->toBeFalse()
        ->and(Realtime::authorizes(makeUser('admin-3'), 'missing'))->toBeFalse();
});
