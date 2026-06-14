<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Centralised, store-scoped reader for all AEO Audit configuration.
 *
 * Keeping config access in one typed class (instead of calling ScopeConfig
 * from every checker) means:
 *  - paths live in one place and can't drift,
 *  - checkers stay free of Magento config plumbing,
 *  - defaults are documented next to the getters.
 *
 * All booleans default to ENABLED so a fresh install audits every signal.
 *
 * @api
 * @since 3.1.0
 */
class Config
{
    /** Section root in core_config_data (matches system.xml section id). */
    private const SECTION = 'angeo_aeo';

    /** Group that holds the per-signal on/off switches. */
    private const GROUP_SIGNALS = 'signals';

    /** Group that holds sitemap-checker tuning. */
    private const GROUP_SITEMAP = 'sitemap';

    // ── Sitemap placeholder-slug handling modes ──────────────────────
    /** Placeholder slugs lower the score (PASS → WARN). Default. */
    public const SLUG_MODE_SCORE = 'score';
    /** Placeholder slugs are reported in details but never change status. */
    public const SLUG_MODE_IGNORE = 'ignore';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
    ) {
    }

    /**
     * Is a given checker enabled? Unknown / unset codes default to TRUE so a
     * freshly installed module (or a third-party checker without a switch)
     * always runs.
     *
     * @param string $checkerCode e.g. "robots_txt", "sitemap"
     */
    public function isCheckerEnabled(string $checkerCode, ?int $storeId = null): bool
    {
        $path = sprintf('%s/%s/%s', self::SECTION, self::GROUP_SIGNALS, $checkerCode);

        // isSetFlag returns false for a missing node, which would wrongly
        // disable unknown checkers — so fall back to TRUE when the path is unset.
        if ($this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId) === null) {
            return true;
        }

        return $this->scopeConfig->isSetFlag($path, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * How the sitemap checker should treat placeholder slugs.
     *
     * @return self::SLUG_MODE_*
     */
    public function getSitemapSlugMode(?int $storeId = null): string
    {
        $value = (string) $this->scopeConfig->getValue(
            sprintf('%s/%s/placeholder_slug_mode', self::SECTION, self::GROUP_SITEMAP),
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $value === self::SLUG_MODE_IGNORE ? self::SLUG_MODE_IGNORE : self::SLUG_MODE_SCORE;
    }

    /**
     * Minimum number of placeholder slugs required before they affect the
     * score. Only meaningful when mode = SLUG_MODE_SCORE.
     *
     * A value of 1 (default) means "a single bad slug warns". Raise it to
     * tolerate a handful of legacy slugs without dinging the score.
     */
    public function getSitemapSlugThreshold(?int $storeId = null): int
    {
        $value = (int) $this->scopeConfig->getValue(
            sprintf('%s/%s/placeholder_slug_threshold', self::SECTION, self::GROUP_SITEMAP),
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $value > 0 ? $value : 1;
    }

    /**
     * CrUX API key for the Core Web Vitals checker (kept here so all config
     * access funnels through one class). Stored encrypted.
     */
    public function getCruxApiKey(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            sprintf('%s/crux/api_key', self::SECTION),
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }
}
