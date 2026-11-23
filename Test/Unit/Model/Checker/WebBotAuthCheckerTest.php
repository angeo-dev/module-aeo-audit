<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Test\Unit\Model\Checker;

use Angeo\AeoAudit\Model\Checker\WebBotAuthChecker;
use Magento\Framework\Module\Manager as ModuleManager;
use PHPUnit\Framework\TestCase;

/**
 * 4.6.0: can the store verify Web Bot Auth signatures (ChatGPT agents)?
 */
class WebBotAuthCheckerTest extends TestCase
{
    use CheckerTestHelper;

    private const JWKS = '{"keys":[{"kty":"OKP","crv":"Ed25519","kid":"a","x":"AAAA"},{"kty":"OKP","crv":"Ed25519","kid":"b","x":"BBBB"}]}';

    private function checker(bool $moduleOn): WebBotAuthChecker
    {
        $this->bootCheckerMocks();
        $modules = $this->createMock(ModuleManager::class);
        $modules->method('isEnabled')->willReturnCallback(
            static fn (string $name): bool => $moduleOn && $name === WebBotAuthChecker::AI_BOTS_MODULE
        );

        return new WebBotAuthChecker($this->httpCache, $this->urlSampler, $modules);
    }

    public function testCountKeys(): void
    {
        self::assertSame(2, WebBotAuthChecker::countKeys(self::JWKS));
        self::assertSame(0, WebBotAuthChecker::countKeys('{"keys":[]}'));
        self::assertSame(0, WebBotAuthChecker::countKeys('<html>'));
    }

    public function testWarnWithoutAiBotsModule(): void
    {
        $checker = $this->checker(false);

        $result = $checker->check($this->store);

        self::assertTrue($result->isWarning(), $result->getMessage());
        self::assertFalse($result->getDetails()['ai_bots_module']);
        self::assertSame('composer require angeo/module-ai-bots', $checker->getFixCommand());
    }

    public function testWarnWhenKeyDirectoryUnreachable(): void
    {
        $checker = $this->checker(true);
        $this->stubUrl(WebBotAuthChecker::CHATGPT_KEY_DIRECTORY, 0, '');

        $result = $checker->check($this->store);

        self::assertTrue($result->isWarning(), $result->getMessage());
        self::assertStringContainsString('cannot read', $result->getMessage());
    }

    public function testPassWhenModuleOnAndKeysReadable(): void
    {
        $checker = $this->checker(true);
        $this->stubUrl(WebBotAuthChecker::CHATGPT_KEY_DIRECTORY, 200, self::JWKS);

        $result = $checker->check($this->store);

        self::assertTrue($result->isPassed(), $result->getMessage());
        self::assertSame(2, $result->getDetails()['key_directory_keys']);
    }

    public function testCodeAndWeight(): void
    {
        $checker = $this->checker(true);
        self::assertSame('web_bot_auth', $checker->getCode());
        self::assertSame(0.0, $checker->getWeight());
    }
}
