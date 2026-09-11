<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Checker;

use Angeo\AeoAudit\Model\Report\CheckResult;
use Angeo\AeoAudit\Service\BotRegistry;
use Angeo\AeoAudit\Service\HttpCache;
use Angeo\AeoAudit\Service\RobotsTxtParser;
use Angeo\AeoAudit\Service\StoreUrlSampler;
use Magento\Store\Api\Data\StoreInterface;

/**
 * Edge vs robots.txt consistency — "is your robots.txt lying?"
 *
 * WAF/CDN rules execute BEFORE robots.txt is ever read, so a Cloudflare
 * managed rule silently overrides any Allow the merchant wrote. The classic
 * failure mode: robots.txt invites GPTBot in, the edge returns 403, and the
 * merchant spends months wondering why AI answers never cite them.
 *
 * Method: for each probe bot the parsed robots.txt declares ALLOWED, fetch
 * the homepage presenting that bot's User-Agent and compare against a
 * baseline fetch with the audit UA. An edge block (403/406/429/503, or a
 * challenge page fingerprint) on an allowed bot is a declaration/behaviour
 * mismatch.
 *
 * Honest limitation, encoded in the grading: this probe spoofs the UA from
 * the store's own server. Edges running verified-bot programs (Cloudflare
 * Verified Bots, Fastly) validate the SOURCE of the request — they may
 * block a UA-spoofer while admitting the genuine crawler from vendor IP
 * ranges. A mismatch here is therefore a WARN with instructions to confirm
 * via the live-activity signal, never an automatic FAIL. The two signals
 * corroborate: "edge blocks the UA" + "zero hits in the activity log" is
 * a real block; "edge blocks the UA" + "healthy hit counts" is a
 * verified-bot program doing its job.
 *
 * Cost: 1 + N cached fetches (N = probe bots, currently 4).
 *
 * @since 4.0.0
 */
class WafRealityChecker extends AbstractChecker
{
    /** Bot codes (BotRegistry) probed against the edge. Search-class first — they matter most. */
    private const PROBE_BOTS = ['oai_searchbot', 'perplexitybot', 'gptbot', 'claudebot'];

    /** Realistic full UA strings per probe bot — bare tokens are themselves a WAF trigger. */
    private const PROBE_USER_AGENTS = [
        'oai_searchbot' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; OAI-SearchBot/1.0; +https://openai.com/searchbot',
        'perplexitybot' => 'Mozilla/5.0 (compatible; PerplexityBot/1.0; +https://perplexity.ai/perplexitybot)',
        'gptbot'        => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.2; +https://openai.com/gptbot',
        'claudebot'     => 'Mozilla/5.0 (compatible; ClaudeBot/1.0; +claudebot@anthropic.com)',
    ];

    /** HTTP statuses that read as an edge/WAF denial rather than an app error. */
    private const BLOCK_STATUSES = [401, 403, 406, 429, 503];

    /** Body fingerprints of challenge/deny pages (checked case-insensitively). */
    private const CHALLENGE_MARKERS = [
        'just a moment',           // Cloudflare JS challenge
        'attention required',      // Cloudflare block page
        'cf-error-details',
        'ddos protection by',
        'request unsuccessful. incapsula',
        'perimeterx',
        'px-captcha',
        'akamai reference',
    ];

    public function __construct(
        HttpCache                        $httpCache,
        StoreUrlSampler                  $urlSampler,
        private readonly BotRegistry     $botRegistry,
        private readonly RobotsTxtParser $robotsParser,
    ) {
        parent::__construct($httpCache, $urlSampler);
    }

    public function getName(): string
    {
        return 'Edge vs robots.txt consistency (WAF reality)';
    }

    public function getCode(): string
    {
        return 'waf_reality';
    }

    public function getWeight(): float
    {
        return 0.9;
    }

    public function check(StoreInterface $store): CheckResult
    {
        $base = $this->normalizeBase($this->urlSampler->getBaseUrl($store));

        // Baseline with the audit UA — if the site is down for us too,
        // per-bot comparisons are meaningless.
        [$baseStatus, $baseBody] = $this->fetch($base . '/');
        if ($baseStatus === 0) {
            return $this->warn(
                'Store homepage was unreachable during the probe — edge consistency not evaluated.',
                'Re-run the audit; if this persists, the audit host cannot reach the store URL.',
                ['baseline_status' => $baseStatus]
            );
        }
        if ($this->looksBlocked($baseStatus, $baseBody)) {
            return $this->warn(
                sprintf('Baseline request (audit UA) was itself blocked (HTTP %d) — the edge challenges ALL bots.', $baseStatus),
                'An edge that challenges every non-browser agent also blocks AI crawlers. '
                . 'Allow-list verified AI crawlers in your WAF/CDN bot management.',
                ['baseline_status' => $baseStatus]
            );
        }

        [$robotsStatus, $robotsBody] = $this->fetch($base . '/robots.txt');
        $rules = $robotsStatus === 200 ? $this->robotsParser->parse($robotsBody) : [];

        $mismatches   = [];
        $consistent   = [];
        $declaredOff  = [];

        foreach (self::PROBE_BOTS as $botCode) {
            $bot = $this->botRegistry->get($botCode);
            if ($bot === null) {
                continue;
            }

            $allowedByRobots = $rules === []
                ? true // no robots.txt = everything implicitly allowed
                : !$this->robotsParser->isBotBlocked($bot['robots_token'], $rules);

            if (!$allowedByRobots) {
                // Merchant explicitly opted this bot out — edge blocking it is consistent policy.
                $declaredOff[] = $bot['robots_token'];
                continue;
            }

            [$status, $body] = $this->httpCache->getAs(
                $base . '/',
                self::PROBE_USER_AGENTS[$botCode] ?? $bot['ua_token']
            );

            if ($this->looksBlocked($status, $body)) {
                $mismatches[$bot['robots_token']] = $status;
            } else {
                $consistent[] = $bot['robots_token'];
            }
        }

        $details = [
            'baseline_status' => $baseStatus,
            'robots_status'   => $robotsStatus,
            'mismatches'      => $mismatches,
            'consistent'      => $consistent,
            'robots_opted_out' => $declaredOff,
            'method_note'     => 'UA-spoof probe from the audit host. Verified-bot edges may admit '
                . 'genuine crawlers (validated by source IP) while blocking this probe — '
                . 'cross-check the "AI crawler activity" signal before acting.',
        ];

        if ($mismatches !== []) {
            $list = implode(', ', array_map(
                static fn(string $bot, int $status) => sprintf('%s→HTTP %d', $bot, $status),
                array_keys($mismatches),
                $mismatches
            ));

            return $this->warn(
                sprintf(
                    'robots.txt allows %d bot(s) that the edge appears to block: %s.',
                    count($mismatches),
                    $list
                ),
                'Your WAF/CDN executes before robots.txt is read, so these Allow rules have no effect. '
                . 'If the "AI crawler activity" signal also shows silence for these bots, allow-list them '
                . 'in your edge bot management (e.g. Cloudflare AI Crawl Control). If activity is healthy, '
                . 'your edge runs a verified-bot program and correctly rejected the spoofed probe — no action needed.',
                $details
            );
        }

        return $this->pass(
            sprintf(
                'Edge behaviour matches robots.txt for all %d probed bot(s)%s.',
                count($consistent),
                $declaredOff !== [] ? sprintf(' (%d deliberately opted out)', count($declaredOff)) : ''
            ),
            $details
        );
    }

    private function looksBlocked(int $status, string $body): bool
    {
        if (in_array($status, self::BLOCK_STATUSES, true)) {
            return true;
        }
        if ($status !== 200 || $body === '') {
            return false;
        }
        $haystack = strtolower(substr($body, 0, 20000));
        foreach (self::CHALLENGE_MARKERS as $marker) {
            if (str_contains($haystack, $marker)) {
                return true;
            }
        }
        return false;
    }
}
