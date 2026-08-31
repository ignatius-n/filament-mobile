<?php

declare(strict_types=1);

use Gait\FilamentMobile\PanelSchemaBuilder;
use Gait\FilamentMobile\Tests\Fixtures\Models\User;
use Illuminate\Support\Facades\Broadcast;

it('omits the bell unless the host opts in', function () {
    expect(schemaDocument()['panel'])->not->toHaveKey('notifications');
});

it('publishes the 30-second default for a true opt in', function () {
    config()->set('filament-mobile.notifications', true);

    expect(schemaDocument()['panel']['notifications'])->toBe(['poll' => 30]);
});

it('honours a poll override', function () {
    config()->set('filament-mobile.notifications', ['poll' => 60]);

    expect(schemaDocument()['panel']['notifications'])->toBe(['poll' => 60]);
});

it('fails closed for a malformed poll', function (mixed $value) {
    config()->set('filament-mobile.notifications', ['poll' => $value]);

    expect(schemaDocument()['panel'])->not->toHaveKey('notifications');
})->with([0, -1, 1.5, '30', 3601]);

it('fails closed for a malformed opt in', function () {
    config()->set('filament-mobile.notifications', 'yes');

    expect(schemaDocument()['panel'])->not->toHaveKey('notifications');
});

it('publishes the user channel only when realtime is also configured', function () {
    config()->set('filament-mobile.notifications', true);
    enableTestRealtime();

    $user = makeUser('admin');
    $node = $this->actingAs($user)
        ->getJson('/api/mobile-panel/schema')
        ->assertOk()
        ->json('panel.notifications');

    expect($node)->toBe([
        'poll' => 30,
        'channel' => 'Gait.FilamentMobile.Tests.Fixtures.Models.User.' . $user->id,
    ]);
});

it('omits the channel without realtime', function () {
    config()->set('filament-mobile.notifications', true);

    expect(schemaDocument()['panel']['notifications'])->not->toHaveKey('channel');
});

it('omits the channel for an anonymous build even with realtime configured', function () {
    config()->set('filament-mobile.notifications', true);
    enableTestRealtime();

    expect(app(PanelSchemaBuilder::class)->build(null)['panel']['notifications'])
        ->toBe(['poll' => 30]);
});

it('honours receivesBroadcastNotificationsOn in the published channel', function () {
    config()->set('filament-mobile.notifications', true);
    enableTestRealtime();

    $user = new class extends User
    {
        protected $table = 'users';

        public function receivesBroadcastNotificationsOn(): string
        {
            return 'App.Custom.7';
        }
    };
    $user->forceFill(['id' => 7, 'name' => 'custom']);

    expect(app(PanelSchemaBuilder::class)->build($user)['panel']['notifications']['channel'])
        ->toBe('App.Custom.7');
});

it('registers the private user channel authorization rule at boot', function () {
    $channels = Broadcast::connection()->getChannels();
    $pattern = 'Gait.FilamentMobile.Tests.Fixtures.Models.User.{id}';

    expect($channels)->toHaveKey($pattern);

    $callback = $channels[$pattern];
    $user = makeUser('admin');

    expect($callback($user, (string) $user->id))->toBeTrue()
        ->and($callback($user, (string) ($user->id + 1)))->toBeFalse();
});
