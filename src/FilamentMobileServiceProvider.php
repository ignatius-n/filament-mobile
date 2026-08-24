<?php

declare(strict_types=1);

namespace Gait\FilamentMobile;

use Gait\FilamentMobile\Introspection\RelationDiscoveryMemo;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Broadcast;

class FilamentMobileServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Back-compat: the DSL classes moved to the gait/laravel-mobile-core package,
        // and the old names must exist EAGERLY, not just on autoload — a parameter
        // type check (host code hints the old FQCN) never triggers autoload, so a
        // lazily-aliased name fails the check against a directly-loaded Core instance.
        foreach ([
            \Gait\FilamentMobile\MobileCard::class => \Gait\MobileCore\MobileCard::class,
            \Gait\FilamentMobile\MobileResource::class => \Gait\MobileCore\MobileResource::class,
            \Gait\FilamentMobile\RelationCard::class => \Gait\MobileCore\RelationCard::class,
        ] as $alias => $core) {
            if (! class_exists($alias, false)) {
                class_alias($core, $alias);
            }
        }

        $this->mergeConfigFrom(__DIR__ . '/../config/filament-mobile.php', 'filament-mobile');

        // One relation-introspection memo per request / queue job. `scoped`,
        // rather than a singleton or static, is what makes this safe under
        // Octane, Swoole, and long-running queue workers: Laravel flushes the
        // instance at the scope boundary, so user/tenant/locale-dependent
        // table declarations cannot leak into the next request.
        $this->app->scoped(RelationDiscoveryMemo::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/filament-mobile.php' => config_path('filament-mobile.php'),
        ], 'filament-mobile-config');

        $this->loadRoutesFrom(__DIR__ . '/Http/routes.php');

        // Registers the channel rule only; the host remains responsible for
        // exposing `/broadcasting/auth` under its API/mobile guard.
        Broadcast::channel(
            'mobile.{resourceKey}',
            fn ($user, string $resourceKey): bool => Realtime::authorizes($user, $resourceKey),
        );

        if ($this->app->runningInConsole()) {
            $this->commands([Console\DoctorCommand::class]);
        }
    }
}
