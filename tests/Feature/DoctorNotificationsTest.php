<?php

declare(strict_types=1);

use Gait\FilamentMobile\Tests\Fixtures\Models\Company;
use Illuminate\Support\Facades\Schema;

it('reports nothing while the bell is off', function () {
    // Banner intentionally carries unrelated doctor fixtures, so this run
    // remains non-zero; only the notifications section must be absent.
    $this->artisan('filament-mobile:doctor')
        ->doesntExpectOutputToContain('Notifications')
        ->assertExitCode(1);
});

it('stays quiet for a healthy bell', function () {
    config()->set('filament-mobile.notifications', true);

    $this->artisan('filament-mobile:doctor')
        ->doesntExpectOutputToContain('Notifications')
        ->assertExitCode(1);
});

it('reports a non-Notifiable user model', function () {
    config()->set('filament-mobile.notifications', true);
    config()->set('auth.providers.users.model', Company::class);

    $this->artisan('filament-mobile:doctor')
        ->expectsOutputToContain('Company must use Notifiable')
        ->assertExitCode(1);
});

it('reports a missing notifications table', function () {
    config()->set('filament-mobile.notifications', true);
    Schema::drop('notifications');

    $this->artisan('filament-mobile:doctor')
        ->expectsOutputToContain('the `notifications` table is missing')
        ->assertExitCode(1);
});

it('reports an unregistrable channel rule when realtime is on', function () {
    config()->set('filament-mobile.notifications', true);
    enableTestRealtime();
    config()->set('auth.providers.users.model', 'App\\Models\\Missing');

    $this->artisan('filament-mobile:doctor')
        ->expectsOutputToContain('channel rule could not be registered')
        ->assertExitCode(1);
});
