# Contributing to Angeo AEO Audit

Thanks for your interest in improving the module! Issues, ideas, and pull requests are welcome.

## Quick start

```bash
git clone https://github.com/angeo-dev/module-aeo-audit.git
cd module-aeo-audit
composer install
vendor/bin/phpunit -c phpunit.xml
```

## Reporting bugs

Open an issue at [github.com/angeo-dev/module-aeo-audit/issues](https://github.com/angeo-dev/module-aeo-audit/issues) with:
- Magento version (Open Source / Commerce / Cloud)
- PHP version
- Theme (Luma / Hyvä / custom)
- Output of `bin/magento angeo:aeo:audit --format=json` (sanitised)
- Steps to reproduce

## Pull requests

Before opening a PR:

1. **Tests pass:** `vendor/bin/phpunit -c phpunit.xml`
2. **Code style:** `vendor/bin/phpcs --standard=Magento2 --extensions=php,phtml --severity=10 .`
3. **Static analysis:** `vendor/bin/phpstan analyse -l 5 .` (warnings okay, no errors)
4. **New checkers:** add a unit test under `Test/Unit/Model/Checker/`
5. **Public API changes:** update `CheckerInterface` carefully — third-party modules depend on it
6. **Update CHANGELOG.md** under `## [Unreleased]`

## Adding a new checker

Implement `Angeo\AeoAudit\Api\CheckerInterface`:

```php
public function getName(): string;
public function getCode(): string;       // unique snake_case
public function getWeight(): float;      // 0.0 to 1.0
public function check(string $baseUrl): CheckResult;
public function getFixCommand(): string; // composer require ... or ''
```

Register in `etc/di.xml`:

```xml
<type name="Angeo\AeoAudit\Model\AuditRunner">
    <arguments>
        <argument name="checkers" xsi:type="array">
            <item name="my_check" xsi:type="object">Vendor\Module\Model\Checker\MyChecker</item>
        </argument>
    </arguments>
</type>
```

Extend `AbstractChecker` to inherit the `fetch()`, `pass()`, `warn()`, `fail()`, and `extractJsonLdSchemas()` helpers.

## Coding standards

- PHP 8.2+ — use `declare(strict_types=1);` everywhere
- Constructor property promotion preferred for DI
- Magento 2 Coding Standard for everything else
- `final` classes are encouraged unless meant for extension
- Checkers must NEVER throw — catch exceptions internally and return `fail()`

## License

By contributing, you agree your contributions will be licensed under the MIT License.
