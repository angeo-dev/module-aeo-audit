# Changelog

All notable changes to `angeo/module-aeo-audit` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.1.2] — 2026-05-01

### Fixed
- Recursive `@graph` parsing in JSON-LD extraction — handles nested `@graph` and top-level array roots correctly
- Bug-report URL in fallback error path now points to the correct repository
- Composer constraint accuracy: explicit `^` ranges for Magento dependencies instead of `*`
- `Test/` directory excluded from production classmap

### Added
- Unit tests for `ProductSchemaChecker`, `FaqSchemaChecker`, `ProductFeedChecker`, `LlmsJsonlChecker` (9 of 9 checkers now have tests)
- `CHANGELOG.md` and `CONTRIBUTING.md`
- GitHub Actions CI workflow (PHPUnit + PHPStan + MCS)
- Magento Coding Standard as a `require-dev` dependency

### Changed
- README updated with explicit Magento version compatibility (2.4.6, 2.4.7, 2.4.8)
- README mentions tested PHP versions (8.2, 8.3, 8.4)
- Removed hardcoded `version` field from `composer.json` — Packagist resolves from git tags

## [2.1.1] — 2026-04-24

### Added
- `getFixCommand()` method on `CheckerInterface` for dynamic CLI fix suggestions
- `LlmsJsonlChecker` for `/llms.jsonl` validation
- Score Trend dashboard in admin UI

## [2.1.0] — 2026-04-15

### Added
- Score Trend dashboard
- Dynamic fix commands in CLI output
- Deeper `llms.txt` validation (12 checks)

## [2.0.0] — 2026-03-20

### Added
- Deep robots.txt parser with first-match semantics
- Product schema validation including `offers.availability`
- Hyvä theme detection
- Admin UI with results grid
- Cron scheduling (weekly Monday 03:00)
- Extensible architecture via `CheckerInterface` + `di.xml`

### Changed
- Weighted scoring: critical signals weight 1.0, informational lower

## [1.0.0] — 2026-02-10

### Added
- Initial release with 6 AEO signal checks
- CLI command `bin/magento angeo:aeo:audit`
- Table, JSON, and Markdown output formats
