# Angeo AEO Audit — AI Engine Optimization for Magento 2

[![Packagist Version](https://img.shields.io/packagist/v/angeo/module-aeo-audit.svg)](https://packagist.org/packages/angeo/module-aeo-audit)
[![Packagist Downloads](https://img.shields.io/packagist/dt/angeo/module-aeo-audit.svg)](https://packagist.org/packages/angeo/module-aeo-audit)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%3E%3D8.2-8892BF.svg)](https://php.net)
[![Magento](https://img.shields.io/badge/magento-2.4.6%20%7C%202.4.7-EE672F.svg)](https://magento.com)

**One CLI command that tells you exactly why ChatGPT, Gemini, and Perplexity aren't recommending your store — and how to fix it.**

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

## What's new in v2.1.1

**Score Trend dashboard** — admin page under **Marketing → Angeo AEO → Score Trend** shows AEO score over time as a line chart. Includes store selector, period selector (7 / 30 / 90 / 365 days), reference lines at 65% and 85%, and per-store score cards.

**Dynamic fix commands in CLI output** — when a signal fails, the audit output shows the exact `composer require` command to fix it. Each checker knows its own fix module, so only relevant commands are shown.

**`llms.jsonl` as Signal #2b** — new checker validates the machine-readable catalog file at `/llms.jsonl`. Checks JSON Lines format validity, required fields, eCommerce fields, record count, file freshness via `Last-Modified`. Weight 0.75.

**Deeper `llms.txt` validation** — 12 checks instead of 5: description paragraph after H1, eCommerce section detection, currency/language metadata, duplicate URL detection, file freshness, dead link HEAD-checks, `llms-full.txt` presence.

**Recursive `@graph` parsing** — JSON-LD schemas now parsed recursively at any nesting level. Handles top-level arrays, nested `@graph`, and mixed structures correctly.

---

## What it checks

| # | Signal | Weight | What it validates |
|---|--------|--------|-------------------|
| 1 | **robots.txt — AI bot access** | 1.0 | OAI-SearchBot, GPTBot, ClaudeBot, PerplexityBot, Google-Extended + 5 more; full parser with first-match semantics |
| 2 | **llms.txt — AI content map** | 1.0 | H1 title, description, H2 sections, markdown links, eCommerce sections, metadata, freshness, dead links |
| 2b | **llms.jsonl — machine-readable catalog** | 0.75 | JSON Lines validity, required fields, eCommerce fields, record count, freshness |
| 3 | **sitemap.xml** | 0.8 | XML validity, URL count, lastmod freshness, robots.txt reference |
| 4 | **Product schema — JSON-LD** | 1.0 | Real product page, `offers.availability`, Hyvä detection |
| 5 | **FAQPage schema** | 0.5 | Homepage and CMS pages |
| 6 | **AI product feed** | 1.0 | Feed file, `/.well-known/ai-plugin.json`, REST API endpoint |
| 7 | **Open Graph tags** | 0.7 | All 5 required tags, description length |
| 8 | **Canonical tags** | 0.6 | Presence and domain mismatch detection |

---

## Installation

```bash
composer require angeo/module-aeo-audit
bin/magento setup:upgrade
bin/magento cache:flush
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

# Fail build if score below threshold
bin/magento angeo:aeo:audit --fail-on=80

# Run without saving to DB (CI / read-only environments)
bin/magento angeo:aeo:audit --no-save
```

Sample output:

```
  AEO Score: [████████████████░░░░] 79% — Good
  ✓ Pass: 6  ⚠ Warn: 2  ✗ Fail: 1

  Critical fixes needed:
  → Install angeo/module-openai-product-feed and register at chatgpt.com/merchants

  💡 Fix with angeo modules:
     composer require angeo/module-openai-product-feed angeo/module-openai-product-feed-api
```

---

## Admin UI

**Marketing → Angeo AEO → AEO Audit Results** — full history grid with score, pass/warn/fail counts, triggered-by, and date.

**Marketing → Angeo AEO → Score Trend** — line chart of AEO score over time per store.

**Marketing → Angeo AEO → Run Audit Now** — trigger an on-demand audit from the browser.

---

## Score interpretation

| Score | Label | Typical situation |
|---|---|---|
| 0–25% | Needs Improvement | Default Magento install. AI crawlers blocked. |
| 26–50% | Needs Improvement | Some fixes applied. Schema or feed missing. |
| 51–75% | Moderate | Core signals in place. Feed not registered. |
| 76–90% | Good | Strong foundation. Minor gaps. |
| 91–100% | Excellent | Full AEO compliance. |

---

## Cron

Weekly audit every Monday at 03:00 server time. Results saved to DB, last 50 per store retained.

```bash
bin/magento cron:run --group=default
```

---

## Extending with custom checks

Implement `Angeo\AeoAudit\Api\CheckerInterface` and register via `di.xml`:

```xml
<type name="Angeo\AeoAudit\Model\AuditRunner">
    <arguments>
        <argument name="checkers" xsi:type="array">
            <item name="my_check" xsi:type="object">Vendor\Module\Model\Checker\MyChecker</item>
        </argument>
    </arguments>
</type>
```

Interface:

```php
public function getName(): string;       // "My Custom Check"
public function getCode(): string;       // "my_check"
public function getWeight(): float;      // 0.0–1.0
public function getFixCommand(): string; // "composer require vendor/fix-module" or ""
public function check(string $baseUrl): CheckResult;
```

---

## Running tests

```bash
vendor/bin/phpunit -c app/code/Angeo/AeoAudit/phpunit.xml
```

Test coverage: 9 of 9 checkers (RobotsTxtChecker, LlmsTxtChecker, LlmsJsonlChecker, SitemapXmlChecker, ProductSchemaChecker, FaqSchemaChecker, ProductFeedChecker, OpenGraphChecker, CanonicalChecker) plus AuditReport unit test.

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
| [`angeo/module-aeo-audit`](https://packagist.org/packages/angeo/module-aeo-audit) | — | **This module** — audit all 9 signals |
| [`angeo/module-robots-txt-aeo`](https://packagist.org/packages/angeo/module-robots-txt-aeo) | #1 | Inject AI bot rules into robots.txt |
| [`angeo/module-llms-txt`](https://packagist.org/packages/angeo/module-llms-txt) | #2, #2b | Generate llms.txt and llms.jsonl |
| [`angeo/module-rich-data`](https://packagist.org/packages/angeo/module-rich-data) | #4, #5 | Product and FAQPage JSON-LD schema |
| [`angeo/module-openai-product-feed`](https://packagist.org/packages/angeo/module-openai-product-feed) | #6 | ACP product feed for ChatGPT Shopping |
| [`angeo/module-openai-product-feed-api`](https://packagist.org/packages/angeo/module-openai-product-feed-api) | #6 | REST API — 6 ACP endpoints |
| [`angeo/module-openai-instant-checkout`](https://packagist.org/packages/angeo/module-openai-instant-checkout) | — | Agentic Commerce Protocol — instant checkout from ChatGPT |

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

Made with care by [Ievgenii Gryshkun](https://angeo.dev) — open-source contributions to the Magento + AI commerce ecosystem.
