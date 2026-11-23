<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Checker;

use Angeo\AeoAudit\Model\Report\CheckResult;
use Magento\Store\Api\Data\StoreInterface;

/**
 * WebMCP — tools the store registers in the shopper's browser.
 *
 * WebMCP lets a page hand an in-browser AI agent named tools (search, add to
 * cart, go to checkout) instead of making it click through the page. OpenAI's
 * Commerce Partner Playbook (November 2026) lists it as one of the ways a
 * store can work with ChatGPT agents.
 *
 * What this signal looks for in the home page HTML:
 *  - angeo/module-webmcp: the `angeo-webmcp-config` JSON block and the tools
 *    it enables;
 *  - any other implementation: a script that calls `modelContext.registerTool`,
 *    or a form with a declarative `toolname` attribute;
 *  - a Chrome origin trial token (`<meta http-equiv="origin-trial">`). WebMCP
 *    is a Chrome origin trial, so without a token only users who switched on
 *    the flag get the tools. The token's expiry date is read and reported.
 *
 * INFORMATIONAL. Weight 0: shown, never counted in the score. WebMCP is a W3C
 * Community Group draft, not a standard, and OpenAI calls it optional. A
 * store must not lose points for not using it. An expired token is still a
 * warning, because then the store believes the tools work when they do not.
 *
 * Not checked: whether the tools really register at runtime. That needs a
 * browser with WebMCP turned on, which this audit does not run.
 *
 * @since 4.6.0
 */
class WebMcpChecker extends AbstractChecker
{
    public const ANGEO_CONFIG_ID = 'angeo-webmcp-config';

    /** Days before expiry at which an origin trial token is reported as running out. */
    private const TOKEN_EXPIRY_WARN_DAYS = 14;

    public function getName(): string
    {
        return 'WebMCP — tools for in-browser AI agents';
    }

    public function getCode(): string
    {
        return 'webmcp';
    }

    public function getWeight(): float
    {
        return 0.0;
    }

    public function getFixCommand(): string
    {
        return 'composer require angeo/module-webmcp';
    }

    public function check(StoreInterface $store): CheckResult
    {
        $base = $this->normalizeBase($this->urlSampler->getBaseUrl($store));
        [$status, $html] = $this->fetch($base . '/');
        $details = ['url' => $base . '/', 'informational' => true];

        if ($status !== 200 || $html === '') {
            return $this->pass(
                sprintf('Home page not readable (HTTP %s), WebMCP not checked — informational only.', $status ?: 'error'),
                $details + ['status' => $status]
            );
        }

        $found = self::detect($html);
        $details += $found;

        $token = $found['origin_trial'];
        if ($token !== null && $token['expires_at'] !== null) {
            $daysLeft = (int) floor(($token['expires_at'] - time()) / 86400);
            $details['origin_trial']['days_left'] = $daysLeft;
            if ($daysLeft < 0) {
                return $this->warn(
                    sprintf('The WebMCP origin trial token expired on %s. Chrome ignores it, so the tools are off for most users.', gmdate('Y-m-d', $token['expires_at'])),
                    'Register for a new token in the Chrome origin trials console and paste it into the '
                    . 'angeo/module-webmcp settings in the admin. Informational: the score does not change.',
                    $details
                );
            }
        }

        if (!$found['has_tools']) {
            return $this->pass(
                'No WebMCP tools on the home page. WebMCP is optional and still an origin trial — informational only.',
                $details + ['note' => 'OpenAI lists WebMCP tools as a way to work with ChatGPT agents. '
                    . 'angeo/module-webmcp adds search, product, cart and checkout tools for Luma and Hyvä.']
            );
        }

        $source = $found['angeo_module'] ? 'angeo/module-webmcp' : 'a custom implementation';
        $tools = $found['tools'] !== [] ? sprintf(' (%s)', implode(', ', $found['tools'])) : '';

        if ($token === null) {
            return $this->pass(
                sprintf('WebMCP tools are registered by %s%s, but there is no origin trial token — only Chrome users with the WebMCP flag get them.', $source, $tools),
                $details + ['note' => 'Add an origin trial token so every Chrome user in the trial gets the tools.']
            );
        }

        if (isset($details['origin_trial']['days_left'])
            && $details['origin_trial']['days_left'] <= self::TOKEN_EXPIRY_WARN_DAYS) {
            return $this->warn(
                sprintf('WebMCP tools are registered by %s%s, but the origin trial token runs out in %d day(s).', $source, $tools, $details['origin_trial']['days_left']),
                'Renew the token in the Chrome origin trials console before it expires.',
                $details
            );
        }

        return $this->pass(
            sprintf('WebMCP tools are registered by %s%s, with an origin trial token.', $source, $tools),
            $details
        );
    }

    // ── Pure helpers (unit-tested without HTTP) ──────────────────────

    /**
     * @return array{
     *     has_tools: bool,
     *     angeo_module: bool,
     *     tools: list<string>,
     *     register_tool_script: bool,
     *     declarative_forms: int,
     *     origin_trial: array{feature: ?string, expires_at: ?int}|null
     * }
     */
    public static function detect(string $html): array
    {
        $angeo = false;
        $tools = [];
        $pattern = '#<script[^>]*\bid=["\']' . preg_quote(self::ANGEO_CONFIG_ID, '#') . '["\'][^>]*>(.*?)</script>#is';
        if (preg_match($pattern, $html, $m) === 1) {
            $angeo = true;
            $config = json_decode(html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5), true, 16);
            if (is_array($config) && is_array($config['tools'] ?? null)) {
                foreach ($config['tools'] as $key => $value) {
                    // Either a list of names or a map name => enabled flag.
                    if (is_string($value)) {
                        $tools[] = $value;
                    } elseif (is_string($key) && $value) {
                        $tools[] = $key;
                    }
                }
            }
        }

        $registerTool = preg_match('#modelContext\s*\??\.\s*registerTool\s*\(#', $html) === 1;
        $declarative = preg_match_all('#<form\b[^>]*\btoolname\s*=#i', $html);

        $token = null;
        if (preg_match('#<meta\b[^>]*http-equiv=["\']origin-trial["\'][^>]*>#i', $html, $meta) === 1
            && preg_match('#\bcontent=["\']([^"\']+)["\']#i', $meta[0], $content) === 1) {
            $token = self::decodeOriginTrialToken($content[1]);
        }

        return [
            'has_tools' => ($angeo && ($tools !== [] || !is_array($config ?? null))) || $registerTool || $declarative > 0,
            'angeo_module' => $angeo,
            'tools' => array_values(array_unique($tools)),
            'register_tool_script' => $registerTool,
            'declarative_forms' => (int) $declarative,
            'origin_trial' => $token,
        ];
    }

    /**
     * Reads the feature name and expiry from a Chrome origin trial token.
     *
     * Token layout (base64): 1 byte version, 64 bytes signature, 4 bytes
     * big-endian payload length, then the JSON payload with "origin",
     * "feature" and "expiry" (Unix seconds). A token that does not decode is
     * still reported as present, with unknown feature and expiry.
     *
     * @return array{feature: ?string, expires_at: ?int}
     */
    public static function decodeOriginTrialToken(string $token): array
    {
        $raw = base64_decode(trim($token), true);
        if ($raw === false || strlen($raw) < 69) {
            return ['feature' => null, 'expires_at' => null];
        }
        $length = unpack('N', substr($raw, 65, 4));
        $payload = json_decode(substr($raw, 69, (int) ($length[1] ?? 0)), true, 8);
        if (!is_array($payload)) {
            return ['feature' => null, 'expires_at' => null];
        }

        return [
            'feature' => is_string($payload['feature'] ?? null) ? $payload['feature'] : null,
            'expires_at' => is_int($payload['expiry'] ?? null) ? $payload['expiry'] : null,
        ];
    }
}
