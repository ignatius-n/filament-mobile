<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Introspection;

use Closure;
use Gait\MobileCore\MobileResource;
use Illuminate\Container\Container;

/**
 * Request-scoped memo for RelationDiscovery's expensive headless table walk.
 *
 * The service provider registers this class with Container::scoped(), so a
 * normal PHP request gets one instance and Laravel's long-running workers
 * discard it between requests or jobs. Keeping this state out of a static is
 * load-bearing: relation labels and table declarations may evaluate request,
 * locale, tenant, or authenticated-user state.
 *
 * ResourceRegistry::mobileResources() builds fresh MobileResource objects on
 * every call. Object identity would therefore miss the exact duplicate this
 * memo exists to remove (PanelSchemaBuilder followed by DoctorCommand's
 * refusal pass). The fingerprint contains every relation declaration that
 * influences discovery and the fully configured cards, all of which are
 * scalar arrays by this point. Unrelated resource declarations deliberately
 * do not split the cache.
 */
final class RelationDiscoveryMemo
{
    /**
     * @var array<string, array{published: list<array<string, mixed>>, refused: array<string, string>}>
     */
    private array $entries = [];

    /**
     * @param  Closure(): array{published: list<array<string, mixed>>, refused: array<string, string>}  $resolve
     * @return array{published: list<array<string, mixed>>, refused: array<string, string>}
     */
    public function remember(string $resourceClass, ?MobileResource $mobile, Closure $resolve): array
    {
        $key = $resourceClass . "\0" . $this->fingerprint($mobile);

        if (! array_key_exists($key, $this->entries)) {
            $this->entries[$key] = $resolve();
        }

        return $this->entries[$key];
    }

    /**
     * Resolve the current Laravel scope, degrading to an isolated memo when
     * the package is used before its service provider has registered.
     */
    public static function current(): self
    {
        $container = Container::getInstance();

        return $container->bound(self::class)
            ? $container->make(self::class)
            : new self();
    }

    private function fingerprint(?MobileResource $mobile): string
    {
        if ($mobile === null) {
            return 'none';
        }

        $cards = [];

        foreach ($mobile->getRelationCardKeys() as $key) {
            $cards[$key] = $mobile->getRelationCard($key)?->toArray();
        }

        // serialize(), not json_encode(): a malformed host string must not
        // turn a performance optimisation into a schema/doctor exception.
        // These values contain arrays, strings, and null only; no executable
        // object or closure state enters the key.
        return hash('sha256', serialize([
            'cards' => $cards,
            'searchable' => $mobile->getRelationSearchableKeys(),
            'sorts' => $mobile->getRelationSortsKeys(),
            'defaultSorts' => $mobile->getRelationDefaultSortKeys(),
        ]));
    }
}
