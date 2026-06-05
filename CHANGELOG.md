# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
While the project is in the `0.x` series the public API is not considered stable:
behaviour may change in any minor release.

## [Unreleased]

## [0.2.0] - 2026-06-05

### Added

- `DateFilter` operators now survive request parsing. `JsonApiUrlParser` accepts
  `after`, `before`, `strictly_after`, and `strictly_before`, so a request such as
  `?filter[publishedAt][after]=2024-01-01` reaches `DateFilter` and filters
  end-to-end instead of being silently dropped.
- Documentation links from the README to the guides (Installation, Basic Usage,
  Configuration, Filtering, Query Builder) and the API Reference.

### Changed

- `JsonApiConfigurator::buildFilterRegistry()` now scopes the auto-registered
  catch-all `JsonApiFilterHandler` off every field already owned by a declared
  field-specific filter (`SearchFilter`, `DateFilter`, or a custom one). When a
  field-specific filter covers every configured field, no catch-all is registered
  at all.

  **Behavioural change:** queries built from `buildFilterRegistry()` may now
  produce different results for configurations that mix a `SearchFilter` or
  `DateFilter` with the catch-all. A field owned by `DateFilter` is filtered with
  `after` / `before` rather than the catch-all's `gte` / `lte` — if you relied on
  `gte` / `lte` on a date field through the catch-all, leave that field off the
  `DateFilter` so the catch-all keeps handling it.

### Fixed

- Partial search (`SearchFilter` with `SearchStrategy::PARTIAL` / `START` / `END`)
  no longer collapses to exact matches when combined with the registry produced by
  `buildFilterRegistry()`. The previously unscoped catch-all emitted
  `field = value` alongside the intended `field LIKE '%value%'`, so partial search
  silently returned only exact hits.

## [0.1.0] - 2026-06-05

Initial public release.

[Unreleased]: https://github.com/modufolio/json-api/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/modufolio/json-api/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/modufolio/json-api/releases/tag/v0.1.0
