<?php

declare(strict_types=1);

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Gait\FilamentMobile\Tests\Fixtures\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The fixture user model deliberately without Notifiable, for the guard that
 * keeps a misconfigured host on empty pages instead of 500s. Same `users`
 * table — the trait, not the storage, is what the guard checks.
 */
final class NonNotifiableUser extends Illuminate\Foundation\Auth\User
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}

/**
 * Sent through Filament's own API, so the stored payload — format stamp,
 * action shape, status — is exactly what the web bell writes, never a
 * hand-rolled imitation of it.
 */
function sendBellNotification(User $user, string $title = 'Order shipped'): void
{
    // Filament's DatabaseNotification is ShouldQueue, and Testbench's
    // default queue driver is `database` — a table this fixture app never
    // migrates. Sync delivery is what a request-path assertion needs anyway.
    config()->set('queue.default', 'sync');

    Notification::make()
        ->title($title)
        ->success()
        ->actions([
            Action::make('view')->label('View')->url('https://example.test/orders/1'),
            // A Livewire event-dispatching action: no url, so the row's
            // published actions must drop it.
            Action::make('later')->label('Later'),
        ])
        ->sendToDatabase($user);
}

it('serves the feed in the standard envelope with a top-level unread count', function () {
    $user = makeUser('admin');
    sendBellNotification($user);

    $response = $this->actingAs($user)->getJson('/api/mobile-panel/notifications')->assertOk();
    $row = $response->json('data.0');

    expect($response->json('meta'))->toBe([
        'current_page' => 1,
        'last_page' => 1,
        'per_page' => 20,
        'total' => 1,
    ])
        ->and($response->json('unread'))->toBe(1)
        ->and($row['id'])->toBeString()
        ->and($row['title'])->toBe('Order shipped')
        ->and($row['body'])->toBeNull()
        ->and($row['status'])->toBe('success')
        ->and($row['color'])->toBeNull()
        ->and($row['date'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
        ->and($row['readAt'])->toBeNull()
        // Only the url-carrying action survives, label + url only.
        ->and($row['actions'])->toBe([['label' => 'View', 'url' => 'https://example.test/orders/1']])
        ->and($row)->not->toHaveKeys(['icon', 'iconColor', 'view', 'viewData']);
});

it('paginates newest first with the configured per_page', function () {
    $user = makeUser('admin');
    sendBellNotification($user, 'Older');
    $user->notifications()->update(['created_at' => now()->subDay()]);

    for ($i = 1; $i <= 20; $i++) {
        sendBellNotification($user, "Newer {$i}");
    }

    $first = $this->actingAs($user)->getJson('/api/mobile-panel/notifications')->assertOk();
    $second = $this->actingAs($user)->getJson('/api/mobile-panel/notifications?page=2')->assertOk();

    expect($first->json('meta'))->toBe([
        'current_page' => 1,
        'last_page' => 2,
        'per_page' => 20,
        'total' => 21,
    ])
        ->and(array_column($second->json('data'), 'title'))->toBe(['Older'])
        ->and($second->json('unread'))->toBe(21);
});

it('makes the feed conditionally cacheable', function () {
    $user = makeUser('admin');
    sendBellNotification($user);

    $first = $this->actingAs($user)->getJson('/api/mobile-panel/notifications')->assertOk();
    $etag = $first->headers->get('ETag');

    expect($etag)->toBeString()->not->toBeEmpty();

    $second = $this->actingAs($user)
        ->withHeaders(['If-None-Match' => $etag])
        ->getJson('/api/mobile-panel/notifications');

    expect($second->getStatusCode())->toBe(304)
        ->and($second->getContent())->toBe('')
        ->and($second->headers->get('ETag'))->toBe($etag);
});

it('never lists or mutates another user\'s notifications', function () {
    $owner = makeUser('owner');
    $other = makeUser('other');
    sendBellNotification($owner);
    $id = $owner->notifications()->firstOrFail()->getKey();

    $this->actingAs($other)->getJson('/api/mobile-panel/notifications')
        ->assertOk()
        ->assertJsonPath('data', [])
        ->assertJsonPath('unread', 0);
    $this->actingAs($other)->postJson("/api/mobile-panel/notifications/{$id}/read")->assertNotFound();
    $this->actingAs($other)->deleteJson("/api/mobile-panel/notifications/{$id}")->assertNotFound();

    // The foreign id had zero effect: the owner's row is intact and unread.
    expect($owner->notifications()->whereNull('read_at')->count())->toBe(1);
});

it('hides rows Filament did not write, exactly like the web bell', function () {
    $user = makeUser('admin');
    DB::table('notifications')->insert([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\Plain',
        'notifiable_type' => $user::class,
        'notifiable_id' => $user->id,
        'data' => json_encode(['message' => 'a plain Laravel notification']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($user)->getJson('/api/mobile-panel/notifications')
        ->assertOk()
        ->assertJsonPath('meta.total', 0)
        ->assertJsonPath('unread', 0);
});

it('marks one row read and answers the remaining unread count', function () {
    $user = makeUser('admin');
    sendBellNotification($user, 'First');
    sendBellNotification($user, 'Second');
    $id = $user->notifications()->firstOrFail()->getKey();

    $this->actingAs($user)->postJson("/api/mobile-panel/notifications/{$id}/read")
        ->assertOk()
        ->assertExactJson(['unread' => 1]);

    expect($user->notifications()->find($id)->read_at)->not->toBeNull();
});

it('marks everything read', function () {
    $user = makeUser('admin');
    sendBellNotification($user, 'First');
    sendBellNotification($user, 'Second');

    $this->actingAs($user)->postJson('/api/mobile-panel/notifications/read-all')
        ->assertOk()
        ->assertExactJson(['unread' => 0]);

    expect($user->notifications()->whereNull('read_at')->count())->toBe(0);
});

it('deletes one row', function () {
    $user = makeUser('admin');
    sendBellNotification($user);
    $id = $user->notifications()->firstOrFail()->getKey();

    $this->actingAs($user)->deleteJson("/api/mobile-panel/notifications/{$id}")->assertNoContent();

    expect($user->notifications()->count())->toBe(0);
});

it('clears read and unread rows alike', function () {
    $user = makeUser('admin');
    sendBellNotification($user, 'Read');
    $user->notifications()->firstOrFail()->markAsRead();
    sendBellNotification($user, 'Unread');

    $this->actingAs($user)->deleteJson('/api/mobile-panel/notifications')->assertNoContent();

    expect($user->notifications()->count())->toBe(0);
});

it('answers 404 for a non-uuid id through the same scoped query', function () {
    $user = makeUser('admin');

    $this->actingAs($user)->postJson('/api/mobile-panel/notifications/not-a-uuid/read')->assertNotFound();
    $this->actingAs($user)->deleteJson('/api/mobile-panel/notifications/not-a-uuid')->assertNotFound();
});

it('answers empty pages, never a 500, when the user model is not Notifiable', function () {
    $user = NonNotifiableUser::create(['name' => 'plain', 'email' => 'plain@example.test']);

    $this->actingAs($user)->getJson('/api/mobile-panel/notifications')
        ->assertOk()
        ->assertJsonPath('data', [])
        ->assertJsonPath('meta.total', 0)
        ->assertJsonPath('unread', 0);
    $this->actingAs($user)->postJson('/api/mobile-panel/notifications/read-all')
        ->assertOk()
        ->assertExactJson(['unread' => 0]);
    $this->actingAs($user)->deleteJson('/api/mobile-panel/notifications')->assertNoContent();
    $this->actingAs($user)->postJson('/api/mobile-panel/notifications/' . Str::uuid() . '/read')->assertNotFound();
});
