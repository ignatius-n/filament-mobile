<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

it('fails when a resource broadcasts but realtime is disabled', function () {
    config()->set('filament-mobile.tests.banner_broadcasts', true);

    $this->artisan('filament-mobile:doctor')
        ->expectsOutputToContain('Realtime')
        ->expectsOutputToContain('filament-mobile.realtime is disabled')
        ->assertExitCode(1);
});

it('fails when valid realtime config has no broadcast auth route', function () {
    enableTestRealtime();

    $this->artisan('filament-mobile:doctor')
        ->expectsOutputToContain('/broadcasting/auth: missing POST')
        ->assertExitCode(1);
});

it('fails closed when a broadcasting resource model lacks the trait', function () {
    enableTestRealtime();
    config()->set('filament-mobile.resources', [
        Gait\FilamentMobile\Tests\Fixtures\Resources\BroadcastPostResource::class,
    ]);

    expect(schemaDocument()['resources'][0])->not->toHaveKey('channel');

    $this->artisan('filament-mobile:doctor')
        ->expectsOutputToContain('Post must use BroadcastsMobileChanges')
        ->assertExitCode(1);
});

it('accepts a broadcast auth route protected by the mobile API guard', function () {
    enableTestRealtime();
    config()->set('filament-mobile.resources', [
        Gait\FilamentMobile\Tests\Fixtures\Resources\BannerResource::class,
    ]);

    Route::post('/broadcasting/auth', fn () => null)->middleware('auth');

    // Banner intentionally carries unrelated action/drift doctor fixtures,
    // so this run remains non-zero; only the realtime section must disappear.
    $this->artisan('filament-mobile:doctor')
        ->doesntExpectOutputToContain('Realtime')
        ->assertExitCode(1);
});
