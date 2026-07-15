# Changelog

All notable changes to `angeo/module-aeo-audit` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [3.2.0] — 2026-07-15

> Security and tooling release. Hardens the Core Web Vitals checker's handling
> of the CrUX API key and adds continuous integration. The CrUX checker gains a
> constructor dependency (`Magento\\Framework\\Encryption\\EncryptorInterface`),
> which Magento's object manager injects automatically — no configuration
> changes are required. Upgrading from 3.1.x is drop-in (`composer update`,
> then `bin/magento setup:upgrade && bin/magento setup:di:compile`).

### Security

- **CrUX API key is now decrypted before use.** The key is stored encrypted
  (`Magento\\Config\\Model\\Config\\Backend\\Encrypted`); the checker now decrypts
  it via `EncryptorInterface` instead of reading the raw stored value. A value
  that fails to decrypt (rotated crypt key, corrupted data) is treated exactly
  like an unconfigured key — a garbage credential is never transmitted.
- **CrUX API key moved out of the request URL.** The key was previously sent as
  a `?key=` query parameter, where it leaks into web-server access logs, proxy
  logs and browser history. It now travels in the `X-Goog-Api-Key` request
  header.
- **TLS verification re-enabled for the CrUX call.** The external request was
  made with peer/host verification disabled, exposing it to man-in-the-middle
  interception of the API key and response. The call now goes through
  `HttpCache`, which keeps TLS verification on and restricts protocols, matching
  the module-wide HTTP security posture.

### Added

-- **Continuous integration.** GitHub Actions pipeline running PHPCS, PHPStan
and PHPUnit against PHP 8.2–8.4 on every push and pull request.
- **Build-status badge** in the README, backed by the CI workflow.
- **Official distribution channels** section clarifying that installation needs
  no custom Composer repository — only Packagist and GitHub are supported.

### Changed

- **PHP 8.5 and Magento 2.4.9 declared.** `composer.json` now allows PHP 8.2–8.5;
  compatibility table and badges updated accordingly.
- **PHPUnit accepts ^10.5 || ^11.0 || ^12.0** to match the 2.4.7–2.4.9 toolchains,
  with a version-agnostic `phpunit.xml` and a dedicated `Test/bootstrap.php`.

### Notes

- Static analysis (PHPStan) is run locally against a real Magento install and is
  intentionally not part of CI, since it requires the full framework.

## [3.1.0] — 2026-06-10

> Minor release. Adds per-signal enable/disable configuration, configurable
> sitemap placeholder-slug handling, and fixes two false-signal bugs in the
> sitemap checker. Fully backward compatible — no interface or DB changes.

### Added

- **Per-signal configuration.** Every one of the 15 signals can now be enabled
  or disabled individually under **Stores → Configuration → Angeo AEO → AEO
  Audit → Signals (Checks)**. All signals are **enabled by default**, so a
  fresh install behaves exactly as before. Disabled signals are skipped during
  the audit and excluded from the score entirely — they neither raise nor lower
  it (removed from both numerator and denominator). Settings are store-scoped.
- **Configurable sitemap placeholder-slug handling.** New group **Angeo AEO →
  AEO Audit → Sitemap Checker**:
    - `Placeholder slug handling` — *Affect score* (default) or *Ignore*
      (report-only, never changes status/score).
    - `Placeholder slug threshold` — how many placeholder slugs are tolerated
      before the score is affected (default 1).
- New `Angeo\AeoAudit\Model\Config` — a single typed reader for all module
  settings, so checkers no longer touch `ScopeConfig` directly.
- New `Angeo\AeoAudit\Model\Config\Source\SlugMode` option source.
- Unit tests: disabled-checker skipping in `AuditRunner`; sitemap foreign-element
  FAIL; placeholder-slug score/ignore modes; disproportion-false-positive
  regression.

### Fixed

- **Sitemap: false "disproportion" warning.** The v3 check compared sitemap URL
  count against active **products only**, but a sitemap also lists the homepage,
  CMS pages and categories — so healthy stores were frequently warned. URL count
  is now compared against the full indexable surface (products + categories +
  CMS pages) and reported as **informational context only** (`coverage_ratio`);
  it never changes the result status.
- **Sitemap: false "stale" warning.** Staleness was computed from the **first**
  `<lastmod>` in the file, so a single old entry (often the homepage or a CMS
  page) flagged the whole sitemap as stale. A legitimately unchanged product
  *should* keep an old `<lastmod>` — that is honest metadata, not a defect. The
  check now inspects the **newest** `<lastmod>` across the file and only warns
  if nothing at all has changed in 180 days (a sign of a broken generation
  cron). Individual old entries are informational only.

### Added — sitemap structural integrity

- **Sitemap: foreign-element detection.** Non-sitemap elements injected directly
  into `<urlset>` (e.g. a stray `<script>` from a theme or module) are now
  detected and reported as a FAIL. `libxml` parses such markup without error, so
  the previous XML-validity check missed it.
- **Sitemap: placeholder-slug detection.** Slugs that carry no meaning for an AI
  engine (`test2.html`, `product-name.html`, bare numbers, etc.) are surfaced so
  they can be renamed. Behaviour is controlled by the new configuration above.

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
