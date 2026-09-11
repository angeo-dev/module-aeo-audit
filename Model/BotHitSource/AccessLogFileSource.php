<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\BotHitSource;

use Angeo\AeoAudit\Api\BotHitSourceInterface;
use Angeo\AeoAudit\Model\Config;
use Angeo\AeoAudit\Service\BotRegistry;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Magento\Store\Api\Data\StoreInterface;

/**
 * Evidence source that reads a webserver access log (nginx / apache).
 *
 * Strictly OPT-IN: the merchant configures an absolute log path in admin.
 * The default installation never touches the filesystem, so the module
 * "installs and works" everywhere; this source is a coverage upgrade for
 * merchants who can grant read access properly.
 *
 * The recommended way to grant access — documented in README, never
 * enforced here — is a targeted ACL (`setfacl -m u:www-data:r access.log`)
 * or a logrotate postrotate hook that copies bot-filtered lines to a
 * PHP-readable location. This module must never ask for `chmod 644` on the
 * live log: access logs contain every visitor's IP.
 *
 * Reading strategy:
 *  - tail-read only the last MAX_READ_BYTES via fseek — a multi-GB log is
 *    never loaded into memory;
 *  - supports the "combined" format and JSON-lines logs (auto-detected
 *    per line);
 *  - PRIVACY: lines are classified and counted in memory; IPs, URLs and
 *    raw lines are discarded immediately. Only (bot, hits, last_seen)
 *    aggregates leave this class.
 *
 * CDN caveat surfaced in the coverage note: behind Fastly/Cloudflare the
 * origin log systematically under-counts bot traffic, because cache hits
 * never reach the origin.
 *
 * @since 4.0.0
 */
class AccessLogFileSource implements BotHitSourceInterface
{
    /** Read at most this many trailing bytes of the log (8 MB). */
    public const MAX_READ_BYTES = 8388608;

    public function __construct(
        private readonly Config      $config,
        private readonly BotRegistry $botRegistry,
        private readonly FileDriver  $fileDriver,
    ) {
    }

    public function getCode(): string
    {
        return 'access_log_file';
    }

    public function getLabel(): string
    {
        return 'Webserver access log';
    }

    public function isAvailable(StoreInterface $store): bool
    {
        $path = $this->config->getAccessLogPath((int) $store->getId());
        if ($path === '') {
            return false;
        }

        try {
            return $this->fileDriver->isExists($path) && $this->fileDriver->isReadable($path);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function getHits(StoreInterface $store, int $days): array
    {
        $path = $this->config->getAccessLogPath((int) $store->getId());

        try {
            $tail = $this->readTail($path);
        } catch (\Throwable) {
            return [];
        }

        return $this->aggregate($tail, $days);
    }

    public function getCoverageNote(): string
    {
        return sprintf(
            'Webserver access log (last %d MB tail). Behind a CDN this UNDER-counts bot traffic: '
            . 'cache hits are served at the edge and never reach the origin log.',
            (int) (self::MAX_READ_BYTES / 1048576)
        );
    }

    /**
     * Parse log content and aggregate AI-bot hits within the window.
     *
     * Public and pure (string in, aggregates out) so the parsing logic is
     * unit-testable without a filesystem.
     *
     * @return array<string, array{hits: int, last_seen: string}>
     */
    public function aggregate(string $content, int $days): array
    {
        $cutoff = (new \DateTimeImmutable(sprintf('-%d days', $days)))->setTime(0, 0);
        $result = [];

        $offset = 0;
        $length = strlen($content);

        // First partial line after a tail-seek is dropped below via the
        // strtok-style walk: we only process complete lines.
        while ($offset < $length) {
            $nl   = strpos($content, "\n", $offset);
            $line = $nl === false ? substr($content, $offset) : substr($content, $offset, $nl - $offset);
            $offset = $nl === false ? $length : $nl + 1;

            if ($line === '' || $line[0] === "\0") {
                continue;
            }

            $parsed = $this->parseLine($line);
            if ($parsed === null) {
                continue;
            }

            [$userAgent, $date] = $parsed;

            $botCode = $this->botRegistry->classifyUserAgent($userAgent);
            if ($botCode === null) {
                continue;
            }

            if ($date !== null && $date < $cutoff) {
                continue;
            }

            $day = $date?->format('Y-m-d') ?? 'unknown';
            if (!isset($result[$botCode])) {
                $result[$botCode] = ['hits' => 0, 'last_seen' => $day];
            }
            $result[$botCode]['hits']++;
            if ($day !== 'unknown' && $day > $result[$botCode]['last_seen']) {
                $result[$botCode]['last_seen'] = $day;
            }
        }

        return $result;
    }

    /**
     * Extract [userAgent, dateTime|null] from one log line, or null when the
     * line matches no supported format. Supports:
     *  - combined:  ... [10/Jun/2026:03:14:15 +0000] "GET / HTTP/1.1" 200 1234 "-" "GPTBot/1.2"
     *  - JSON lines with common key names (user_agent / http_user_agent / ua,
     *    time_local / time_iso8601 / time / timestamp)
     *
     * @return array{0: string, 1: \DateTimeImmutable|null}|null
     */
    private function parseLine(string $line): ?array
    {
        // JSON-lines format
        if ($line[0] === '{') {
            $decoded = json_decode($line, true);
            if (!is_array($decoded)) {
                return null;
            }
            $ua = (string) ($decoded['user_agent'] ?? $decoded['http_user_agent'] ?? $decoded['ua'] ?? '');
            if ($ua === '') {
                return null;
            }
            $rawTime = (string) ($decoded['time_iso8601'] ?? $decoded['time_local']
                ?? $decoded['time'] ?? $decoded['timestamp'] ?? '');
            return [$ua, $this->parseDate($rawTime)];
        }

        // Combined format: UA is the LAST quoted field; date is in [brackets].
        if (!preg_match('/"([^"]*)"\s*$/', $line, $uaMatch)) {
            return null;
        }
        $ua = $uaMatch[1];
        if ($ua === '' || $ua === '-') {
            return null;
        }

        $date = null;
        if (preg_match('/\[([^\]]+)\]/', $line, $dateMatch)) {
            $date = $this->parseDate($dateMatch[1]);
        }

        return [$ua, $date];
    }

    private function parseDate(string $raw): ?\DateTimeImmutable
    {
        if ($raw === '') {
            return null;
        }

        // nginx/apache combined: 10/Jun/2026:03:14:15 +0000
        $date = \DateTimeImmutable::createFromFormat('d/M/Y:H:i:s P', $raw);
        if ($date !== false) {
            return $date;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Read at most MAX_READ_BYTES from the end of the file, dropping the
     * first (potentially partial) line.
     */
    private function readTail(string $path): string
    {
        $stat = $this->fileDriver->stat($path);
        $size = (int) ($stat['size'] ?? 0);
        if ($size === 0) {
            return '';
        }

        $resource = $this->fileDriver->fileOpen($path, 'r');
        try {
            $start = max(0, $size - self::MAX_READ_BYTES);
            if ($start > 0) {
                $this->fileDriver->fileSeek($resource, $start);
            }

            $content = '';
            while (!$this->fileDriver->endOfFile($resource)) {
                $chunk = $this->fileDriver->fileRead($resource, 1048576);
                if ($chunk === '') {
                    break;
                }
                $content .= $chunk;
            }
        } finally {
            $this->fileDriver->fileClose($resource);
        }

        // Drop the first partial line when we seeked into the middle.
        if ($start > 0) {
            $firstNewline = strpos($content, "\n");
            $content = $firstNewline === false ? '' : substr($content, $firstNewline + 1);
        }

        return $content;
    }
}
