# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
While the project is in the `0.x` series the public API is not considered stable:
behaviour may change in any minor release.

## [0.5.0] - 2026-08-27

### Fixed

- **ManyToMany relationships can now be included.** `buildJoins()` excluded only
  `OneToMany` from the main query, so a ManyToMany include fell through to the
  join branch and emitted a single `LEFT JOIN` straight to the target table with
  an `ON` condition referencing a join table that was never joined or aliased —
  SQL that is malformed on its face, and that multiplied rows where it did run,
  making `LIMIT` slice joined pairs instead of records. `fetchToManyIncludes()`
  declined ManyToMany as well, so neither path served it. Both sides of the
  association now resolve through the join table with one batched
  `WHERE fk IN (…)`, the same shape `OneToMany` already used: the join table is
  declared on the owning side only, so an inverse-side mapping is read back off
  the target with its two columns swapped. This path previously had no test
  coverage at all — the only ManyToMany mapping in the suite was a mock used for
  a URI assertion — so anyone who tried it hit broken SQL.

- **`withTotalCount()` no longer inflates the total when the query carries a
  join.** `fetchTotalCount()` clones the built query, joins included, and counted
  with `COUNT(t0.id)`. It now counts `COUNT(DISTINCT t0.id)`, so a pager cannot
  advertise more pages than there are records.

- **`buildUri()` no longer emits an empty `page[size]=`.** It gated pagination on
  the size differing from the builder's default, which a disabled pagination
  size (`null`) also satisfies. There is no JSON:API parameter meaning "no
  limit", so the parameters are now omitted entirely in that case; the
  round-trip is lossy there by necessity.

- **An included to-one contributes every field its resource exposes.**
  `buildJoins()` selected `reset($allowedFields)` — the first configured field
  and nothing else — so a caller needing two columns off a joined record (a
  person's first *and* last name) could not reach the second at all. All allowed
  fields are now selected, aliased `rel_field` onto the parent's attributes.

- **Sparse fieldsets narrow included resources, not just the primary one.**
  `fields()` kept only the entry for the resource being queried and discarded
  the rest, so `fields[authors]=name` had no effect on a joined author. The full
  map is retained and applied to joined and batched resources alike. A fieldset
  naming only unexposed fields cannot widen access — the configured allow-list
  still wins.

### Added

- **Entity routes can declare the roles that reach them.**
  `JsonApiConfigurator::roles()` and `ResourceConfigurator::roles()` record a
  role list per entity, and `buildConfig()` emits it as a fifth `roles` key.
  A route loader writes it to the route as `_is_granted_roles`, where any one
  of the listed roles grants access.

- **`JsonApiQueryBuilder::withoutPagination()` returns every matching row.**
  Pagination was unconditional: `buildPage()` always produced bindings, so a
  `LIMIT` was emitted even for a caller that never mentioned paging, and there
  was no way to say otherwise. Note the two entry points still disagree on the
  default — a request parsed into `JsonApiQueryParams` gives size 10, a builder
  driven by hand falls back to 25 — which is exactly why an explicit opt-out was
  needed. Use it deliberately: the result is then bounded only by the data.

- **One level of nested includes is resolved.** A to-one second segment
  (`comments.author`) is joined onto the included rows, so an included record can
  carry a related record's columns rather than only its foreign key.

### Changed

- **`show`, `create` and `update` return the resource under `data`.** They
  returned it at the numeric key `0` — `[0 => $row, 'included' => …]` — while
  `index` used `data`, so a caller had to special-case which operation it had
  just run. `data` now holds the resource itself rather than a one-item list,
  matching how every JSON:API implementation splits a resource document from a
  collection document.

  A missing record returned a bare `[]`, which could not be told apart from a
  malformed result; it now returns `['data' => null]`. The spec has no document
  shape for a missing single resource — the answer is still a 404 — so this only
  makes the builder's signal to its caller unambiguous.

  **Behavioural change:** replace `$result[0]` with `$result['data']`, and
  `empty($result)` with `($result['data'] ?? null) === null`. `index` is
  unchanged, and `included` is still present on every operation that supports
  it, empty or not.

- **Unsupported nested include paths now throw instead of being ignored.**
  `fetchToManyIncludes()` collapsed every path with `explode('.', $path)[0]`, so
  the second segment was dropped silently even though the documentation
  advertised `comments.author`. An unknown second segment, a to-many second
  segment, and any third segment now raise `InvalidArgumentException`.

  **Behavioural change:** a request whose include path was previously discarded
  returned `200` with a document that looked well-formed and was quietly missing
  what was asked for. The same request now fails. Deep paths that were being
  ignored need shortening — or splitting into separate includes — before
  upgrading.

- **PHPStan runs at level 8** (was 5). Reaching it took annotating the array
  shapes across `src/` and `tests/` and fixing what the stricter pass turned
  up: `Str::snake()` fed a possibly-null `preg_replace()` result straight into
  `Str::lower()`, `executeCreate()` passed DBAL's `int|string` insert id to a
  `string` parameter, and `getAllowedRelationships()` could hand an int array
  key to metadata lookups that take a string. Fifteen tests dereferenced a
  `findOneBy()` result without checking it for null, which would have surfaced
  as a fatal rather than a failed assertion had the fixture not matched.
  (`phpstan.php`)

- **`phpstan/phpstan-phpunit` analyses the test suite.** PHPUnit's assertions
  now narrow types for static analysis, and an assertion that can never pass is
  reported instead of sitting green. (`phpstan.php`, `composer.json`)

### Documentation

- Corrected the response-assembly example in the installation and basic-usage
  guides. It mapped only `id` and `attributes`, so it dropped relationship
  linkage and produced no `included` member at all — a client requesting
  `?include=` received a well-formed document missing exactly what it asked for.
  The corrected flow is now executed by a test so it cannot drift again.
- Documented the `roles` config key (see Added above). The reference stated that
  `buildConfig()` produces "only four keys per entity… No other keys are read
  anywhere in the library"; `roles` is the fifth.
- Corrected the documented `get()` return shape, which claimed
  `['data' => …, 'total' => int]` "otherwise a list of rows". It is never a bare
  list, and `included` was missing from both forms.
- Noted that the two configuration APIs do not merge: `buildConfig()` returns the
  fluent configuration as soon as any `resource()` call has been made, so
  entities registered with `addEntity()`/`entities()` are ignored in that case.
- Documented that a custom filter claiming a field via `supports()` takes it away
  from the catch-all handler, so the standard `eq`/`in`/`null`/`not_null`
  operators reach that filter instead and are silently ignored unless it handles
  them.
- Documented `AttributeCaster`, which had no reference page, and the
  snake_case-conversion asymmetry between `sort` and `group` in the parser.

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

[Unreleased]: https://github.com/modufolio/json-api/compare/v0.5.0...HEAD
[0.5.0]: https://github.com/modufolio/json-api/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/modufolio/json-api/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/modufolio/json-api/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/modufolio/json-api/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/modufolio/json-api/releases/tag/v0.1.0
