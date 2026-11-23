<?php
/**
 * Copyright (c) 2026 Ievgenii Gryshkun (angeo.dev)
 * MIT License — see LICENSE for full terms.
 */

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Checker;

use Angeo\AeoAudit\Model\Report\CheckResult;
use Angeo\AeoAudit\Service\HttpCache;
use Angeo\AeoAudit\Service\StoreUrlSampler;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Store\Api\Data\StoreInterface;

/**
 * Web Bot Auth — can the store tell a signed ChatGPT agent from a copy?
 *
 * ChatGPT's agents sign their requests with HTTP Message Signatures
 * (RFC 9421): `Signature`, `Signature-Input` and `Signature-Agent:
 * "https://chatgpt.com"`, with public keys at
 * https://chatgpt.com/.well-known/http-message-signatures-directory.
 * OpenAI's Commerce Partner Playbook asks stores to let these signed
 * requests through the CDN or firewall. Akamai, Cloudflare, HUMAN and Vercel
 * check the signature themselves; other edges and the store must do it.
 *
 * What this signal checks, from inside the store:
 *  1. angeo/module-ai-bots is enabled, so the store (and the agent activity
 *     report) can verify a signature instead of trusting the User-Agent.
 *  2. The store host can fetch ChatGPT's key directory. Without the keys no
 *     signature can be verified, even with the module installed.
 *
 * Not checked: the CDN or firewall rule itself. Testing it needs a request
 * signed with OpenAI's private key. The recommendation names the setting to
 * look at instead.
 *
 * INFORMATIONAL. Weight 0: shown, never counted in the score.
 *
 * @since 4.6.0
 */
class WebBotAuthChecker extends AbstractChecker
{
    public const AI_BOTS_MODULE = 'Angeo_AiBots';
    public const CHATGPT_KEY_DIRECTORY = 'https://chatgpt.com/.well-known/http-message-signatures-directory';

    private const EDGE_NOTE = 'Your CDN or firewall must also let signed requests through and keep the Signature, '
        . 'Signature-Input and Signature-Agent headers. Akamai, Cloudflare, HUMAN and Vercel verify ChatGPT\'s '
        . 'signature themselves; on other edges, allow requests whose Signature-Agent is exactly "https://chatgpt.com" '
        . 'after checking the signature.';

    public function __construct(
        HttpCache $httpCache,
        StoreUrlSampler $urlSampler,
        private readonly ModuleManager $moduleManager,
    ) {
        parent::__construct($httpCache, $urlSampler);
    }

    public function getName(): string
    {
        return 'Web Bot Auth — signed AI agents (ChatGPT)';
    }

    public function getCode(): string
    {
        return 'web_bot_auth';
    }

    public function getWeight(): float
    {
        return 0.0;
    }

    public function getFixCommand(): string
    {
        return 'composer require angeo/module-ai-bots';
    }

    public function check(StoreInterface $store): CheckResult
    {
        $details = ['informational' => true, 'key_directory' => self::CHATGPT_KEY_DIRECTORY];

        $moduleOn = $this->moduleManager->isEnabled(self::AI_BOTS_MODULE);
        $details['ai_bots_module'] = $moduleOn;

        if (!$moduleOn) {
            return $this->warn(
                'The store cannot verify Web Bot Auth signatures, so it cannot tell a real ChatGPT agent from a bot that only copies its name. Informational — the score does not change.',
                'Install angeo/module-ai-bots: it checks the signature of ChatGPT, Google and other signed agents, '
                . 'and the agent activity report then counts only verified visits. ' . self::EDGE_NOTE,
                $details
            );
        }

        [$status, $body] = $this->fetch(self::CHATGPT_KEY_DIRECTORY);
        $keys = self::countKeys($status === 200 ? $body : '');
        $details['key_directory_status'] = $status;
        $details['key_directory_keys'] = $keys;

        if ($status !== 200 || $keys === 0) {
            return $this->warn(
                sprintf(
                    'angeo/module-ai-bots is enabled, but this server cannot read ChatGPT\'s public keys (HTTP %s, %d key(s)), so signatures cannot be verified.',
                    $status ?: 'error',
                    $keys
                ),
                'Allow outgoing HTTPS from the web server to chatgpt.com (proxy or egress firewall). '
                . self::EDGE_NOTE,
                $details
            );
        }

        return $this->pass(
            sprintf('The store can verify Web Bot Auth signatures: angeo/module-ai-bots is on and ChatGPT\'s key directory is readable (%d key(s)).', $keys),
            $details + ['note' => self::EDGE_NOTE]
        );
    }

    /**
     * Number of keys in an HTTP Message Signatures directory (a JWK Set).
     */
    public static function countKeys(string $body): int
    {
        $data = json_decode($body, true, 16);
        if (!is_array($data) || !is_array($data['keys'] ?? null)) {
            return 0;
        }

        return count(array_filter($data['keys'], static fn ($k): bool => is_array($k) && isset($k['kty'])));
    }
}
