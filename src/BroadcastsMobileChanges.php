<?php

declare(strict_types=1);

namespace Gait\FilamentMobile;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Database\Eloquent\BroadcastsEvents;

/**
 * Broadcasts minimal invalidation signals for a model served by exactly one
 * `mobile()->broadcasts()` resource.
 *
 * The event never serializes model attributes. A client must revalidate via
 * the existing authorized HTTP endpoint before anything reaches the screen.
 */
trait BroadcastsMobileChanges
{
    use BroadcastsEvents;

    /** @return list<PrivateChannel> */
    public function broadcastOn($event): array
    {
        $resource = Realtime::forModel($this);

        return $resource === null
            ? []
            : [new PrivateChannel($resource['channel'])];
    }

    /** @return array{key: string, id: int|string|null, event: string} */
    public function broadcastWith($event): array
    {
        $resource = Realtime::forModel($this);

        return [
            'key' => $resource['key'] ?? '',
            'id' => $this->getRouteKey(),
            'event' => match ($event) {
                'trashed' => 'deleted',
                'restored' => 'created',
                default => (string) $event,
            },
        ];
    }

    public function broadcastAs($event): string
    {
        return 'filament-mobile.changed';
    }

    public function broadcastAfterCommit(): bool
    {
        return true;
    }

    /**
     * Laravel's model broadcaster already carries InteractsWithSockets. Set
     * its socket from X-Socket-ID before dispatch so the originating app does
     * not receive its own write and immediately refetch it.
     */
    protected function newBroadcastableEvent(string $event): MobileModelChanged
    {
        $broadcast = new MobileModelChanged($this, $event);
        $broadcast->dontBroadcastToCurrentUser();

        return $broadcast;
    }
}
