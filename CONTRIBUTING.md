# Contributing

## Scope

This repository is a Laravel package, so proposed changes should keep package boundaries clean and avoid assumptions about a specific host app.

## Development

1. Install dependencies with `composer update`.
2. Run the test suite with `vendor/bin/phpunit`.
3. Update docs when behavior, configuration, routes, or commands change.

## Documentation Requirement

Documentation is part of the change. When a public contract, config key, command, route, service workflow, or recommended integration pattern changes, update both:

- `README.md` when the change affects installation, architecture, high-value usage, or upgrade guidance
- `docs-site` when the change needs detailed implementation or operational guidance

Keep package docs app-agnostic. If an example depends on a host app, mark the package-owned boundary and the host-app-owned boundary clearly.

## Pull Requests

Please keep pull requests focused and include:

- a short description of the problem and the change
- test coverage for behavior changes when practical
- documentation updates for public-facing changes

## Compatibility

Changes should preserve the package's published compatibility targets unless the pull request explicitly updates them.
