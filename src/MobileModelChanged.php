<?php

declare(strict_types=1);

namespace Gait\FilamentMobile;

use Illuminate\Database\Eloquent\BroadcastableModelEventOccurred;

/**
 * Keeps hard deletes on the queue so `afterCommit` is true for every model
 * lifecycle. Laravel's base event broadcasts hard deletes synchronously.
 */
final class MobileModelChanged extends BroadcastableModelEventOccurred
{
    public function shouldBroadcastNow(): bool
    {
        return false;
    }
}
