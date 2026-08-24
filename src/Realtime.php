<?php

declare(strict_types=1);

namespace Gait\FilamentMobile;

use Gait\MobileCore\Authorizer;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

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
