<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Report;

use Angeo\AeoAudit\Api\CheckerInterface;

/**
 * Immutable Value Object representing the result of a single AEO check.
 */
final class CheckResult
{
    public function __construct(
        private readonly string $checkName,
        private readonly string $checkCode,
        private readonly string $status,
        private readonly string $message,
        private readonly string $recommendation = '',
        private readonly array  $details = [],
        private readonly float  $weight = 1.0,
        private readonly string $fixCommand = '',
    ) {}

    public static function pass(
        string $checkName,
        string $message,
        array  $details = [],
        string $checkCode = '',
        float  $weight = 1.0,
    ): self {
        return new self($checkName, $checkCode, CheckerInterface::STATUS_PASS, $message, '', $details, $weight);
    }

    public static function warn(
        string $checkName,
        string $message,
        string $recommendation = '',
        array  $details = [],
        string $checkCode = '',
        float  $weight = 1.0,
        string $fixCommand = '',
    ): self {
        return new self($checkName, $checkCode, CheckerInterface::STATUS_WARN, $message, $recommendation, $details, $weight, $fixCommand);
    }

    public static function fail(
        string $checkName,
        string $message,
        string $recommendation = '',
        array  $details = [],
        string $checkCode = '',
        float  $weight = 1.0,
        string $fixCommand = '',
    ): self {
        return new self($checkName, $checkCode, CheckerInterface::STATUS_FAIL, $message, $recommendation, $details, $weight, $fixCommand);
    }

    public function getCheckName(): string    { return $this->checkName; }
    public function getCheckCode(): string    { return $this->checkCode; }
    public function getStatus(): string       { return $this->status; }
    public function getMessage(): string      { return $this->message; }
    public function getRecommendation(): string { return $this->recommendation; }
    public function getDetails(): array       { return $this->details; }
    public function getWeight(): float        { return $this->weight; }
    public function getFixCommand(): string    { return $this->fixCommand; }

    public function isPassed(): bool  { return $this->status === CheckerInterface::STATUS_PASS; }
    public function isWarning(): bool { return $this->status === CheckerInterface::STATUS_WARN; }
    public function isFailed(): bool  { return $this->status === CheckerInterface::STATUS_FAIL; }

    /**
     * Weighted score contribution: pass=weight, warn=weight*0.5, fail=0
     */
    public function getWeightedScore(): float
    {
        return match ($this->status) {
            CheckerInterface::STATUS_PASS => $this->weight,
            CheckerInterface::STATUS_WARN => $this->weight * 0.5,
            default                       => 0.0,
        };
    }
}
