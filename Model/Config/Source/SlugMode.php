<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Config\Source;

use Angeo\AeoAudit\Model\Config;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Options for the "Placeholder slug handling" sitemap setting.
 *
 * @since 3.1.0
 */
class SlugMode implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => Config::SLUG_MODE_SCORE,  'label' => __('Affect score (warn at threshold)')],
            ['value' => Config::SLUG_MODE_IGNORE, 'label' => __('Ignore (report only)')],
        ];
    }
}
