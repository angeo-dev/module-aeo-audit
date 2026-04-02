# Angeo AEO Audit — AI Engine Optimization Auditor for Magento 2

[![Packagist Version](https://img.shields.io/packagist/v/angeo/module-aeo-audit.svg)](https://packagist.org/packages/angeo/module-aeo-audit)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%3E%3D8.2-8892BF.svg)](https://php.net)

**One CLI command that tells you exactly why ChatGPT, Gemini, and Perplexity aren't recommending your store — and how to fix it.**

---

## 🚀 Overview

`angeo/module-aeo-audit` is a Magento 2 CLI tool that audits your store's **AEO (AI Engine Optimization)** readiness. It checks all the signals that AI search engines use to discover, index, and cite your store in conversational results.

Run the audit, get a scored report, fix the issues — then watch your store appear in ChatGPT Shopping, Google AI Overviews, and Gemini answers.

---

## ✅ What It Checks

| Check | Why It Matters |
|-------|----------------|
| **robots.txt — AI bot access** | GPTBot, ClaudeBot, PerplexityBot, anthropic-ai, Google-Extended |
| **llms.txt — AI content map** | The new standard for guiding LLMs to your best content |
| **sitemap.xml** | AI crawlers rely on sitemaps for complete discovery |
| **Product JSON-LD schema** | ChatGPT & Gemini extract product data from structured markup |
| **FAQPage schema** | Increases AI citation probability for answer-style queries |
| **AI Product Feed** | Required for ChatGPT Shopping and Gemini product cards |
| **Open Graph tags** | AI engines use og:description as content fallback |
| **Canonical tags** | Prevents AI indexing of duplicate Magento URLs |

---

## 📦 Installation

```bash
composer require angeo/module-aeo-audit
bin/magento setup:upgrade
bin/magento cache:flush
```

---

## 🔍 Usage

### Basic audit (all stores)

```bash
bin/magento angeo:aeo:audit
```

### Audit a specific store

```bash
bin/magento angeo:aeo:audit --store=en_us
```

### JSON output (for dashboards / CI)

```bash
bin/magento angeo:aeo:audit --format=json
```

### Markdown report saved to file

```bash
bin/magento angeo:aeo:audit --format=markdown --output=/var/www/html/aeo-report.md
```

### CI pipeline — fail build if score below 70%

```bash
bin/magento angeo:aeo:audit --fail-on=70
```

---

## 📊 Example Output

```
  ╔══════════════════════════════════════════╗
  ║   Angeo AEO Audit — angeo.dev           ║
  ║   AI Engine Optimization for Magento 2  ║
  ╚══════════════════════════════════════════╝

Store: default — https://mystore.com/

+------------------------------------------+--------+-----------------------------------------------+
| Check                                    | Status | Message                                       |
+------------------------------------------+--------+-----------------------------------------------+
| robots.txt — AI Bot Access               | ✓ PASS | All 7 AI bots are permitted in robots.txt.    |
| llms.txt — AI Content Map                | ✗ FAIL | llms.txt not found.                           |
| sitemap.xml — Search Engine Discovery    | ✓ PASS | sitemap.xml found (1,243 URLs).               |
| Product Schema — JSON-LD Structured Data | ✓ PASS | Product JSON-LD schema found.                 |
| FAQPage Schema — AI Answer Eligibility   | ⚠ WARN | No FAQPage schema on homepage.                |
| AI Product Feed — ChatGPT/Gemini         | ✗ FAIL | No AI-readable product feed found.            |
| Open Graph — Social & AI Preview Tags    | ✓ PASS | All required Open Graph tags found.           |
| Canonical Tags — Duplicate Content       | ✓ PASS | Canonical tag found on homepage.              |
+------------------------------------------+--------+-----------------------------------------------+

  AEO Score: [██████████░░░░░░░░░░] 50% — Needs Improvement
  ✓ Pass: 5  ⚠ Warn: 1  ✗ Fail: 2

  Critical fixes needed:
  → Install angeo/module-llms-txt and generate your llms.txt
  → Install angeo/module-openai-product-feed and run: bin/magento angeo:product-feed:generate

  💡 Fix issues with angeo modules:
     composer require angeo/module-llms-txt
     composer require angeo/module-openai-product-feed
```

---

## 🧪 Running Tests

```bash
vendor/bin/phpunit app/code/Angeo/AeoAudit/Test/Unit
```

---

## 🔗 The Angeo AI Suite for Magento 2

This module is part of the **Angeo AI Commerce Suite**:

| Module | Purpose |
|--------|---------|
| [`angeo/module-aeo-audit`](https://packagist.org/packages/angeo/module-aeo-audit) | **This module** — audit your AI readiness |
| [`angeo/module-llms-txt`](https://packagist.org/packages/angeo/module-llms-txt) | Generate llms.txt for ChatGPT, Claude, Gemini |
| [`angeo/module-openai-product-feed`](https://packagist.org/packages/angeo/module-openai-product-feed) | AI product feed for ChatGPT Shopping |
| [`angeo/module-openai-instant-checkout`](https://packagist.org/packages/angeo/module-openai-instant-checkout) | ChatGPT Agentic Commerce / Instant Checkout |

---

## 🤝 Contributing

Found a bug or want to add a new check? Contributions are very welcome!

Check out the open issues or create a new one. Contact: [info@angeo.dev](mailto:info@angeo.dev)

---

## ☕ Support

If this module saves you time, consider [buying me a coffee](https://buymeacoffee.com/angeo). Your support keeps open-source Magento AI tooling alive. 🙏

---

## 📄 License

MIT License — see [LICENSE](LICENSE)

**Keywords:** Magento 2 AEO audit, AI engine optimization, ChatGPT SEO Magento, Gemini indexing, llms.txt checker, robots.txt AI bots, structured data audit, Magento 2 AI module, angeo
