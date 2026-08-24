<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Resources;

use Gait\FilamentMobile\MobileResource;

/** Realtime doctor fixture whose Post model intentionally lacks the trait. */
final class BroadcastPostResource extends PostResource
{
    public static function mobile(): MobileResource
    {
        return MobileResource::make()->broadcasts();
    }
}
