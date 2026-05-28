<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Test\Unit\Model\Checker;

use Angeo\AeoAudit\Model\Checker\OrganizationSchemaChecker;
use PHPUnit\Framework\TestCase;

class OrganizationSchemaCheckerTest extends TestCase
{
    use CheckerTestHelper;

    private OrganizationSchemaChecker $checker;

    protected function setUp(): void
    {
        $this->bootCheckerMocks('https://example.com');
        $this->checker = new OrganizationSchemaChecker($this->httpCache, $this->urlSampler);
    }

    public function testFailWhenNoOrgSchema(): void
    {
        $this->stubUrl('https://example.com', 200, '<html><body>nothing</body></html>');
        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isFailed());
    }

    public function testFailWhenMissingRequiredFields(): void
    {
        $html = $this->wrapJsonLd([
            '@context' => 'https://schema.org',
            '@type'    => 'Organization',
            // missing name and url
        ]);
        $this->stubUrl('https://example.com', 200, $html);
        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isFailed());
        $this->assertStringContainsString('missing required', $result->getMessage());
    }

    public function testWarnsWhenSameAsTooFew(): void
    {
        $html = $this->wrapJsonLd([
            '@context'    => 'https://schema.org',
            '@type'       => 'Organization',
            'name'        => 'Example Store',
            'url'         => 'https://example.com',
            'logo'        => 'https://example.com/logo.png',
            'description' => 'A great store',
            'sameAs'      => ['https://facebook.com/example'], // only one
            'contactPoint' => [
                '@type'       => 'ContactPoint',
                'telephone'   => '+1-555-0100',
                'contactType' => 'customer service',
            ],
        ]);
        $this->stubUrl('https://example.com', 200, $html);
        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isWarning());
        $this->assertStringContainsString('sameAs', $result->getRecommendation());
    }

    public function testPassesWithFullSchema(): void
    {
        $html = $this->wrapJsonLd([
            '@context'    => 'https://schema.org',
            '@type'       => 'Organization',
            'name'        => 'Example Store',
            'url'         => 'https://example.com',
            'logo'        => 'https://example.com/logo.png',
            'description' => 'A great store',
            'sameAs'      => [
                'https://facebook.com/example',
                'https://wikidata.org/wiki/Q1234',
            ],
            'contactPoint' => [
                '@type'       => 'ContactPoint',
                'telephone'   => '+1-555-0100',
                'contactType' => 'customer service',
            ],
        ]);
        $this->stubUrl('https://example.com', 200, $html);
        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isPassed(), 'Got ' . $result->getStatus() . ': ' . $result->getMessage()
            . ' | ' . $result->getRecommendation());
    }

    public function testAcceptsOnlineStoreSubtype(): void
    {
        $html = $this->wrapJsonLd([
            '@context'    => 'https://schema.org',
            '@type'       => 'OnlineStore',
            'name'        => 'Example',
            'url'         => 'https://example.com',
            'logo'        => 'https://example.com/logo.png',
            'description' => 'desc',
            'sameAs'      => ['a', 'b'],
            'contactPoint' => ['@type' => 'ContactPoint'],
        ]);
        $this->stubUrl('https://example.com', 200, $html);
        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isPassed());
        $this->assertSame('OnlineStore', $result->getDetails()['type']);
    }

    public function testGraphArrayUnwrapsCorrectly(): void
    {
        $html = $this->wrapJsonLd([
            '@context' => 'https://schema.org',
            '@graph'   => [
                ['@type' => 'WebSite', 'url' => 'https://example.com'],
                [
                    '@type'  => 'Organization',
                    'name'   => 'Example',
                    'url'    => 'https://example.com',
                    'logo'   => 'https://example.com/logo.png',
                    'description' => 'desc',
                    'sameAs' => ['a', 'b'],
                    'contactPoint' => ['@type' => 'ContactPoint'],
                ],
            ],
        ]);
        $this->stubUrl('https://example.com', 200, $html);
        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isPassed(), 'Got ' . $result->getStatus() . ': ' . $result->getMessage());
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function wrapJsonLd(array $schema): string
    {
        return '<html><head><script type="application/ld+json">'
            . json_encode($schema) . '</script></head><body></body></html>';
    }
}
