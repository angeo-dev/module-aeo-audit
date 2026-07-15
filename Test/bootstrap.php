<?php
/**
 * PHPUnit bootstrap for credential-free CI.
 *
 * Loads Composer's autoloader, then minimal Magento runtime stubs. The stub
 * file guards each declaration with *_exists() so that when a real Magento
 * install is present (e.g. running the suite locally against the framework),
 * the genuine classes win and the stubs are skipped.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/stubs/magento-stubs.php';
