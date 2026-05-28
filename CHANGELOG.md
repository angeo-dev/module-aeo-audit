# Changelog

All notable changes to `angeo/module-aeo-audit` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [3.0.0] — 2026-05-22

> Major release. Adds 6 new checkers, refactors the checker architecture, and
> requires changes in third-party modules that implement `CheckerInterface`.

### ⚠️ Breaking changes

- **`CheckerInterface::check()` signature changed** from
  `check(string $baseUrl): CheckResult` to
  `check(\Magento\Store\Api\Data\StoreInterface $store): CheckResult`.
  Custom checkers must be updated. The base URL is available via
  `$store->getBaseUrl()` or `StoreUrlSampler::getBaseUrl($store)`.
- **`CheckerInterface` adds two required methods**: `getCategory(): string` and
  `getSeverity(): string`. Subclassing `AbstractChecker` provides sensible
  defaults (technical / weight-derived severity). Custom checkers extending
  the interface directly need to implement both.
- **`AbstractChecker` constructor signature changed.** Now requires
  `HttpCache` and `StoreUrlSampler` instead of `Curl`. DI handles this
  automatically for checkers that don't override the constructor.

### Added — 6 new checkers (now 15 total signals)

- `MerchantPoliciesChecker` — validates `hasMerchantReturnPolicy` +
  `OfferShippingDetails` + `priceValidUntil` + `itemCondition` on a sampled
  product. Required by Google AI Mode and ChatGPT Shopping since Jan 2026.
  Weight 0.9.
- `OrganizationSchemaChecker` — validates `Organization` / `OnlineStore`
  JSON-LD on the homepage. Establishes brand entity in AI knowledge graphs.
  Weight 0.8.
- `UcpProfileChecker` — validates `/.well-known/ucp` (Universal Commerce
  Protocol, integration with `angeo/module-ucp`). HTTPS-only, JWK validation
  including **leaked-private-key detection** (CRITICAL security check).
  Weight 0.9.
- `JsonLdQualityChecker` — three-page scan (home + product + category) with
  `@context` validation, duplicate-schema detection, `BreadcrumbList` /
  `ItemList` / `WebSite+SearchAction` presence. Weight 0.7.
- `WellKnownAggregateChecker` — inventory matrix for `/.well-known/ucp`,
  `ai-plugin.json`, `security.txt`, `mcp`. Weight 0.5.
- `CoreWebVitalsChecker` — LCP / INP / CLS via Google CrUX API
  (requires API key under Stores → Configuration → Angeo AEO).
  Category `external_api`. Weight 0.5.

### Added — architecture

- `Service\HttpCache` — request-scoped HTTP cache. Eliminates duplicate
  fetches across checkers (a single `runAll()` for 10 stores went from
  hundreds of HTTP requests to a few dozen).
- `Service\StoreUrlSampler` — centralized product / category / CMS URL
  sampling, memoized per store. Replaces ad-hoc sampling logic inside
  individual checkers.
- `--category` CLI flag — filter checkers by category
  (`technical|live_signal|external_api|feed`). Useful for fast cron checks.
- `--fail-on-severity` CLI flag — fail the build on `critical` / `important`
  / `info` severity. Complements `--fail-on=<score>` for CI.
- Per-checker timeout logging — slow checkers (>30s) emit warning to log;
  checker exceptions no longer halt the audit run.
- `Test/Unit/Model/Checker/CheckerTestHelper` trait — shared test scaffolding
  for checker unit tests.

### Enhanced — existing checkers

- `RobotsTxtChecker`: detects versioned UAs (`GPTBot/1.0`), `Crawl-delay`
  on bots that ignore it, HTTP sitemap directives, conflicting `Allow:` /
  `Disallow:` rules. AI bot list expanded to 12.
- `SitemapXmlChecker`: detects `sitemap.xml.gz`, compares URL count to
  active catalog product count (warns on >30% delta).
- `LlmsTxtChecker`: validates store-locale + currency match metadata;
  flags cross-host links on subdomain stores.
- `CanonicalChecker`: now cross-checks canonical against `og:url` and
  Product JSON-LD `url`; verifies HTTPS; checks hreflang presence on
  multi-store setups.

### Considered and rejected — `ai_bot_traffic` checker

An access-log-based AI-bot traffic checker was prototyped during the v3
development cycle and **excluded from the release** after a security and
usefulness review. The summary, recorded so the trade-off is documented:

- **Encouraged poor permissions hygiene.** The natural way to make
  `/var/log/nginx/access.log` readable to PHP-FPM is `usermod -aG adm
  www-data` or `chmod 644`, both of which expose unrelated sensitive logs
  (auth.log, syslog) to any future LFI/RCE in the application. The
  bundled ACL guidance helped, but a module whose presence creates the
  incentive at all violates "secure by default".
- **Unusable on managed platforms.** On Adobe Commerce Cloud, Magento
  Cloud, and any containerised hosting, nginx logs go to stdout and
  centralised collection (Fastly/New Relic/Splunk). PHP-FPM cannot read
  them at all. The check returns WARN on these platforms 100% of the
  time, contributing only noise.
- **Dominated by false positives.** Even on self-hosted setups, sites
  behind Cloudflare/Fastly with edge caching never see the AI bots reach
  origin — the bots are served from edge. WARN again.
- **Better-served externally.** Edge analytics (Fastly, Cloudflare
  Analytics), APM platforms (New Relic, Datadog), and dedicated log
  analyzers (GoAccess, Matomo) measure AI-bot traffic without coupling
  it to PHP application permissions.

This means **15 built-in signals, not 16**. The `live_signal` category
remains in `CheckerInterface` for third-party modules that have their own
secure live-signal source — notably `angeo/module-aeo-brand-visibility`,
which queries AI provider APIs rather than parsing host logs.

### Configuration

- New encrypted config field: `angeo_aeo/crux/api_key`
  (Stores → Configuration → Angeo AEO → CrUX API Key).

### Suggested

- New `suggest` entry: `angeo/module-ucp` — companion module for UCP profile.
- New `suggest` entry: `angeo/module-aeo-brand-visibility` — live AI
  visibility checker (adds a `brand_visibility` signal via DI injection).

### Migration guide for v2 → v3

For most users (using only built-in checkers): `composer update`. No code
changes needed.

For custom checkers extending `AbstractChecker`: update the `check()` signature:

```diff
- public function check(string $baseUrl): CheckResult
+ public function check(\Magento\Store\Api\Data\StoreInterface $store): CheckResult
  {
-     [$status, $html] = $this->fetch($baseUrl . '/path');
+     $base = $this->urlSampler->getBaseUrl($store);
+     [$status, $html] = $this->fetch($base . '/path');
  }
```

For custom checkers implementing `CheckerInterface` directly: also add
`getCategory()` and `getSeverity()`. Sensible defaults:

```php
public function getCategory(): string { return CheckerInterface::CATEGORY_TECHNICAL; }
public function getSeverity(): string { return CheckerInterface::SEVERITY_IMPORTANT; }
```

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
