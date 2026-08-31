<?php

declare(strict_types=1);

namespace Gait\FilamentMobile;

use Gait\MobileCore\Authorizer;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/** Fail-closed Reverb configuration, channel naming, and authorization. */
final class Realtime
{
    /**
     * Public Pusher-protocol settings safe to publish in `/schema`.
     *
     * @return array{driver: 'reverb', key: string, host: string, port: int, scheme: 'ws'|'wss', authEndpoint: string}|null
     */
    public static function configuration(): ?array
    {
        $configured = config('filament-mobile.realtime', false);

        if ($configured === true) {
            $configured = [];
        }

        if (! is_array($configured)) {
            return null;
        }

        $connectionName = config('broadcasting.default');
        $connection = is_string($connectionName)
            ? config("broadcasting.connections.{$connectionName}")
            : null;

        if (! is_array($connection)) {
            return null;
        }

        $connectionDriver = $connection['driver'] ?? null;

        if ($connectionDriver !== 'reverb') {
            return null;
        }

        $options = is_array($connection['options'] ?? null) ? $connection['options'] : [];
        $key = $configured['key'] ?? $connection['key'] ?? null;
        $host = $configured['host'] ?? $options['host'] ?? null;
        $port = $configured['port'] ?? $options['port'] ?? null;
        $scheme = $configured['scheme'] ?? self::socketScheme($options);
        $authEndpoint = $configured['authEndpoint']
            ?? $configured['auth_endpoint']
            ?? '/broadcasting/auth';

        if (
            ! is_string($key) || trim($key) === ''
            || ! is_string($host) || trim($host) === ''
            || ! is_int($port) || $port < 1 || $port > 65535
            || ! in_array($scheme, ['ws', 'wss'], true)
            || ! is_string($authEndpoint)
            || ! str_starts_with($authEndpoint, '/')
            || str_starts_with($authEndpoint, '//')
        ) {
            return null;
        }

        return [
            'driver' => 'reverb',
            'key' => trim($key),
            'host' => trim($host),
            'port' => $port,
            'scheme' => $scheme,
            'authEndpoint' => $authEndpoint,
        ];
    }

    public static function channel(string $resourceKey): string
    {
        return 'mobile.' . $resourceKey;
    }

    /**
     * P21: the user's private database-notification channel — the exact name
     * Filament's DatabaseNotificationsSent broadcasts on (measured in
     * vendor/filament/notifications' DatabaseNotifications component,
     * getBroadcastChannel()): the model's receivesBroadcastNotificationsOn()
     * override when it has one, else the FQCN dotted with the key. Published
     * from here, never reconstructed client-side. A throwing or malformed
     * override degrades to the derived default rather than publishing a name
     * nothing broadcasts on.
     */
    public static function userChannel(Authenticatable $user): string
    {
        if (method_exists($user, 'receivesBroadcastNotificationsOn')) {
            try {
                $channel = $user->receivesBroadcastNotificationsOn();

                if (is_string($channel) && $channel !== '') {
                    return $channel;
                }
            } catch (Throwable) {
                // Fall through to the derived default below.
            }
        }

        // getKey() over getAuthIdentifier(), because getKey() is what the
        // vendor component dots into the name — the two only differ for a
        // model with a custom auth identifier column, and matching the
        // broadcast is the whole point of publishing this.
        $key = method_exists($user, 'getKey') ? $user->getKey() : $user->getAuthIdentifier();

        return str_replace('\\', '.', $user::class) . '.' . $key;
    }

    /**
     * The Broadcast::channel() pattern matching userChannel()'s derived
     * default, resolved from the mobile guard's auth provider config. Null
     * when that config does not name a user model class — the service
     * provider then skips registering the rule and doctor reports it. A
     * model overriding receivesBroadcastNotificationsOn() is honoured in the
     * PUBLISHED name, but its channel rule is the host's to register: no
     * boot-time derivation can know an instance method's answer.
     */
    public static function userChannelPattern(): ?string
    {
        $guard = config('filament-mobile.guard') ?? config('auth.defaults.guard');
        $provider = is_string($guard) ? config("auth.guards.{$guard}.provider") : null;
        $model = is_string($provider) ? config("auth.providers.{$provider}.model") : null;

        if (! is_string($model) || ! class_exists($model)) {
            return null;
        }

        return str_replace('\\', '.', $model) . '.{id}';
    }

    /**
     * The one broadcast-enabled mobile resource owning this model. Ambiguity
     * fails closed because one event cannot truthfully carry two resource keys.
     *
     * @return array{key: string, channel: string}|null
     */
    public static function forModel(Model $model, ?ResourceRegistry $registry = null): ?array
    {
        if (
            self::configuration() === null
            || ! in_array(BroadcastsMobileChanges::class, class_uses_recursive($model), true)
        ) {
            return null;
        }

        $registry ??= app(ResourceRegistry::class);
        $matches = [];

        foreach ($registry->mobileResources() as $class => $mobile) {
            if ($mobile->getBroadcasts() && $class::getModel() === $model::class) {
                $key = $registry->keyFor($class);
                $matches[] = ['key' => $key, 'channel' => self::channel($key)];
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    public static function authorizes(
        ?Authenticatable $user,
        string $resourceKey,
        ?ResourceRegistry $registry = null,
    ): bool {
        if (self::configuration() === null) {
            return false;
        }

        $registry ??= app(ResourceRegistry::class);
        $found = $registry->findByKey($resourceKey);

        if ($found === null) {
            return false;
        }

        [$class] = $found;
        $model = $class::getModel();
        $resource = self::forModel(new $model(), $registry);

        return $resource !== null
            && $resource['key'] === $resourceKey
            && Authorizer::allows($user, 'viewAny', $class::getModel());
    }

    /** @param array<string, mixed> $options */
    private static function socketScheme(array $options): ?string
    {
        $scheme = $options['scheme'] ?? null;

        if ($scheme === 'https') {
            return 'wss';
        }

        if ($scheme === 'http') {
            return 'ws';
        }

        if (in_array($scheme, ['ws', 'wss'], true)) {
            return $scheme;
        }

        return ($options['useTLS'] ?? false) === true ? 'wss' : null;
    }
}
