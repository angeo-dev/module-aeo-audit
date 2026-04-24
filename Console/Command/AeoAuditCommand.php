<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Console\Command;

use Angeo\AeoAudit\Api\CheckerInterface;
use Angeo\AeoAudit\Model\AuditResult;
use Angeo\AeoAudit\Model\AuditResultFactory;
use Angeo\AeoAudit\Model\AuditRunner;
use Angeo\AeoAudit\Model\Report\AuditReport;
use Angeo\AeoAudit\Model\Report\CheckResult;
use Angeo\AeoAudit\Model\ResourceModel\AuditResult as AuditResultResource;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AeoAuditCommand extends Command
{
    private const OPT_STORE   = 'store';
    private const OPT_FORMAT  = 'format';
    private const OPT_OUTPUT  = 'output';
    private const OPT_FAIL_ON = 'fail-on';
    private const OPT_NO_SAVE = 'no-save';

    public function __construct(
        private readonly AuditRunner         $auditRunner,
        private readonly AuditResultFactory  $auditResultFactory,
        private readonly AuditResultResource $auditResultResource,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('angeo:aeo:audit')
            ->setDescription('Run AEO (AI Engine Optimization) audit for your Magento 2 store.')
            ->addOption(self::OPT_STORE,   's', InputOption::VALUE_OPTIONAL, 'Store code (default: all stores)')
            ->addOption(self::OPT_FORMAT,  'f', InputOption::VALUE_OPTIONAL, 'Output format: table (default), json, markdown', 'table')
            ->addOption(self::OPT_OUTPUT,  'o', InputOption::VALUE_OPTIONAL, 'Save report to file path')
            ->addOption(self::OPT_FAIL_ON, null, InputOption::VALUE_OPTIONAL, 'Exit code 1 if score below this % (CI use)')
            ->addOption(self::OPT_NO_SAVE, null, InputOption::VALUE_NONE, 'Do not persist results to database');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $storeCode  = $input->getOption(self::OPT_STORE);
        $format     = strtolower((string) $input->getOption(self::OPT_FORMAT));
        $outputFile = $input->getOption(self::OPT_OUTPUT);
        $failOn     = $input->getOption(self::OPT_FAIL_ON);
        $noSave     = (bool) $input->getOption(self::OPT_NO_SAVE);

        $this->printBanner($output);

        try {
            $reports = $this->auditRunner->runAll($storeCode ?: null);
        } catch (\Throwable $e) {
            $output->writeln('<error>Audit failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $fileContent = '';
        $worstScore  = 100;

        foreach ($reports as $report) {
            $output->writeln(sprintf(
                '<comment>Store: %s — %s</comment>',
                $report->getStoreCode(),
                $report->getStoreUrl()
            ));
            $output->writeln('');

            switch ($format) {
                case 'json':
                    $chunk = json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    $output->writeln($chunk);
                    $fileContent .= $chunk . "\n";
                    break;

                case 'markdown':
                    $chunk = $this->renderMarkdown($report);
                    $output->writeln($chunk);
                    $fileContent .= $chunk . "\n";
                    break;

                default:
                    $this->renderTable($output, $report);
                    $fileContent .= $this->renderMarkdown($report) . "\n";
                    break;
            }

            $this->renderSummary($output, $report);

            // Persist to DB
            if (!$noSave) {
                try {
                    /** @var AuditResult $result */
                    $result = $this->auditResultFactory->create();
                    $result->populateFromReport($report, AuditResult::TRIGGERED_MANUAL);
                    $this->auditResultResource->save($result);
                    $this->auditResultResource->pruneOldResults($report->getStoreCode());
                } catch (\Throwable $e) {
                    $output->writeln('<comment>Warning: could not save results to DB — ' . $e->getMessage() . '</comment>');
                }
            }

            $worstScore = min($worstScore, $report->getScorePercent());
        }

        if ($outputFile) {
            file_put_contents($outputFile, $fileContent);
            $output->writeln(sprintf('<info>Report saved to: %s</info>', $outputFile));
        }

        if ($failOn !== null && $worstScore < (int) $failOn) {
            $output->writeln(sprintf(
                '<error>Score %d%% is below threshold %d%% — failing build.</error>',
                $worstScore,
                (int) $failOn
            ));
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function printBanner(OutputInterface $output): void
    {
        $output->writeln('');
        $output->writeln('<info>  ╔══════════════════════════════════════════╗</info>');
        $output->writeln('<info>  ║   Angeo AEO Audit — angeo.dev           ║</info>');
        $output->writeln('<info>  ║   AI Engine Optimization for Magento 2  ║</info>');
        $output->writeln('<info>  ╚══════════════════════════════════════════╝</info>');
        $output->writeln('');
    }

    private function renderTable(OutputInterface $output, AuditReport $report): void
    {
        $table = new Table($output);
        $table->setHeaders(['Check', 'Status', 'Score', 'Message', 'Recommendation']);
        $table->setColumnMaxWidth(3, 42);
        $table->setColumnMaxWidth(4, 38);

        foreach ($report->getResults() as $result) {
            $table->addRow([
                $result->getCheckName(),
                $this->statusLabel($result->getStatus()),
                sprintf('w:%.1f', $result->getWeight()),
                $result->getMessage(),
                $result->getRecommendation() ?: '—',
            ]);
        }

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

        $bar = '[' . str_repeat('█', (int) round($pct / 5)) . str_repeat('░', 20 - (int) round($pct / 5)) . ']';

        $output->writeln('');
        $output->writeln(sprintf('  AEO Score: <%1$s>%2$s %3$d%% — %4$s</%1$s>', $color, $bar, $pct, $label));
        $output->writeln(sprintf(
            '  <info>✓ Pass: %d</info>  <comment>⚠ Warn: %d</comment>  <error>✗ Fail: %d</error>',
            $report->getPassCount(),
            $report->getWarnCount(),
            $report->getFailCount()
        ));

        $fails = array_filter($report->getResults(), fn(CheckResult $r) => $r->isFailed());
        if (!empty($fails)) {
            $output->writeln('');
            $output->writeln('  <error>Critical fixes needed:</error>');
            foreach ($fails as $r) {
                if ($r->getRecommendation()) {
                    $output->writeln('  → ' . $r->getRecommendation());
                }
            }
        }

        $output->writeln('');

        // Collect fix commands from failed + warned checks — dynamic, not hardcoded
        $fixCommands = [];
        foreach ($report->getResults() as $result) {
            if (!$result->isPassed()) {
                $cmd = $result->getFixCommand();
                if ($cmd !== '') {
                    $fixCommands[$cmd] = true;
                }
            }
        }

        if (!empty($fixCommands)) {
            $output->writeln('');
            $output->writeln('  <info>💡 Fix with angeo modules:</info>');
            foreach (array_keys($fixCommands) as $cmd) {
                $output->writeln('     ' . $cmd);
            }
        }

    }

    private function renderMarkdown(AuditReport $report): string
    {
        $lines   = [];
        $lines[] = '# AEO Audit — ' . $report->getStoreCode();
        $lines[] = '';
        $lines[] = '**URL:** ' . $report->getStoreUrl() . '  ';
        $lines[] = '**Score:** ' . $report->getScorePercent() . '% — ' . $report->getScoreLabel() . '  ';
        $lines[] = '**Pass:** ' . $report->getPassCount() . ' | **Warn:** ' . $report->getWarnCount() . ' | **Fail:** ' . $report->getFailCount();
        $lines[] = '';
        $lines[] = '| Check | Status | Message | Recommendation |';
        $lines[] = '|-------|--------|---------|----------------|';

        foreach ($report->getResults() as $result) {
            $status = match ($result->getStatus()) {
                CheckerInterface::STATUS_PASS => '✅ Pass',
                CheckerInterface::STATUS_WARN => '⚠️ Warn',
                default                       => '❌ Fail',
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

    private function statusLabel(string $status): string
    {
        return match ($status) {
            CheckerInterface::STATUS_PASS => '<info>✓ PASS</info>',
            CheckerInterface::STATUS_WARN => '<comment>⚠ WARN</comment>',
            default                       => '<error>✗ FAIL</error>',
        };
    }
}
