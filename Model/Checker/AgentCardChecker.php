<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Checker;

use Angeo\AeoAudit\Model\Report\CheckResult;
use Magento\Store\Api\Data\StoreInterface;

/**
 * Validates the A2A Agent Card at /.well-known/agent-card.json.
 *
 * A2A (Agent2Agent) reached 1.0.0 under Linux Foundation governance, and UCP
 * 2026-04-08 lists `a2a` alongside `rest`, `mcp` and `embedded` as a transport
 * a business may advertise — pointing at /.well-known/agent-card.json. A store
 * that declares the a2a transport in its UCP profile and does not serve a
 * readable card at the canonical path has advertised a door with nothing
 * behind it.
 *
 * The single most common real-world defect this catches is the path
 * migration: before A2A 0.3 the card lived at /.well-known/agent.json, and a
 * substantial share of published cards are still there — where a spec-compliant
 * 1.0.0 client never looks. Those operators believe they have published a card
 * that, as far as the protocol is concerned, does not exist.
 *
 * Severity is conditional, which is the point of this checker:
 *
 *  - UCP profile declares an `a2a` transport → the card is REQUIRED.
 *    Missing, or served only at the legacy path → FAIL.
 *  - No a2a transport declared → the card is optional. Its absence is
 *    informational (PASS with a note); a card at the legacy path is still a
 *    WARN, because it is a live misconfiguration either way.
 *
 * This keeps the signal from penalising the overwhelming majority of Magento
 * stores that have never opted into A2A, while making it strict for the ones
 * that have.
 *
 * @since 4.1.0
 */
class AgentCardChecker extends AbstractChecker
{
    /** Canonical path since A2A 0.3. A 1.0.0 client looks here and nowhere else. */
    public const CANONICAL_PATH = '/.well-known/agent-card.json';

    /** Pre-0.3 path. Still widely published, never read by current clients. */
    public const LEGACY_PATH = '/.well-known/agent.json';

    /** UCP profile, read to decide whether the card is required or optional. */
    private const UCP_PATHS = ['/.well-known/ucp', '/.well-known/ucp.json'];

    /** Fields A2A 1.0.0 requires on a card for it to be consumable. */
    private const REQUIRED_FIELDS = ['name', 'url', 'version'];

    /** Fields whose absence makes the card discoverable but not useful. */
    private const EXPECTED_FIELDS = ['description', 'capabilities', 'skills'];

    public function getName(): string
    {
        return 'A2A Agent Card — /.well-known/agent-card.json';
    }

    public function getCode(): string
    {
        return 'agent_card';
    }

    public function getWeight(): float
    {
        return 0.6;
    }

    public function getFixCommand(): string
    {
        return '';
    }

    public function check(StoreInterface $store): CheckResult
    {
        $base = $this->normalizeBase($this->urlSampler->getBaseUrl($store));

        $a2aDeclared = $this->ucpDeclaresA2a($base);

        [$canonicalStatus, $canonicalBody] = $this->fetch($base . self::CANONICAL_PATH);
        $legacyStatus = $this->statusCode($base . self::LEGACY_PATH);

        $details = [
            'base_url' => $base,
            'canonical_path' => self::CANONICAL_PATH,
            'canonical_status' => $canonicalStatus,
            'legacy_path' => self::LEGACY_PATH,
            'legacy_status' => $legacyStatus,
            'ucp_declares_a2a' => $a2aDeclared,
        ];

        // ── Card at the canonical path ───────────────────────────────
        if ($canonicalStatus === 200) {
            $card = json_decode($canonicalBody, true);

            if (!is_array($card)) {
                return $this->fail(
                    'Agent card at the canonical path is not valid JSON.',
                    'Serve a parseable JSON document at ' . self::CANONICAL_PATH
                        . '; an A2A client discards anything it cannot decode.',
                    $details
                );
            }

            $missingRequired = $this->missingKeys($card, self::REQUIRED_FIELDS);
            $missingExpected = $this->missingKeys($card, self::EXPECTED_FIELDS);

            $details['declared_name'] = is_string($card['name'] ?? null) ? $card['name'] : null;
            $details['declared_version'] = is_string($card['version'] ?? null) ? $card['version'] : null;
            $details['missing_required'] = $missingRequired;
            $details['missing_expected'] = $missingExpected;

            if ($missingRequired !== []) {
                return $this->fail(
                    sprintf(
                        'Agent card is served but incomplete — missing %s.',
                        implode(', ', $missingRequired)
                    ),
                    'A2A 1.0.0 requires name, url and version on every card. '
                        . 'A card missing any of them cannot be used to address the agent.',
                    $details
                );
            }

            if ($legacyStatus === 200) {
                $details['note'] = 'Card served at both paths.';

                return $this->warn(
                    'Agent card is correct at the canonical path, and a copy is still served at the legacy path.',
                    'Retire ' . self::LEGACY_PATH . ' or redirect it to '
                        . self::CANONICAL_PATH . ' so the two cannot drift apart.',
                    $details
                );
            }

            if ($missingExpected !== []) {
                return $this->warn(
                    sprintf(
                        'Agent card is discoverable but thin — missing %s.',
                        implode(', ', $missingExpected)
                    ),
                    'Add description, capabilities and skills so a calling agent can decide '
                        . 'whether this agent is relevant before invoking it.',
                    $details
                );
            }

            return $this->pass('Agent card served at the canonical path and structurally complete.', $details);
        }

        // ── Card only at the legacy path ─────────────────────────────
        if ($legacyStatus === 200) {
            $recommendation = 'Move the card to ' . self::CANONICAL_PATH
                . ' (or serve it at both). A2A clients from 0.3 onward do not read '
                . self::LEGACY_PATH . ', so this card is currently invisible to them.';

            return $a2aDeclared
                ? $this->fail(
                    'Agent card is published at the pre-0.3 path only, and the UCP profile declares an a2a transport.',
                    $recommendation,
                    $details
                )
                : $this->warn(
                    'Agent card is published at the pre-0.3 path only, where current A2A clients never look.',
                    $recommendation,
                    $details
                );
        }

        // ── No card anywhere ─────────────────────────────────────────
        if ($a2aDeclared) {
            return $this->fail(
                'UCP profile declares an a2a transport, but no agent card is served.',
                'Publish an A2A agent card at ' . self::CANONICAL_PATH
                    . ', or remove the a2a transport from /.well-known/ucp. '
                    . 'An advertised transport with no card behind it fails discovery.',
                $details
            );
        }

        return $this->pass(
            'No A2A agent card — not required, since the store advertises no a2a transport.',
            $details + ['applicable' => false]
        );
    }

    /**
     * True when the UCP profile advertises an `a2a` transport, which is what
     * makes the agent card mandatory rather than optional.
     */
    private function ucpDeclaresA2a(string $base): bool
    {
        foreach (self::UCP_PATHS as $path) {
            [$status, $body] = $this->fetch($base . $path);
            if ($status !== 200) {
                continue;
            }

            $profile = json_decode($body, true);
            if (!is_array($profile)) {
                continue;
            }

            if ($this->containsA2aTransport($profile)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Walks the profile looking for a transport declaration of "a2a". The
     * services block nests differently across UCP revisions, so this searches
     * structurally rather than assuming one shape.
     *
     * @param array<mixed> $node
     */
    private function containsA2aTransport(array $node): bool
    {
        foreach ($node as $key => $value) {
            if ($key === 'transport' && is_string($value) && strtolower($value) === 'a2a') {
                return true;
            }
            if (is_array($value) && $this->containsA2aTransport($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $card
     * @param list<string> $keys
     * @return list<string>
     */
    private function missingKeys(array $card, array $keys): array
    {
        $missing = [];
        foreach ($keys as $key) {
            $value = $card[$key] ?? null;
            if ($value === null || $value === '' || $value === []) {
                $missing[] = $key;
            }
        }

        return $missing;
    }
}
