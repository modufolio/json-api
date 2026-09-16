# Changelog

All notable changes to this project are documented here. Unreleased work and
the most recent release are in this file in full; every earlier release has its
own file under [`releases/`](releases/), listed at the bottom.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
While the project is in the `0.x` series the public API is not considered stable:
behaviour may change in any minor release.

## [0.10.1] - 2026-09-16

### Changed

- **`JsonApiQueryBuilder` is split into components.** Schema lookups,
  row-level scope, built-in filters, include resolution, row hydration and
  the write statements now live in `@internal` classes under
  `Modufolio\JsonApi\Query`. Public API and behaviour are unchanged.
- **Create runs the same statement `debug()` shows.** It used to build one
  INSERT for debug output and run `Connection::insert()` for the real write;
  there is now a single path. The written row is identical.

## Earlier releases

- [0.10.0](releases/0.10.0.md) - 2026-09-15
- [0.9.0](releases/0.9.0.md) - 2026-09-06
- [0.8.0](releases/0.8.0.md) - 2026-09-05
- [0.7.0](releases/0.7.0.md) - 2026-08-27
- [0.6.0](releases/0.6.0.md) - 2026-08-27
- [0.5.0](releases/0.5.0.md) - 2026-08-27
- [0.4.0](releases/0.4.0.md) - 2026-08-07
- [0.3.0](releases/0.3.0.md) - 2026-08-02
- [0.2.0](releases/0.2.0.md) - 2026-06-05
- [0.1.0](releases/0.1.0.md) - 2026-06-05
