<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Checker;

use Angeo\AeoAudit\Model\Report\CheckResult;
use Magento\Store\Api\Data\StoreInterface;

/**
 * Validates the llms.txt v2 link relations on storefront pages.
 *
 * The August 2026 revision of llmstxt.org added the piece that was missing
 * from v1: given a page, how does an agent find the markdown version of it and
 * the llms.txt that covers it, without guessing URLs? Two relations answer
 * that — `rel="alternate" type="text/markdown"` pointing at the markdown twin,
 * and `rel="describedby"` pointing at llms.txt. Either the HTML <head> or an
 * HTTP `Link:` header is acceptable; this checker accepts both.
 *
 * v2 also widened the markdown URL form. v1 specified appending to the full
 * page URL (`page.html.md`); v2 also allows replacing the extension
 * (`page.md`). A store that serves only one of them is still compliant, so a
 * missing second form is informational, not a failure.
 *
 * This signal is deliberately independent of any particular module: a store
 * that hand-writes the tags and serves markdown from nginx passes exactly the
 * same way as one running angeo/module-llms-txt.
 *
 * @since 4.2.0
 */
class LinkRelationsChecker extends AbstractChecker
{
    private const REL_MARKDOWN = 'alternate';
    private const REL_LLMS_TXT = 'describedby';

    public function getName(): string
    {
        return 'llms.txt v2 link relations';
    }

    public function getCode(): string
    {
        return 'link_relations';
    }

    public function getWeight(): float
    {
        return 0.7;
    }

    public function getFixCommand(): string
    {
        return 'composer require angeo/module-llms-txt';
    }

    public function check(StoreInterface $store): CheckResult
    {
        $base       = $this->normalizeBase($this->urlSampler->getBaseUrl($store));
        $productUrl = $this->urlSampler->getSampleProductUrl($store);

        if ($productUrl === null) {
            return $this->warn(
                'No sample product URL available — cannot inspect link relations.',
                'The store has no visible, enabled product with a URL rewrite. '
                . 'Add one, or ignore this signal on a catalogue-less store.',
                ['base_url' => $base]
            );
        }

        [$status, $html, $headers] = $this->fetchWithHeaders($productUrl);

        if ($status !== 200 || $html === '') {
            return $this->warn(
                sprintf('Sample product page returned HTTP %s — link relations not verified.', $status ?: 'error'),
                'Check that the sampled product page is reachable.',
                ['url' => $productUrl, 'status' => $status]
            );
        }

        $mdHref   = $this->findRelHref($html, $headers, self::REL_MARKDOWN, true);
        $llmsHref = $this->findRelHref($html, $headers, self::REL_LLMS_TXT, false);

        $issues   = [];
        $warnings = [];

        if ($mdHref === null) {
            $issues[] = 'No rel="alternate" type="text/markdown" — agents cannot discover the markdown version of this page';
        }
        if ($llmsHref === null) {
            $issues[] = 'No rel="describedby" — the page does not point at the llms.txt that covers it';
        }

        // Both relations may be present but point at something that 404s,
        // which is worse than declaring nothing: the agent follows and fails.
        $mdStatus   = $mdHref !== null ? $this->statusCode($this->absolutize($mdHref, $base)) : null;
        $llmsStatus = $llmsHref !== null ? $this->statusCode($this->absolutize($llmsHref, $base)) : null;

        if ($mdHref !== null && $mdStatus !== 200) {
            $issues[] = sprintf('rel="alternate" points at %s which returns HTTP %s', $mdHref, $mdStatus);
        }
        if ($llmsHref !== null && $llmsStatus !== 200) {
            $issues[] = sprintf('rel="describedby" points at %s which returns HTTP %s', $llmsHref, $llmsStatus);
        }

        // The Link: header form matters for the markdown resource itself —
        // it is not HTML, so it has no <head> to carry the relation.
        $headerOnMirror = null;
        if ($mdHref !== null && $mdStatus === 200) {
            [, , $mirrorHeaders] = $this->fetchWithHeaders($this->absolutize($mdHref, $base));
            $linkHeader = $mirrorHeaders['link'] ?? '';
            $headerOnMirror = $linkHeader !== '' && str_contains($linkHeader, self::REL_LLMS_TXT);
            if (!$headerOnMirror) {
                $warnings[] = 'The markdown mirror sends no Link: rel="describedby" header — '
                    . 'an agent that fetched it directly cannot find the llms.txt describing it';
            }
        }

        // v2 permits both URL forms. Serving only one is compliant.
        $altForm = $this->alternateMarkdownForm($productUrl);
        $altFormStatus = $altForm !== null ? $this->statusCode($altForm) : null;
        if ($altForm !== null && $altFormStatus !== 200) {
            $warnings[] = sprintf(
                'Only one markdown URL form resolves. %s returns HTTP %s; v2 allows both '
                . 'page.html.md and page.md, and agents in the wild try either',
                $altForm,
                $altFormStatus
            );
        }

        $details = [
            'sample_url'          => $productUrl,
            'markdown_href'       => $mdHref,
            'markdown_status'     => $mdStatus,
            'llms_txt_href'       => $llmsHref,
            'llms_txt_status'     => $llmsStatus,
            'link_header_on_mirror' => $headerOnMirror,
            'alternate_form'      => $altForm,
            'alternate_form_status' => $altFormStatus,
        ];

        if ($issues !== []) {
            return $this->fail(
                sprintf('Link relations incomplete: %s', $issues[0]),
                implode(' | ', array_merge($issues, $warnings))
                . ' | llms.txt v2 (10 Aug 2026) added these relations so agents stop guessing URLs.',
                $details
            );
        }

        if ($warnings !== []) {
            return $this->warn(
                'Link relations present — ' . count($warnings) . ' improvement(s).',
                implode(' | ', $warnings),
                $details
            );
        }

        return $this->pass(
            'Both llms.txt v2 link relations present and resolving, including the Link: header on the mirror.',
            $details
        );
    }

    /**
     * Look for a link relation in the HTML head first, then in the HTTP Link:
     * header. Both forms are valid per the spec.
     *
     * @param array<string, string> $headers
     */
    private function findRelHref(string $html, array $headers, string $rel, bool $requireMarkdownType): ?string
    {
        // <link rel="alternate" type="text/markdown" href="...">  — attribute
        // order is not fixed, so match the tag then read its attributes.
        if (preg_match_all('/<link\b[^>]*>/i', $html, $tags) !== false) {
            foreach ($tags[0] as $tag) {
                if (!$this->attrContains($tag, 'rel', $rel)) {
                    continue;
                }
                if ($requireMarkdownType && !$this->attrContains($tag, 'type', 'text/markdown')) {
                    continue;
                }
                $href = $this->attr($tag, 'href');
                if ($href !== null && $href !== '') {
                    return $href;
                }
            }
        }

        // Link: <https://…>; rel="describedby"
        $linkHeader = $headers['link'] ?? '';
        if ($linkHeader !== '' && str_contains($linkHeader, $rel)) {
            if (preg_match('/<([^>]+)>\s*;[^,]*' . preg_quote($rel, '/') . '/i', $linkHeader, $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }

    private function attr(string $tag, string $name): ?string
    {
        if (preg_match('/\b' . preg_quote($name, '/') . '\s*=\s*["\']([^"\']*)["\']/i', $tag, $m) === 1) {
            return trim($m[1]);
        }

        return null;
    }

    private function attrContains(string $tag, string $name, string $needle): bool
    {
        $value = $this->attr($tag, $name);

        return $value !== null && stripos($value, $needle) !== false;
    }

    private function absolutize(string $href, string $base): string
    {
        if (preg_match('~^https?://~i', $href) === 1) {
            return $href;
        }

        return $base . '/' . ltrim($href, '/');
    }

    /**
     * Given a page URL, build the markdown form this store is NOT primarily
     * using, so both can be probed. Returns null when there is no second form
     * to try (a suffixless URL has only one).
     */
    private function alternateMarkdownForm(string $pageUrl): ?string
    {
        $path = (string) parse_url($pageUrl, PHP_URL_PATH);
        if ($path === '' || !str_contains(basename($path), '.')) {
            return null;
        }

        // page.html → page.md  (the appended form is page.html.md)
        $replaced = preg_replace('/\.[^.\/]+$/', '.md', $pageUrl);

        return is_string($replaced) ? $replaced : null;
    }
}
