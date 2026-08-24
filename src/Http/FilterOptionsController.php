<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Http;

use Gait\FilamentMobile\Introspection\FilterDeclaration;
use Gait\FilamentMobile\Introspection\PublishedFilter;
use Gait\FilamentMobile\ResourceRegistry;
use Gait\MobileCore\Authorizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/** Serves options for a table select filter that was too large or dynamic. */
final class FilterOptionsController
{
    public function __construct(private readonly ResourceRegistry $registry)
    {
    }

    public function __invoke(Request $request, string $resource): JsonResponse
    {
        [$class, $mobile] = $this->registry->findByKey($resource)
            ?? abort(404, "No mobile resource [{$resource}].");

        abort_unless(Authorizer::allows($request->user(), 'viewAny', $class::getModel()), 403);

        $validated = $request->validate([
            'filter' => ['required', 'string'],
            'q' => ['nullable', 'string'],
        ]);

        $raw = FilterDeclaration::tableFilters($class, $request);
        $published = FilterDeclaration::classify($raw, $class, $request, $mobile->getFilters())[0];
        $declaration = collect($published)->first(
            static fn (PublishedFilter $candidate): bool => $candidate->name === $validated['filter']
                && $candidate->optionsUrl !== null,
        );

        // One answer for hidden, unknown, narrowed-away and inline filters.
        abort_if($declaration === null, 422, "[{$validated['filter']}] is not a searchable filter.");

        $filter = $raw[$declaration->name] ?? null;
        abort_if(! is_object($filter), 422, "[{$validated['filter']}] is not a searchable filter.");

        $query = (string) ($validated['q'] ?? '');
        $limit = $this->limitOf($filter);

        if ($declaration->options !== []) {
            $matches = array_values(array_filter(
                $declaration->options,
                static fn (array $option): bool => $query === ''
                    || mb_stripos($option['label'], $query) !== false,
            ));

            return response()->json([
                'options' => array_slice($matches, 0, $limit),
                'hasMore' => count($matches) > $limit,
            ]);
        }

        $results = FilterDeclaration::withRequest($request, function () use ($class, $filter, $query): array {
            try {
                $field = $filter->getFormField();
                // A table filter's generated form Select is normally mounted
                // inside Filament's filter form, which supplies its model.
                // Headlessly it has no container, so set the same resource
                // model directly before relationship search asks for it.
                $field->model($class::getModel());

                return $this->flat($field->getSearchResults($query));
            } catch (Throwable) {
                return [];
            }
        });

        return response()->json([
            'options' => array_slice($results, 0, $limit),
            'hasMore' => count($results) >= $limit,
        ]);
    }

    private function limitOf(object $filter): int
    {
        try {
            return max(1, (int) $filter->getOptionsLimit());
        } catch (Throwable) {
            return 50;
        }
    }

    /** @param array<mixed, mixed> $options
     *  @return list<array{value: string, label: string}>
     */
    private function flat(array $options): array
    {
        $flat = [];
        foreach ($options as $value => $label) {
            if (is_array($label)) {
                $flat = [...$flat, ...$this->flat($label)];
                continue;
            }
            // Filter values travel in a query string and ListQuery validates
            // strings. Normalising here also makes a selected relationship id
            // compare equal to the provider's seeded/default filter state.
            $flat[] = ['value' => (string) $value, 'label' => (string) $label];
        }

        return $flat;
    }
}
