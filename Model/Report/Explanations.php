<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Report;

/**
 * "What is this / why it matters / how to fix" for every signal.
 *
 * Shown under each finding on the result page, with no AI and no network.
 * Written in plain English (B1–B2) and passed through __() so they can be
 * translated with a language pack. A unit test checks that every checker
 * registered in etc/di.xml has an entry here.
 *
 * Checkers from other modules can add their own text with a plugin on
 * get(), or leave it: the page then shows only the checker's own message.
 *
 * @api
 * @since 4.5.0
 */
class Explanations
{
    /**
     * @return array{what:string,why:string,how:string}|null
     */
    public function get(string $code): ?array
    {
        $all = $this->all();

        return $all[$code] ?? null;
    }

    /**
     * Terms used on the page, for the legend and for the AI assistant.
     *
     * @return array<string,string>
     */
    public function glossary(): array
    {
        return [
            (string) __('Readiness score') => (string) __('What you control: how well AI systems can find, read and trust the store. 0–100, weighted by signal.'),
            (string) __('Measured visibility') => (string) __('The outcome: how often AI assistants name the store. Shown apart from readiness because it moves slower and has a margin of error.'),
            (string) __('Weight') => (string) __('How much a signal counts in the score. Critical signals weigh 0.8–1.0, standard ones 0.5.'),
            (string) __('Points if fixed') => (string) __('How much the readiness score would rise if this signal passed. A warning earns half its weight back, a failure all of it.'),
            (string) __('Not applicable') => (string) __('The signal does not apply to this store (for example an A2A card when no A2A transport is offered). It is left out of the score.'),
        ];
    }

    /**
     * @return array<string,array{what:string,why:string,how:string}>
     */
    public function all(): array
    {
        $e = static fn (string $what, string $why, string $how): array => [
            'what' => $what,
            'why'  => $why,
            'how'  => $how,
        ];

        return [
            'robots_txt' => $e(
                (string) __('robots.txt tells crawlers what they may read. This check looks at AI bots by type: search bots (they index for AI answers), fetchers (they open a page when a user asks), and training bots.'),
                (string) __('A blocked AI search bot means that assistant cannot cite your store. Blocking training bots is a business choice: it is reported, not counted against you.'),
                (string) __('Allow OAI-SearchBot, Claude-SearchBot and PerplexityBot before any wildcard Disallow. angeo/module-robots-txt-aeo writes these rules for you.')
            ),
            'llms_txt' => $e(
                (string) __('A Markdown file at /llms.txt that says what the store sells and where the important pages are.'),
                (string) __('Some AI tools read it to find the right pages fast. Google Search does not use it, so it weighs less than crawler access or product data.'),
                (string) __('composer require angeo/module-llms-txt, then run bin/magento angeo:llms:generate.')
            ),
            'llms_jsonl' => $e(
                (string) __('/llms.jsonl lists the catalogue as JSON, one product per line.'),
                (string) __('An agent can read products, prices and stock without parsing HTML pages.'),
                (string) __('angeo/module-llms-txt writes it together with llms.txt.')
            ),
            'link_relations' => $e(
                (string) __('Two links in the head of a product page, from llms.txt v2: rel="alternate" to a Markdown copy of the page, and rel="describedby" to the llms.txt that covers it.'),
                (string) __('Agents find the clean version of a page without guessing URLs.'),
                (string) __('angeo/module-llms-txt 4.x adds both links and serves the Markdown copies.')
            ),
            'agents_md' => $e(
                (string) __('/agents.md: short instructions for AI agents with your delivery, returns and privacy terms, and links to the policy pages.'),
                (string) __('An agent that recommends your products can quote your real terms instead of guessing them.'),
                (string) __('With angeo/module-llms-txt: Formats → Generate agents.md, fill in the content, then run bin/magento angeo:llms:generate.')
            ),
            'sitemap' => $e(
                (string) __('sitemap.xml lists the pages of the store.'),
                (string) __('Crawlers find deep catalogue pages faster than by following links.'),
                (string) __('Marketing → SEO & Search → Site Map. Add a "Sitemap:" line with its URL to robots.txt.')
            ),
            'product_schema' => $e(
                (string) __('Product data in JSON-LD on product pages: name, price, currency and stock (offers.availability). Configurable products may use ProductGroup with variants.'),
                (string) __('Shopping assistants read price and stock from it. Without it they guess from the page, or skip the product.'),
                (string) __('angeo/module-rich-data adds complete Product and ProductGroup markup. Default Magento leaves out availability.')
            ),
            'merchant_policies' => $e(
                (string) __('Return policy and shipping details inside the product offer (hasMerchantReturnPolicy, shippingDetails).'),
                (string) __('An agent needs both to quote a total price and a return window before it recommends a purchase.'),
                (string) __('angeo/module-rich-data adds both from your store settings.')
            ),
            'organization_schema' => $e(
                (string) __('Who sells: the store name, logo, URL and contact in JSON-LD.'),
                (string) __('AI answers attach a recommendation to a named merchant. Without this, the store has no clear identity.'),
                (string) __('angeo/module-rich-data adds Organization markup on the homepage.')
            ),
            'ucp_profile' => $e(
                (string) __('/.well-known/ucp is the Universal Commerce Protocol profile. It tells shopping agents what the store supports and where its endpoints are.'),
                (string) __('Agents that speak UCP look there first to find the catalogue and checkout.'),
                (string) __('composer require angeo/module-ucp. The current revision is 2026-08-25.')
            ),
            'ai_product_feed' => $e(
                (string) __('A structured product feed for AI shopping surfaces, as a file or a REST endpoint.'),
                (string) __('Merchant programmes and agents read a feed instead of crawling every page.'),
                (string) __('angeo/module-openai-product-feed and module-openai-product-feed-api. Then submit the feed to each programme you want to appear in.')
            ),
            'jsonld_quality' => $e(
                (string) __('Whether the JSON-LD is well formed: @context set, nodes linked with @id, no duplicate Product or Organization nodes.'),
                (string) __('Connected data is easier for AI systems to read and to trust. Duplicates make them guess which node is right.'),
                (string) __('Put the nodes in one @graph with @id links. angeo/module-rich-data does this.')
            ),
            'canonical' => $e(
                (string) __('rel="canonical" names the main URL of a page; hreflang links the language versions.'),
                (string) __('Filters and sort orders create many URLs for one product. Without a canonical they compete with each other.'),
                (string) __('Stores → Configuration → Catalog → Catalog → Search Engine Optimization → Use Canonical Link Meta Tag for Products = Yes.')
            ),
            'open_graph' => $e(
                (string) __('og: meta tags (title, description, image, url) on product pages.'),
                (string) __('Link previews and some AI summaries use them to describe the page.'),
                (string) __('angeo/module-rich-data adds them, or your theme may have a setting.')
            ),
            'faq_schema' => $e(
                (string) __('FAQPage JSON-LD on pages that have questions and answers.'),
                (string) __('Question-and-answer text is easy for assistants to quote word for word.'),
                (string) __('Add FAQ markup with angeo/module-rich-data or your CMS FAQ extension.')
            ),
            'well_known' => $e(
                (string) __('Two discovery files under /.well-known/: ucp and security.txt (RFC 9116).'),
                (string) __('Agents and security tools look there first. A missing file is a warning, never a failure.'),
                (string) __('angeo/module-ucp for ucp; write security.txt by hand.')
            ),
            'agent_discovery' => $e(
                (string) __('An AI Catalog (/.well-known/ai-catalog.json or a rel="ai-catalog" link) and the MCP Server Card it lists.'),
                (string) __('They tell an agent which endpoints the store has and how to connect. Both are drafts, so this signal is informational and never changes the score. A broken file is still a warning.'),
                (string) __('angeo/module-llms-txt 5.1 publishes the catalog; angeo/module-mcp-server 2.5 adds its Server Card to it.')
            ),
            'agent_card' => $e(
                (string) __('An A2A agent card at /.well-known/agent-card.json.'),
                (string) __('It is only needed when the UCP profile offers an a2a transport. Otherwise the signal is "not applicable" and left out of the score.'),
                (string) __('Publish the card, or remove the a2a transport from the UCP profile.')
            ),
            'core_web_vitals' => $e(
                (string) __('Real-user page speed from Google\'s Chrome UX Report: LCP, INP and CLS.'),
                (string) __('Slow pages are crawled less and convert worse.'),
                (string) __('Add a CrUX API key in the AEO Audit settings. Small stores often have no CrUX data; then the signal is a warning, not a failure.')
            ),
            'waf_reality' => $e(
                (string) __('Compares what robots.txt allows with what the firewall or CDN really does to AI bots.'),
                (string) __('A bot that robots.txt allows but the edge blocks (HTTP 403 or 429) still cannot read the store. This is a common, hidden problem.'),
                (string) __('Allow the verified AI search bots in the WAF or CDN bot settings (for example Cloudflare AI Crawl Control, or your host\'s firewall).')
            ),
            'ai_crawler_activity' => $e(
                (string) __('How often AI crawlers really visited, from the store\'s own request records.'),
                (string) __('It shows whether the bots come at all, and which ones. Allowed is not the same as visited.'),
                (string) __('Give it a few days of data. If an AI search bot never visits, check robots.txt and the WAF first.')
            ),
            'brand_visibility' => $e(
                (string) __('Measured, not configured: how often AI assistants name the brand when asked about its category.'),
                (string) __('Readiness is what you control; visibility is the outcome. They move at different speeds, so they are shown apart.'),
                (string) __('Open the Brand Visibility page for the answers per assistant and the share of voice against competitors.')
            ),
        ];
    }
}
