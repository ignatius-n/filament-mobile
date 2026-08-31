# Contributing

Thanks for helping! Two minutes of context before you start.

## How this repository works

Development happens in a private monorepo that holds this Laravel package,
the Flutter client, and their shared contract goldens. This repository is
the monorepo's public snapshot — the exact tree Packagist serves — and CI
runs here on every push and pull request.

Pull requests are welcome. An accepted change is ported into the monorepo
with credit in the changelog and ships in the next release, so your PR may
be closed as "merged" without a merge commit — that is the normal flow, not
a rejection.

## What to work on

See **Status & roadmap** at the end of the README for the prioritized open
items, and the "deliberately out of scope" list of things not to propose.
For anything sizable, open an issue first so the approach is agreed before
you build it.

## Running the suite

```bash
composer update --prefer-dist
vendor/bin/pest
```

CI additionally runs the suite against **both Filament majors**
(`composer update --with="filament/filament:^4.0"` and `^5.0`) and once
against **MySQL** — SQLite and MySQL disagree about `LIKE ... ESCAPE` and
JSON serialization, so one driver is not enough. A change must be green on
all of them.

## Ground rules

- **Fail closed, loudly.** A component or declaration this package cannot
  publish faithfully is refused and named by `filament-mobile:doctor` —
  never guessed at, never silently dropped. New features follow the same
  pattern.
- **`contract/*.json` are shared goldens** read by both this suite and the
  Flutter client's. Several carry deliberate deviations documented in the
  test that owns them — read the test's docblock before "fixing" a golden.
- **Mobile must not be stricter than the panel it mirrors.** When Filament's
  web panel does not enforce something server-side, neither do we.
- PHP floor is `^8.4`; features newer than the floor are parse errors in CI.
