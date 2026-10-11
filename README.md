# Laravel Vue Modular Template

Generic Laravel + Vue/Inertia starter template organized as a modular monolith with pragmatic Clean Architecture, DDD, and lightweight CQRS.

The implemented application modules are:

- `app/Modules/Teams`: domain enums, commands/queries, Eloquent persistence, factories, migrations, HTTP/Inertia adapters, policies and routes.
- `app/Modules/Profile`: profile update and deletion commands with Eloquent adapters and settings HTTP adapters.
- `app/Modules/Security`: password-update command with its Eloquent adapter and settings HTTP adapter.

Laravel/Fortify, Inertia, and the global `User` model remain narrow framework compatibility adapters. Application contracts expose immutable data or scalar/domain types rather than Eloquent or HTTP objects. See [ARCHITECTURE.md](ARCHITECTURE.md) for dependency rules, transaction boundaries, verified architecture tests, and documented exceptions.

## Local verification

```bash
composer test
composer coverage
composer architecture
npm run check
npm run build
```

The continuous-integration script runs the architecture suite explicitly before the complete PHP suite:

```bash
composer ci:check
```

`npm run types:check` is also part of CI. Address generated Wayfinder type errors in the generating source/tooling rather than editing generated files.
