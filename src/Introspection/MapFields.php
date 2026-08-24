<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Introspection;

use Throwable;

/** Optional map-plugin classification without a runtime plugin dependency. */
final class MapFields
{
    public const string FIELD = 'Dotswan\\MapPicker\\Fields\\Map';

    public const string ENTRY = 'Dotswan\\MapPicker\\Infolists\\MapEntry';

    public static function isMap(object $component): bool
    {
        return in_array($component::class, [self::FIELD, self::ENTRY], true);
    }

    /**
     * Every map point path in the wired component tree.
     *
     * @param  iterable<mixed>  $components
     * @return list<string>
     */
    public static function pathsIn(iterable $components): array
    {
        $paths = [];

        foreach ($components as $component) {
            if (! is_object($component)) {
                continue;
            }

            if (self::isMap($component) && method_exists($component, 'getName')) {
                try {
                    $name = $component->getName();
                } catch (Throwable) {
                    $name = null;
                }

                if (is_string($name) && $name !== '') {
                    $paths[] = $name;
                }
            }

            $paths = [...$paths, ...self::pathsIn(ChildComponents::of($component))];
        }

        return array_values(array_unique($paths));
    }
}
