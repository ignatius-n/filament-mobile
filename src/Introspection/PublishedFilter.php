<?php

declare(strict_types=1);

namespace Gait\FilamentMobile\Introspection;

/**
 * One table filter the mobile client can render and submit — the same
 * schema-node shape `SchemaWalker` already publishes for form selects,
 * reused rather than invented. A `TernaryFilter`/`TrashedFilter` is shaped
 * as a select whose options are always the fixed `1`/`0` pair, so one class
 * covers both kinds: nothing after construction differs by `kind` except
 * which keys `toNode()` includes.
 */
final class PublishedFilter
{
    /** @param list<array{value: string, label: string}> $options */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $kind, // 'select' | 'ternary'
        public readonly array $options,
        public readonly bool $multiple,
        public readonly ?string $placeholder,
        public readonly string|array|null $default,
        public readonly ?string $optionsUrl = null,
    ) {
    }

    /** The contract node — a schema component in the existing vocabulary. */
    public function toNode(): array
    {
        $config = $this->optionsUrl === null
            ? ['options' => $this->options]
            : ['optionsUrl' => $this->optionsUrl, 'searchable' => true];

        if ($this->multiple) {
            $config['multiple'] = true;
        }

        if ($this->placeholder !== null) {
            $config['placeholder'] = $this->placeholder;
        }

        $node = [
            'type' => 'select',
            'name' => $this->name,
            'label' => $this->label,
            'config' => $config,
        ];

        if ($this->default !== null) {
            $node['default'] = $this->default;
        }

        return $node;
    }

    /**
     * The same declaration as a plain array, for `ListQuery::filters()`.
     * mobile-core must not depend on filament-mobile, so the validator takes
     * arrays and this is the one place that converts.
     *
     * @return array{name: string, kind: string, multiple: bool, options: list<string>, default: mixed, remote?: bool}
     */
    public function toValidatorArray(): array
    {
        $declaration = [
            'name' => $this->name,
            'kind' => $this->kind,
            'multiple' => $this->multiple,
            'options' => array_map(static fn (array $option): string => $option['value'], $this->options),
            'default' => $this->default,
        ];

        if ($this->optionsUrl !== null) {
            $declaration['remote'] = true;
        }

        return $declaration;
    }
}
