<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Test\Unit\Model\Checker;

use Angeo\AeoAudit\Model\Checker\FaqSchemaChecker;
use PHPUnit\Framework\TestCase;

class FaqSchemaCheckerTest extends TestCase
{
    use CheckerTestHelper;

    private FaqSchemaChecker $checker;

    protected function setUp(): void
    {
        $this->bootCheckerMocks('https://example.com');
        $this->urlSampler->method('getSampleCmsPageUrl')->willReturn('https://example.com/faq');
        $this->checker = new FaqSchemaChecker($this->httpCache, $this->urlSampler);
    }

    public function testWarnsWhenNoFaqAnywhere(): void
    {
        $this->stubUrl('https://example.com', 200, '<html></html>');
        $this->stubUrl('https://example.com/faq', 200, '<html></html>');
        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isWarning());
    }

    public function testPassesWhenFaqOnHomepage(): void
    {
        $schema = [
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            'mainEntity' => [
                ['@type' => 'Question', 'name' => 'Q1'],
                ['@type' => 'Question', 'name' => 'Q2'],
            ],
        ];
        $html = '<script type="application/ld+json">' . json_encode($schema) . '</script>';
        $this->stubUrl('https://example.com', 200, $html);
        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isPassed());
        $this->assertSame(2, $result->getDetails()['question_count']);
    }

    public function testPassesWhenFaqOnCmsPage(): void
    {
        $this->stubUrl('https://example.com', 200, '<html></html>');
        $schema = [
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            'mainEntity' => [['@type' => 'Question']],
        ];
        $html = '<script type="application/ld+json">' . json_encode($schema) . '</script>';
        $this->stubUrl('https://example.com/faq', 200, $html);
        $result = $this->checker->check($this->store);
        $this->assertTrue($result->isPassed());
    }
}
