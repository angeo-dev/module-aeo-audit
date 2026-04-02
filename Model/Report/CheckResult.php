<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Report;

use Angeo\AeoAudit\Api\CheckerInterface;

/**
 * Immutable value object representing the result of a single AEO check.
 */
class CheckResult
{
    public function __construct(
        private readonly string $checkName,
        private readonly string $status,
        private readonly string $message,
        private readonly string $recommendation = '',
        private readonly array  $details = []
    ) {}

    public static function pass(string $checkName, string $message, array $details = []): self
    {
        return new self($checkName, CheckerInterface::STATUS_PASS, $message, '', $details);
    }

    public static function warn(string $checkName, string $message, string $recommendation = '', array $details = []): self
    {
        return new self($checkName, CheckerInterface::STATUS_WARN, $message, $recommendation, $details);
    }

    public static function fail(string $checkName, string $message, string $recommendation = '', array $details = []): self
    {
        return new self($checkName, CheckerInterface::STATUS_FAIL, $message, $recommendation, $details);
    }

    public function getCheckName(): string
    {
        return $this->checkName;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getRecommendation(): string
    {
        return $this->recommendation;
    }

    public function getDetails(): array
    {
        return $this->details;
    }

    public function isPassed(): bool
    {
        return $this->status === CheckerInterface::STATUS_PASS;
    }

    public function isWarning(): bool
    {
        return $this->status === CheckerInterface::STATUS_WARN;
    }

    public function isFailed(): bool
    {
        return $this->status === CheckerInterface::STATUS_FAIL;
    }

    /**
     * Score contribution: pass=2, warn=1, fail=0
     */
    public function getScore(): int
    {
        return match ($this->status) {
            CheckerInterface::STATUS_PASS => 2,
            CheckerInterface::STATUS_WARN => 1,
            default => 0,
        };
    }
}
