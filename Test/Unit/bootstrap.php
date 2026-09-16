<?php
/**
 * Unit-test bootstrap.
 *
 * Magento generates *Factory classes at runtime (generated/code). Unit tests
 * run without Magento's code generator, so PHPUnit cannot mock those classes.
 * This autoloader declares a minimal factory for any *Factory whose target
 * class or interface exists — the same shape Magento generates.
 *
 * Test-only: Test/ is excluded from setup:di:compile and from the Composer
 * package (.gitattributes).
 *
 * @copyright Copyright (c) Angeo
 * @license   MIT
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    if (substr($class, -7) !== 'Factory') {
        return;
    }
    $target = substr($class, 0, -7);
    if (!class_exists($target) && !interface_exists($target)) {
        return;
    }

    $dir = sys_get_temp_dir() . '/angeo-test-factories';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $file = $dir . '/' . hash('sha256', $class) . '.php';
    if (!is_file($file)) {
        $pos = (int) strrpos($class, '\\');
        $code = sprintf(
            "<?php\nnamespace %s;\n\nclass %s\n{\n    public function create(array \$data = []): \\%s\n    {\n"
            . "        throw new \\LogicException('Test factory: configure create() on a mock.');\n    }\n}\n",
            substr($class, 0, $pos),
            substr($class, $pos + 1),
            $target
        );
        file_put_contents($file, $code);
    }
    require_once $file;
});
