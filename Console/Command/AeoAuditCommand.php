<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Console\Command;

use Angeo\AeoAudit\Api\CheckerInterface;
use Angeo\AeoAudit\Model\AuditRunner;
use Angeo\AeoAudit\Model\Report\AuditReport;
use Angeo\AeoAudit\Model\Report\CheckResult;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;

class AeoAuditCommand extends Command
{
    private const OPTION_STORE   = 'store';
    private const OPTION_FORMAT  = 'format';
    private const OPTION_OUTPUT  = 'output';
    private const OPTION_FAIL_ON = 'fail-on';

    public function __construct(
        private readonly AuditRunner $auditRunner
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('angeo:aeo:audit')
            ->setDescription('Run AEO (AI Engine Optimization) audit for your Magento 2 store.')
            ->addOption(
                self::OPTION_STORE,
                's',
                InputOption::VALUE_OPTIONAL,
                'Store code to audit (default: all stores)'
            )
            ->addOption(
                self::OPTION_FORMAT,
                'f',
                InputOption::VALUE_OPTIONAL,
                'Output format: table (default), json, markdown',
                'table'
            )
            ->addOption(
                self::OPTION_OUTPUT,
                'o',
                InputOption::VALUE_OPTIONAL,
                'Write report to a file path (optional)'
            )
            ->addOption(
                self::OPTION_FAIL_ON,
                null,
                InputOption::VALUE_OPTIONAL,
                'Exit with code 1 if score is below this percentage (e.g. 70)',
                null
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $storeCode = $input->getOption(self::OPTION_STORE);
        $format    = strtolower((string) $input->getOption(self::OPTION_FORMAT));
        $outputFile = $input->getOption(self::OPTION_OUTPUT);
        $failOn    = $input->getOption(self::OPTION_FAIL_ON);

        $output->writeln('');
        $output->writeln('<info>  ╔══════════════════════════════════════════╗</info>');
        $output->writeln('<info>  ║   Angeo AEO Audit — angeo.dev           ║</info>');
        $output->writeln('<info>  ║   AI Engine Optimization for Magento 2  ║</info>');
        $output->writeln('<info>  ╚══════════════════════════════════════════╝</info>');
        $output->writeln('');

        try {
            $reports = $this->auditRunner->runAll($storeCode ?: null);
        } catch (\Exception $e) {
            $output->writeln('<error>Audit failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $reportContent = '';

        foreach ($reports as $report) {
            $output->writeln(sprintf(
                '<comment>Store: %s — %s</comment>',
                $report->getStoreCode(),
                $report->getStoreUrl()
            ));
            $output->writeln('');

            switch ($format) {
                case 'json':
                    $json = $this->renderJson($report);
                    $output->writeln($json);
                    $reportContent .= $json . "\n";
                    break;
                case 'markdown':
                    $md = $this->renderMarkdown($report);
                    $output->writeln($md);
                    $reportContent .= $md . "\n";
                    break;
                default:
                    $this->renderTable($output, $report);
                    $reportContent .= $this->renderMarkdown($report) . "\n";
                    break;
            }

            $this->renderSummary($output, $report);
            $output->writeln('');
        }

        if ($outputFile) {
            file_put_contents($outputFile, $reportContent);
            $output->writeln(sprintf('<info>Report saved to: %s</info>', $outputFile));
        }

        // Exit code handling for CI pipelines
        if ($failOn !== null) {
            $threshold = (int) $failOn;
            foreach ($reports as $report) {
                if ($report->getScorePercent() < $threshold) {
                    $output->writeln(sprintf(
                        '<error>Score %d%% is below threshold %d%%. Failing build.</error>',
                        $report->getScorePercent(),
                        $threshold
                    ));
                    return Command::FAILURE;
                }
            }
        }

        return Command::SUCCESS;
    }

    private function renderTable(OutputInterface $output, AuditReport $report): void
    {
        $table = new Table($output);
        $table->setHeaders(['Check', 'Status', 'Message', 'Recommendation']);
        $table->setColumnMaxWidth(2, 45);
        $table->setColumnMaxWidth(3, 40);

        $rows = [];
        foreach ($report->getResults() as $result) {
            $rows[] = [
                $result->getCheckName(),
                $this->formatStatus($result->getStatus()),
                $result->getMessage(),
                $result->getRecommendation() ?: '—',
            ];
        }

        $table->setRows($rows);
        $table->render();
    }

    private function renderSummary(OutputInterface $output, AuditReport $report): void
    {
        $pct   = $report->getScorePercent();
        $label = $report->getScoreLabel();

        $color = match (true) {
            $pct >= 85 => 'info',
            $pct >= 65 => 'comment',
            default    => 'error',
        };

        $bar = $this->progressBar($pct);

        $output->writeln('');
        $output->writeln(sprintf(
            '  AEO Score: <%s>%s %d%% — %s</%s>',
            $color, $bar, $pct, $label, $color
        ));
        $output->writeln(sprintf(
            '  <info>✓ Pass: %d</info>  <comment>⚠ Warn: %d</comment>  <error>✗ Fail: %d</error>',
            $report->getPassCount(),
            $report->getWarnCount(),
            $report->getFailCount()
        ));

        if ($report->getFailCount() > 0) {
            $output->writeln('');
            $output->writeln('  <error>Critical fixes needed:</error>');
            foreach ($report->getResults() as $result) {
                if ($result->isFailed() && $result->getRecommendation()) {
                    $output->writeln('  → ' . $result->getRecommendation());
                }
            }
        }

        $output->writeln('');
        $output->writeln('  <info>💡 Fix issues with angeo modules:</info>');
        $output->writeln('     composer require angeo/module-llms-txt');
        $output->writeln('     composer require angeo/module-openai-product-feed');
        $output->writeln('');
    }

    private function renderJson(AuditReport $report): string
    {
        $data = [
            'store_code'    => $report->getStoreCode(),
            'store_url'     => $report->getStoreUrl(),
            'score'         => $report->getScorePercent(),
            'score_label'   => $report->getScoreLabel(),
            'pass'          => $report->getPassCount(),
            'warn'          => $report->getWarnCount(),
            'fail'          => $report->getFailCount(),
            'checks'        => array_map(fn(CheckResult $r) => [
                'name'           => $r->getCheckName(),
                'status'         => $r->getStatus(),
                'message'        => $r->getMessage(),
                'recommendation' => $r->getRecommendation(),
                'details'        => $r->getDetails(),
            ], $report->getResults()),
        ];

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function renderMarkdown(AuditReport $report): string
    {
        $lines = [];
        $lines[] = '# AEO Audit Report — ' . $report->getStoreCode();
        $lines[] = '';
        $lines[] = '**URL:** ' . $report->getStoreUrl();
        $lines[] = '**Score:** ' . $report->getScorePercent() . '% — ' . $report->getScoreLabel();
        $lines[] = '**Pass:** ' . $report->getPassCount() . ' | **Warn:** ' . $report->getWarnCount() . ' | **Fail:** ' . $report->getFailCount();
        $lines[] = '';
        $lines[] = '| Check | Status | Message | Recommendation |';
        $lines[] = '|-------|--------|---------|----------------|';

        foreach ($report->getResults() as $result) {
            $status = match ($result->getStatus()) {
                CheckerInterface::STATUS_PASS => '✅ Pass',
                CheckerInterface::STATUS_WARN => '⚠️ Warn',
                default                        => '❌ Fail',
            };
            $lines[] = sprintf(
                '| %s | %s | %s | %s |',
                $result->getCheckName(),
                $status,
                str_replace('|', '\\|', $result->getMessage()),
                str_replace('|', '\\|', $result->getRecommendation() ?: '—')
            );
        }

        $lines[] = '';
        $lines[] = '_Generated by [angeo/module-aeo-audit](https://angeo.dev)_';

        return implode("\n", $lines);
    }

    private function formatStatus(string $status): string
    {
        return match ($status) {
            CheckerInterface::STATUS_PASS => '<info>✓ PASS</info>',
            CheckerInterface::STATUS_WARN => '<comment>⚠ WARN</comment>',
            default                        => '<error>✗ FAIL</error>',
        };
    }

    private function progressBar(int $pct): string
    {
        $filled = (int) round($pct / 5);
        $empty  = 20 - $filled;
        return '[' . str_repeat('█', $filled) . str_repeat('░', $empty) . ']';
    }
}
