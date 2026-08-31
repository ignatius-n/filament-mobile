<?php

declare(strict_types=1);

return [
    // Route prefix for every mobile endpoint.
    'prefix' => 'api/mobile-panel',

    // Records per page. Deliberately not client-controllable: a `per_page`
    // query parameter would let one request pull an entire table.
    'per_page' => 20,

    // Above this many options, a select publishes an `optionsUrl` instead of
    // inlining. The pilot measured a 55-option list in a development
    // database; the same panel in production inlines the whole table.
    'options_inline_max' => 50,

    // Auth guard for the mobile endpoints. Null uses the application default.
    'guard' => null,

    // Host middleware applied to every mobile endpoint, before the auth guard.
    // Defaults to the application's `api` group so the host's own middleware —
    // locale negotiation above all — runs here like it does on every other
    // route. Without it the panel always serialises in APP_LOCALE, which makes
    // the package unusable in a bilingual app.
    'middleware' => ['api'],

    // Opt-in background revalidation for open Flutter screens. False means
    // no timers and preserves the pre-P20 behavior. Set true for Filament-like
    // defaults, or override individual positive intervals in seconds:
    // ['lists' => 15, 'detail' => 15, 'dashboard' => 5]. The app pauses in
    // the background and while its route is not current; list polling also
    // pauses during drag-to-reorder.
    'poll' => false,

    // Optional Reverb change signals. False publishes no connection or
    // resource channels. True derives the public client settings from the
    // active `broadcasting.connections.reverb` connection; an array may
    // override key, host, port, scheme (ws/wss), and authEndpoint. The active
    // broadcast connection must still be Reverb. Secrets are never published.
    'realtime' => false,

    // Opt-in in-app notification bell, mirroring Filament's own database
    // notifications. False publishes no `panel.notifications` node and the
    // notification endpoints answer empty pages. True polls the badge every
    // 30 seconds (Filament's own bell cadence); an array may override the
    // interval: ['poll' => 60]. A non-integer or out-of-range (1–3600) poll
    // drops the whole node rather than starting a timer the host did not
    // choose. With `realtime` also enabled, the user's private notification
    // channel is published beside the interval.
    'notifications' => false,

    // Explicit resource list. Null (the default) reads the registered Filament
    // panel, so resources are never listed twice. Set an array only where no
    // panel is booted — or to serve a deliberate subset.
    'resources' => null,

    // The panel's declared translation locales, published as `panel.locales`
    // for a client to order per-locale field chips by. Null (the default)
    // publishes nothing unless the official
    // `filament/spatie-laravel-translatable-plugin` is registered on the
    // panel, in which case ITS locales win. Set this only for a panel built
    // on this package's own manual dotted-field convention
    // (`caption.ar`/`caption.en`) with no such plugin — a flat list, e.g.
    // `['ar', 'en']`.
    'locales' => null,

    // Dashboard widgets exposed to mobile, by class name, in publication
    // order. Empty by default and never auto-discovered: a widget runs
    // arbitrary queries, so a panel must name each one it wants on a phone.
    // Only StatsOverviewWidget and ChartWidget subclasses are supported;
    // `filament-mobile:doctor` reports anything else.
    'widgets' => [],

    // Components this package does not ship knowledge of, mapped to a contract
    // type. Entries here win over the built-in map, so a panel can also
    // override one it disagrees with. `filament-mobile:doctor` prints the exact
    // line to paste for anything it finds unmapped.
    //
    //     \Guava\IconPicker\Forms\Components\IconPicker::class => 'select',
    'types' => [],
];
