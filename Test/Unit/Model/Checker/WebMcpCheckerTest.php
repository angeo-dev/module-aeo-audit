<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Test\Unit\Model\Checker;

use Angeo\AeoAudit\Model\Checker\WebMcpChecker;
use PHPUnit\Framework\TestCase;

/**
 * 4.6.0: WebMCP tools and the Chrome origin trial token.
 */
class WebMcpCheckerTest extends TestCase
{
    use CheckerTestHelper;

    private WebMcpChecker $checker;

    protected function setUp(): void
    {
        $this->bootCheckerMocks();
        $this->checker = new WebMcpChecker($this->httpCache, $this->urlSampler);
    }

    private static function token(string $feature, int $expiry): string
    {
        $payload = (string) json_encode(['origin' => 'https://example.com:443', 'feature' => $feature, 'expiry' => $expiry]);

        return base64_encode(chr(3) . str_repeat("\0", 64) . pack('N', strlen($payload)) . $payload);
    }

    private static function angeoPage(string $tokenMeta = ''): string
    {
        return '<html><head>' . $tokenMeta . '</head><body>'
            . '<script type="application/json" id="angeo-webmcp-config">'
            . '{"version":"1.0.1","tools":["search_products","get_product","add_to_cart"],"storeCode":"default"}'
            . '</script><script src="/static/Angeo_WebMcp/js/webmcp.js" defer></script></body></html>';
    }

    public function testDecodeOriginTrialToken(): void
    {
        $t = WebMcpChecker::decodeOriginTrialToken(self::token('WebMCP', 1893456000));
        self::assertSame('WebMCP', $t['feature']);
        self::assertSame(1893456000, $t['expires_at']);

        self::assertSame(['feature' => null, 'expires_at' => null], WebMcpChecker::decodeOriginTrialToken('not-a-token'));
    }

    public function testDetectAngeoModuleAndTools(): void
    {
        $found = WebMcpChecker::detect(self::angeoPage());
        self::assertTrue($found['has_tools']);
        self::assertTrue($found['angeo_module']);
        self::assertSame(['search_products', 'get_product', 'add_to_cart'], $found['tools']);
        self::assertNull($found['origin_trial']);
    }

    public function testDetectCustomImplementations(): void
    {
        $script = WebMcpChecker::detect('<script>document.modelContext.registerTool({name:"x"})</script>');
        self::assertTrue($script['has_tools']);
        self::assertFalse($script['angeo_module']);

        $form = WebMcpChecker::detect('<form toolname="search" tooldescription="Search"><input name="q"></form>');
        self::assertTrue($form['has_tools']);
        self::assertSame(1, $form['declarative_forms']);

        self::assertFalse(WebMcpChecker::detect('<html><body>plain</body></html>')['has_tools']);
    }

    public function testAngeoConfigWithAllToolsOffIsNotTools(): void
    {
        $html = '<script type="application/json" id="angeo-webmcp-config">{"tools":[]}</script>';
        self::assertFalse(WebMcpChecker::detect($html)['has_tools']);
    }

    public function testPassWithToolsAndValidToken(): void
    {
        $meta = '<meta http-equiv="origin-trial" content="' . self::token('WebMCP', time() + 90 * 86400) . '">';
        $this->stubUrl('https://example.com/', 200, self::angeoPage($meta));

        $result = $this->checker->check($this->store);

        self::assertTrue($result->isPassed(), $result->getMessage());
        self::assertStringContainsString('angeo/module-webmcp', $result->getMessage());
        self::assertStringContainsString('with an origin trial token', $result->getMessage());
    }

    public function testPassWithToolsButNoTokenSaysFlagOnly(): void
    {
        $this->stubUrl('https://example.com/', 200, self::angeoPage());

        $result = $this->checker->check($this->store);

        self::assertTrue($result->isPassed(), $result->getMessage());
        self::assertStringContainsString('no origin trial token', $result->getMessage());
    }

    public function testWarnWhenTokenExpired(): void
    {
        $meta = '<meta http-equiv="origin-trial" content="' . self::token('WebMCP', time() - 86400) . '">';
        $this->stubUrl('https://example.com/', 200, self::angeoPage($meta));

        $result = $this->checker->check($this->store);

        self::assertTrue($result->isWarning(), $result->getMessage());
        self::assertStringContainsString('expired', $result->getMessage());
    }

    public function testWarnWhenTokenRunsOutSoon(): void
    {
        $meta = '<meta http-equiv="origin-trial" content="' . self::token('WebMCP', time() + 5 * 86400) . '">';
        $this->stubUrl('https://example.com/', 200, self::angeoPage($meta));

        $result = $this->checker->check($this->store);

        self::assertTrue($result->isWarning(), $result->getMessage());
        self::assertStringContainsString('runs out', $result->getMessage());
    }

    public function testNoToolsIsInformationalPass(): void
    {
        $this->stubUrl('https://example.com/', 200, '<html><body>store</body></html>');

        $result = $this->checker->check($this->store);

        self::assertTrue($result->isPassed(), $result->getMessage());
        self::assertStringContainsString('No WebMCP tools', $result->getMessage());
    }

    public function testCodeAndWeight(): void
    {
        self::assertSame('webmcp', $this->checker->getCode());
        self::assertSame(0.0, $this->checker->getWeight());
    }
}
