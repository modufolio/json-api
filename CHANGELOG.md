# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
While the project is in the `0.x` series the public API is not considered stable:
behaviour may change in any minor release.

## [Unreleased]
  a URI assertion — so anyone who tried it hit broken SQL.

## [0.4.0] - 2026-08-07

### Added

- **`AttributeCaster` converts request attributes to the types the entity
  declares.** A document carries an enum as its backing value and a date as a
  string, so handing them to setters typed `?ContactStatus` or
  `?DateTimeImmutable` is a `TypeError`. The caster reads the Doctrine mapping,
  covering enums, dates, booleans and decimals in one path:
  `$entity->setStatus($caster->cast($entity, 'status', 'active'))`. It casts
  only — the caller still calls setters, so domain logic in them survives.
  Unmapped fields and associations pass through untouched. Non-string values
  are left alone (JSON already produced real ints and booleans), and a string
  on a boolean field uses `filter_var` rules rather than DBAL's `(bool)` cast,
  which reads `"false"` as `true`.

- **`InvalidAttributeValueException` carries the field and the accepted
  values.** A well-formed attribute holding an unusable value is a semantic
  failure, so callers should answer `422` rather than `400`. `getField()` and
  `getAccepted()` supply what a JSON:API error object needs — a
  `source.pointer` and, for a backed enum, the values that would have been
  taken — without parsing the message. `getAccepted()` is empty for open-ended
  types like dates. Extends `InvalidArgumentException`, so existing catch
  blocks are unaffected.

## [0.3.0] - 2026-08-02

### Added

- **Resources can now be fetched by uuid as well as numeric id.** When the
  id in a show, update, or delete request is not numeric and the entity
  maps a `uuid` field, `JsonApiQueryBuilder` resolves the uuid to the
  numeric primary key up front and runs the operation unchanged — so
  `GET /api/contact/31331347-a19d-4a0c-8689-3f1985034300` works exactly
  like `GET /api/contact/29`. Entities without a `uuid` field, and uuids
  that match no row, fall through to the normal 404 path. Serialized
  documents still expose the numeric id; nothing changes for existing
  numeric lookups.

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

[Unreleased]: https://github.com/modufolio/json-api/compare/v0.4.0...HEAD
[0.4.0]: https://github.com/modufolio/json-api/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/modufolio/json-api/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/modufolio/json-api/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/modufolio/json-api/releases/tag/v0.1.0
