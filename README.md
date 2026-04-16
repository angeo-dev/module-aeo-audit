# Angeo AEO Audit — AI Engine Optimization for Magento 2

[![Packagist Version](https://img.shields.io/packagist/v/angeo/module-aeo-audit.svg)](https://packagist.org/packages/angeo/module-aeo-audit)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%3E%3D8.2-8892BF.svg)](https://php.net)

**One CLI command that tells you exactly why ChatGPT, Gemini, and Perplexity aren't recommending your store — and how to fix it.**

---

## What's new in v2.0.0

- **Deep checks** — robots.txt is now fully parsed (not string-searched); Product schema validates `offers.availability`; sitemap checks XML validity and lastmod freshness; llms.txt validated against the actual spec
- **Weighted scoring** — critical checks (robots, schema, feed) have weight 1.0; informational checks have lower weights; score reflects real impact
- **Hyvä theme detection** — Product schema check auto-detects Hyvä and gives a specific fix recommendation
- **Admin UI** — full results grid under Marketing → Angeo AEO → AEO Audit Results
- **Cron scheduling** — automatic weekly audit every Monday at 03:00; results saved to DB
- **Run from Admin** — Marketing → Angeo AEO → Run Audit Now
- **Extensible via di.xml** — third-party modules can inject custom `CheckerInterface` implementations
- **Safety net in `AuditRunner`** — uncaught exceptions in checkers are caught and recorded as FAIL, never crash the process

---

## What it checks

| Check | Weight | Why it matters |
|-------|--------|----------------|
| **robots.txt — AI bot access** | 1.0 | GPTBot, OAI-SearchBot, ClaudeBot, PerplexityBot, Google-Extended + 5 more; full parser |
| **llms.txt — AI content map** | 1.0 | H1 title, markdown links, section count, llms-full.txt bonus |
| **sitemap.xml** | 0.8 | XML validity, URL count, lastmod freshness, robots.txt reference |
| **Product schema — JSON-LD** | 1.0 | Real product page, offers.availability, Hyvä detection |
| **FAQPage schema** | 0.5 | Homepage + CMS FAQ pages |
| **AI product feed** | 1.0 | CSV/JSON feed, /.well-known/ai-plugin.json, REST API |
| **Open Graph tags** | 0.7 | All 5 required tags, description length check |
| **Canonical tags** | 0.6 | Presence + domain mismatch detection |

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

# JSON output (for dashboards)
bin/magento angeo:aeo:audit --format=json

# Markdown report to file
bin/magento angeo:aeo:audit --format=markdown --output=/var/www/html/aeo-report.md

# CI pipeline — fail build if score below 80%
bin/magento angeo:aeo:audit --fail-on=80

# Run without saving to DB (CI / read-only environments)
bin/magento angeo:aeo:audit --no-save
```

---

## Admin UI

Navigate to **Marketing → Angeo AEO → AEO Audit Results** to view the full audit history grid with score, pass/warn/fail counts, triggered-by, and date columns.

Click **View** on any row for a detailed breakdown of all checks with messages and recommendations.

Click **Run Audit Now** to trigger an on-demand audit for all stores.

---

## Cron

The module registers a weekly cron job that runs every Monday at 03:00 server time:

```
bin/magento cron:run --group=default
```

Results are saved automatically to the DB and visible in the Admin Grid. The last 50 results per store are retained; older records are pruned automatically.

To test the cron manually:

```bash
bin/magento angeo:aeo:audit  # saves to DB same as cron
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

Your checker must implement:

```php
public function getName(): string;    // "My Custom Check"
public function getCode(): string;    // "my_check" — unique, used in JSON output
public function getWeight(): float;   // 0.0–1.0
public function check(string $baseUrl): CheckResult;
```

---

## Running tests

```bash
vendor/bin/phpunit -c app/code/Angeo/AeoAudit/phpunit.xml
```

---

## The Angeo AI Suite for Magento 2

| Module | Purpose |
|--------|---------|
| [`angeo/module-aeo-audit`](https://packagist.org/packages/angeo/module-aeo-audit) | **This module** — audit your AI readiness |
| [`angeo/module-llms-txt`](https://packagist.org/packages/angeo/module-llms-txt) | Generate llms.txt for ChatGPT, Claude, Gemini |
| [`angeo/module-openai-product-feed`](https://packagist.org/packages/angeo/module-openai-product-feed) | AI product feed for ChatGPT Shopping |
| [`angeo/module-openai-product-feed-api`](https://packagist.org/packages/angeo/module-openai-product-feed-api) | Full ACP REST API — 6 endpoints |

---

## License

MIT — see [LICENSE](LICENSE)
