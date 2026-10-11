# Laravel Vue Modular Template

Generic Laravel + Vue/Inertia starter template, being migrated incrementally to a modular monolith with pragmatic Clean Architecture, DDD, and lightweight CQRS.

See [ARCHITECTURE.md](ARCHITECTURE.md) for the current migration boundary, verified rules, commands, compatibility exceptions, and the work still planned.

## Local verification

```bash
composer test
composer coverage
composer architecture
npm run check
npm run build
```

`npm run types:check` is also part of CI. Address generated Wayfinder type errors in the generating source/tooling rather than editing generated files.
