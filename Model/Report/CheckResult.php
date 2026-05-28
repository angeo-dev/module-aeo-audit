<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Report;

use Angeo\AeoAudit\Api\CheckerInterface;

/**
 * Immutable value object representing the result of a single AEO check.
 *
 * v3 changes:
 *  - Severity defaults derived from weight, can be overridden in the factory.
 *  - Category defaults to "technical" and is propagated to JSON output for
 *    selective rendering on the admin grid and in markdown reports.
 *  - toArray() added so the CLI's JSON / markdown serializers don't need to
 *    introspect getters.
 */
class CheckResult
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        private readonly string $checkName,
        private readonly string $checkCode,
        private readonly string $status,
        private readonly string $message,
        private readonly string $recommendation = '',
        private readonly array  $details = [],
        private readonly float  $weight = 1.0,
        private readonly string $fixCommand = '',
        private readonly string $category = CheckerInterface::CATEGORY_TECHNICAL,
        private readonly string $severity = '',
    ) {
    }

    /**
     * Pass.
     *
     * @param array<string, mixed> $details
     */
    public static function pass(
        string $checkName,
        string $message,
        array  $details = [],
        string $checkCode = '',
        float  $weight = 1.0,
        string $category = CheckerInterface::CATEGORY_TECHNICAL,
        string $severity = '',
    ): self {
        return new self(
            $checkName,
            $checkCode,
            CheckerInterface::STATUS_PASS,
            $message,
            '',
            $details,
            $weight,
            '',
            $category,
            $severity ?: self::deriveSeverity($weight),
        );
    }

    /**
     * Warn.
     *
     * @param array<string, mixed> $details
     */
    public static function warn(
        string $checkName,
        string $message,
        string $recommendation = '',
        array  $details = [],
        string $checkCode = '',
        float  $weight = 1.0,
        string $fixCommand = '',
        string $category = CheckerInterface::CATEGORY_TECHNICAL,
        string $severity = '',
    ): self {
        return new self(
            $checkName,
            $checkCode,
            CheckerInterface::STATUS_WARN,
            $message,
            $recommendation,
            $details,
            $weight,
            $fixCommand,
            $category,
            $severity ?: self::deriveSeverity($weight),
        );
    }

    /**
     * Fail.
     *
     * @param array<string, mixed> $details
     */
    public static function fail(
        string $checkName,
        string $message,
        string $recommendation = '',
        array  $details = [],
        string $checkCode = '',
        float  $weight = 1.0,
        string $fixCommand = '',
        string $category = CheckerInterface::CATEGORY_TECHNICAL,
        string $severity = '',
    ): self {
        return new self(
            $checkName,
            $checkCode,
            CheckerInterface::STATUS_FAIL,
            $message,
            $recommendation,
            $details,
            $weight,
            $fixCommand,
            $category,
            $severity ?: self::deriveSeverity($weight),
        );
    }

    public function getCheckName(): string
    {
        return $this->checkName;
    }

    public function getCheckCode(): string
    {
        return $this->checkCode;
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

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return $this->details;
    }

    public function getWeight(): float
    {
        return $this->weight;
    }

    public function getFixCommand(): string
    {
        return $this->fixCommand;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getSeverity(): string
    {
        return $this->severity ?: self::deriveSeverity($this->weight);
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
     * Weighted score contribution: pass=weight, warn=weight*0.5, fail=0.
     */
    public function getWeightedScore(): float
    {
        return match ($this->status) {
            CheckerInterface::STATUS_PASS => $this->weight,
            CheckerInterface::STATUS_WARN => $this->weight * 0.5,
            default                       => 0.0,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'check_name'     => $this->checkName,
            'check_code'     => $this->checkCode,
            'status'         => $this->status,
            'message'        => $this->message,
            'recommendation' => $this->recommendation,
            'details'        => $this->details,
            'weight'         => $this->weight,
            'fix_command'    => $this->fixCommand,
            'category'       => $this->category,
            'severity'       => $this->getSeverity(),
        ];
    }

    private static function deriveSeverity(float $weight): string
    {
        return match (true) {
            $weight >= 0.8 => CheckerInterface::SEVERITY_CRITICAL,
            $weight >= 0.6 => CheckerInterface::SEVERITY_IMPORTANT,
            default        => CheckerInterface::SEVERITY_INFORMATIONAL,
        };
    }
}
