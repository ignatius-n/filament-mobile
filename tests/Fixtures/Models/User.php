<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Tests\Fixtures\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    // P21: the notifications() relation the bell endpoints query, same as a
    // real host's user model. NotificationsTest's non-Notifiable guard uses
    // its own traitless model instead of this one.
    use Notifiable;

    protected $guarded = [];

    public $timestamps = false;
}
