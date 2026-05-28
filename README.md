# Angeo AEO Audit — AI Engine Optimization for Magento 2

[![Packagist Version](https://img.shields.io/packagist/v/angeo/module-aeo-audit.svg)](https://packagist.org/packages/angeo/module-aeo-audit)
[![Packagist Downloads](https://img.shields.io/packagist/dt/angeo/module-aeo-audit.svg)](https://packagist.org/packages/angeo/module-aeo-audit)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%3E%3D8.2-8892BF.svg)](https://php.net)
[![Magento](https://img.shields.io/badge/magento-2.4.6%20%7C%202.4.7%20%7C%202.4.8-EE672F.svg)](https://magento.com)

**One CLI command that tells you exactly why ChatGPT, Gemini, Claude, and Perplexity aren't recommending your store — and how to fix it.**

- 🏠 Project home: [angeo.dev](https://angeo.dev)
- 📦 Source: [github.com/angeo-dev/module-aeo-audit](https://github.com/angeo-dev/module-aeo-audit)
- 🐛 Issues: [github.com/angeo-dev/module-aeo-audit/issues](https://github.com/angeo-dev/module-aeo-audit/issues)
- 📖 Full guide: [Magento 2 AEO Guide 2026](https://angeo.dev/magento-2-aeo-guide/)

---

## Compatibility

| Component | Version |
|---|---|
| Magento Open Source | 2.4.6, 2.4.7, 2.4.8 |
| Adobe Commerce | 2.4.6, 2.4.7, 2.4.8 |
| Adobe Commerce Cloud | All current versions |
| PHP | 8.2, 8.3, 8.4 |
| Themes | Luma, Hyvä |

Tested with: Magento Open Source 2.4.7-p3 + PHP 8.3 + Hyvä 1.3.

---

## What's new in v3.0.0

> **Major release** — see [CHANGELOG.md](CHANGELOG.md) for the breaking-change
> migration guide if you have custom checkers.

**15 signals** (up from 9), reflecting the actual AEO landscape of 2026: AI
shopping integrations, merchant policies, agentic commerce, and structured-data
quality.

**6 new checkers:**

- `merchant_policies` — `MerchantReturnPolicy` + `OfferShippingDetails` —
  required by Google AI Mode and ChatGPT Shopping since Jan 2026
- `organization_schema` — brand entity in AI knowledge graphs
- `ucp_profile` — Universal Commerce Protocol (`/.well-known/ucp`), with
  built-in security check that detects leaked JWK private keys
- `jsonld_quality` — three-page schema breadth audit (homepage / category /
  product), `WebSite+SearchAction`, `BreadcrumbList`, `ItemList`
- `well_known` — discovery matrix for `/.well-known/{ucp,ai-plugin.json,security.txt,mcp}`
- `core_web_vitals` — LCP / INP / CLS via Google CrUX API (free, opt-in
  with API key)

**Refactored architecture** (this is the BC-break):

- Shared `Service\HttpCache` — eliminates duplicate fetches across checkers
  (hundreds of redundant HTTP requests on multi-store audits before, dozens
  now)
- `Service\StoreUrlSampler` — single source of truth for product / category /
  CMS URL sampling
- New `--category` and `--fail-on-severity` CLI flags for CI workflows
- Per-checker exception isolation — slow or failing checkers no longer halt
  the audit run

> **Note on access-log monitoring**: an `ai_bot_traffic` checker was
> prototyped during v3 development and excluded from the release after
> security review — it encouraged broad read access on `/var/log/nginx/`,
> didn't work on Cloud/containerised hosting, and was dominated by false
> positives behind edge caches. AI-bot traffic is better measured at the
> edge (Fastly/Cloudflare Analytics) or via APM (New Relic, Datadog) than
> inside a PHP module. See CHANGELOG.md "Considered and rejected" for the
> full rationale. The `live_signal` category remains in `CheckerInterface`
> for third-party modules with secure live-signal sources — notably
> `angeo/module-aeo-brand-visibility`.

---

## What it checks — 15 signals

| #  | Signal | Code | Weight | Category | What it validates |
|----|--------|------|--------|----------|-------------------|
| 1  | **robots.txt — AI bots** | `robots_txt` | 1.0 | technical | 12 AI bots, syntax errors, versioned UAs, conflicting rules |
| 2  | **llms.txt — content map** | `llms_txt` | 1.0 | technical | Spec compliance + store-locale + currency match + cross-host links |
| 3  | **llms.jsonl — catalog** | `llms_jsonl` | 0.75 | technical | JSON Lines validity, required fields, eCommerce fields |
| 4  | **sitemap.xml** | `sitemap` | 0.8 | technical | XML, lastmod, `.gz`, catalog disproportion |
| 5  | **Product schema** | `product_schema` | 1.0 | technical | JSON-LD on real product, offers, Hyvä detection |
| 6  | **Merchant policies** ★ NEW | `merchant_policies` | 0.9 | technical | `hasMerchantReturnPolicy`, `OfferShippingDetails`, `priceValidUntil`, `itemCondition` |
| 7  | **Organization schema** ★ NEW | `organization_schema` | 0.8 | technical | `Organization` / `OnlineStore` on homepage, `sameAs`, logo |
| 8  | **UCP profile** ★ NEW | `ucp_profile` | 0.9 | technical | `/.well-known/ucp`, signing keys, leaked-private-key detection |
| 9  | **AI product feed** | `ai_product_feed` | 1.0 | feed | Feed file, `/.well-known/ai-plugin.json`, REST endpoint |
| 10 | **JSON-LD quality** ★ NEW | `jsonld_quality` | 0.7 | technical | Breadcrumb, ItemList, WebSite+SearchAction, duplicate schemas |
| 11 | **Canonical + hreflang** | `canonical` | 0.7 | technical | Canonical agrees with og:url + JSON-LD url; hreflang on multi-store |
| 12 | **Open Graph** | `open_graph` | 0.7 | technical | All 5 OG tags, description length |
| 13 | **FAQ schema** | `faq_schema` | 0.5 | technical | FAQPage JSON-LD on homepage or sampled CMS page |
| 14 | **Well-known matrix** ★ NEW | `well_known` | 0.5 | technical | ucp / ai-plugin.json / security.txt / mcp inventory |
| 15 | **Core Web Vitals** ★ NEW | `core_web_vitals` | 0.5 | external_api | LCP / INP / CLS via Google CrUX (API key required) |

★ NEW = added in v3.0.0.

---

## Installation

```bash
composer require angeo/module-aeo-audit
bin/magento setup:upgrade
bin/magento cache:flush
```

For full coverage, install the companion modules:

```bash
composer require \
  angeo/module-llms-txt \
  angeo/module-rich-data \
  angeo/module-openai-product-feed \
  angeo/module-openai-product-feed-api \
  angeo/module-ucp \
  angeo/module-aeo-brand-visibility
```

---

## CLI usage

```bash
# Audit all stores
bin/magento angeo:aeo:audit

# Specific store
bin/magento angeo:aeo:audit --store=en_us

# JSON output (for dashboards / CI)
bin/magento angeo:aeo:audit --format=json

# Markdown report to file
bin/magento angeo:aeo:audit --format=markdown --output=/var/www/html/aeo-report.md

# Fast technical-only checks (skip external APIs)
bin/magento angeo:aeo:audit --category=technical

# Run only external-API checks (Core Web Vitals + any third-party live signals)
bin/magento angeo:aeo:audit --category=external_api,live_signal

# Fail build if score below threshold
bin/magento angeo:aeo:audit --fail-on=80

# Fail build if any critical-severity check fails
bin/magento angeo:aeo:audit --fail-on-severity=critical

# Run without saving to DB (CI / read-only environments)
bin/magento angeo:aeo:audit --no-save
```

Sample output:

```
  AEO Score: [████████████████░░░░] 81% — Good
  ✓ Pass: 12  ⚠ Warn: 3  ✗ Fail: 1

  Critical fixes needed:
  → Install angeo/module-openai-product-feed and register at chatgpt.com/merchants

  💡 Fix with angeo modules:
     composer require angeo/module-openai-product-feed angeo/module-openai-product-feed-api
     composer require angeo/module-ucp
```

---

## Configuration

Some checkers need configuration. All are accessed via:
**Stores → Configuration → Angeo AEO**.

| Setting | Purpose |
|---------|---------|
| **CrUX API Key** | Required by `core_web_vitals` checker. Free key from [console.cloud.google.com](https://console.cloud.google.com/) — enable the Chrome UX Report API. Stored encrypted. |

---

## Admin UI

- **Marketing → Angeo AEO → AEO Audit Results** — full history grid
- **Marketing → Angeo AEO → Score Trend** — line chart of AEO score over time
- **Marketing → Angeo AEO → Run Audit Now** — trigger an on-demand audit

---

## Score interpretation

| Score | Label | Typical situation |
|---|---|---|
| 0–25% | Critical | Default Magento install. AI crawlers blocked. No schema. |
| 26–50% | Needs Improvement | Some fixes applied. Feed or merchant policies missing. |
| 51–75% | Needs Improvement | Core signals in place. UCP, ai-plugin.json, or hreflang missing. |
| 76–90% | Good | Strong foundation. Minor gaps in well-known or CWV. |
| 91–100% | Excellent | Full 2026 AEO compliance. |

---

## Cron

Weekly audit every Monday at 03:00 server time. Results saved to DB,
last 50 per store retained.

```bash
bin/magento cron:run --group=default
```

For fast daily checks (without external APIs or log scans), schedule an
additional cron job calling the audit with `--category=technical`.

---

## Extending with custom checks

Implement `Angeo\AeoAudit\Api\CheckerInterface` (or extend
`Angeo\AeoAudit\Model\Checker\AbstractChecker`, which provides HTTP cache,
URL sampling and JSON-LD parsing), and register via `di.xml`:

```xml
<type name="Angeo\AeoAudit\Model\AuditRunner">
    <arguments>
        <argument name="checkers" xsi:type="array">
            <item name="my_check" xsi:type="object">Vendor\Module\Model\Checker\MyChecker</item>
        </argument>
    </arguments>
</type>
```

v3 interface:

```php
public function getName(): string;       // "My Custom Check"
public function getCode(): string;       // "my_check"
public function getWeight(): float;      // 0.0–1.0
public function getCategory(): string;   // CheckerInterface::CATEGORY_*
public function getSeverity(): string;   // CheckerInterface::SEVERITY_*
public function getFixCommand(): string; // "composer require vendor/fix-module" or ""
public function check(\Magento\Store\Api\Data\StoreInterface $store): CheckResult;
```

Migrating from v2? See [CHANGELOG.md](CHANGELOG.md) for the migration guide.

---

## Running tests

```bash
vendor/bin/phpunit -c app/code/Angeo/AeoAudit/phpunit.xml
```

v3 ships with unit tests covering all 15 checkers, both services
(`HttpCache`, `StoreUrlSampler`), the `AuditRunner`, and the report value
objects.

---

## Code quality

```bash
# Magento Coding Standard
vendor/bin/phpcs --standard=Magento2 \
    --extensions=php,phtml --severity=10 \
    app/code/Angeo/AeoAudit/

# PHPStan static analysis
vendor/bin/phpstan analyse -l 5 app/code/Angeo/AeoAudit/
```

---

## The Angeo AI Visibility Suite

| Module | Signal | Purpose |
|--------|--------|---------|
| [`angeo/module-aeo-audit`](https://packagist.org/packages/angeo/module-aeo-audit) | — | **This module** — audit all 15 signals |
| [`angeo/module-robots-txt-aeo`](https://packagist.org/packages/angeo/module-robots-txt-aeo) | #1 | Inject AI bot rules into robots.txt |
| [`angeo/module-llms-txt`](https://packagist.org/packages/angeo/module-llms-txt) | #2, #3 | Generate llms.txt and llms.jsonl |
| [`angeo/module-rich-data`](https://packagist.org/packages/angeo/module-rich-data) | #5, #6, #7, #13 | Product, Organization, FAQ JSON-LD + merchant policies |
| [`angeo/module-openai-product-feed`](https://packagist.org/packages/angeo/module-openai-product-feed) | #9 | ACP product feed for ChatGPT Shopping |
| [`angeo/module-openai-product-feed-api`](https://packagist.org/packages/angeo/module-openai-product-feed-api) | #9 | REST API — 6 ACP endpoints |
| [`angeo/module-openai-instant-checkout`](https://packagist.org/packages/angeo/module-openai-instant-checkout) | — | Agentic Commerce Protocol — instant checkout from ChatGPT |
| [`angeo/module-ucp`](https://packagist.org/packages/angeo/module-ucp) | #8 | Universal Commerce Protocol — `/.well-known/ucp` |
| [`angeo/module-aeo-brand-visibility`](https://packagist.org/packages/angeo/module-aeo-brand-visibility) | (extends) | Live AI visibility across ChatGPT, Claude, Perplexity, Gemini, Groq |

---

## Contributing

Issues and PRs welcome at [github.com/angeo-dev/module-aeo-audit](https://github.com/angeo-dev/module-aeo-audit).

Before opening a PR:
1. Run `vendor/bin/phpunit -c phpunit.xml` — all tests must pass
2. Run `vendor/bin/phpcs --standard=Magento2` — no MCS violations
3. Add tests for any new checker

---

## License

MIT — see [LICENSE](LICENSE)

---

Made with care by [Ievgenii Gryshkun](https://angeo.dev) — open-source
contributions to the Magento + AI commerce ecosystem.
