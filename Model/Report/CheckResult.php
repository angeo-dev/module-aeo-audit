<?php

declare(strict_types=1);

namespace Angeo\AeoAudit\Model\Report;

use Angeo\AeoAudit\Api\CheckerInterface;

/**
 * Immutable Value Object representing the result of a single AEO check.
 */
class CheckResult
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
    ) {
    }

    /**
     * Create a passing check result.
     *
     * @param string $checkName
     * @param string $message
     * @param array  $details
     * @param string $checkCode
     * @param float  $weight
     * @return self
     */
    public static function pass(
        string $checkName,
        string $message,
        array  $details = [],
        string $checkCode = '',
        float  $weight = 1.0,
    ): self {
        return new self($checkName, $checkCode, CheckerInterface::STATUS_PASS, $message, '', $details, $weight);
    }

    /**
     * Create a warning check result.
     *
     * @param string $checkName
     * @param string $message
     * @param string $recommendation
     * @param array  $details
     * @param string $checkCode
     * @param float  $weight
     * @param string $fixCommand
     * @return self
     */
    public static function warn(
        string $checkName,
        string $message,
        string $recommendation = '',
        array  $details = [],
        string $checkCode = '',
        float  $weight = 1.0,
        string $fixCommand = '',
    ): self {
        return new self(
            $checkName,
            $checkCode,
            CheckerInterface::STATUS_WARN,
            $message,
            $recommendation,
            $details,
            $weight,
            $fixCommand
        );
    }

    /**
     * Create a failing check result.
     *
     * @param string $checkName
     * @param string $message
     * @param string $recommendation
     * @param array  $details
     * @param string $checkCode
     * @param float  $weight
     * @param string $fixCommand
     * @return self
     */
    public static function fail(
        string $checkName,
        string $message,
        string $recommendation = '',
        array  $details = [],
        string $checkCode = '',
        float  $weight = 1.0,
        string $fixCommand = '',
    ): self {
        return new self(
            $checkName,
            $checkCode,
            CheckerInterface::STATUS_FAIL,
            $message,
            $recommendation,
            $details,
            $weight,
            $fixCommand
        );
    }

    /**
     * Get check name.
     *
     * @return string
     */
    public function getCheckName(): string
    {
        return $this->checkName;
    }
    /**
     * Get check code.
     *
     * @return string
     */
    public function getCheckCode(): string
    {
        return $this->checkCode;
    }
    /**
     * Get check status.
     *
     * @return string
     */
    public function getStatus(): string
    {
        return $this->status;
    }
    /**
     * Get check message.
     *
     * @return string
     */
    public function getMessage(): string
    {
        return $this->message;
    }
    /**
     * Get recommendation.
     *
     * @return string
     */
    public function getRecommendation(): string
    {
        return $this->recommendation;
    }
    /**
     * Get details array.
     *
     * @return array
     */
    public function getDetails(): array
    {
        return $this->details;
    }
    /**
     * Get check weight.
     *
     * @return float
     */
    public function getWeight(): float
    {
        return $this->weight;
    }
    /**
     * Get fix command.
     *
     * @return string
     */
    public function getFixCommand(): string
    {
        return $this->fixCommand;
    }

    /**
     * Check if result is passing.
     *
     * @return bool
     */
    public function isPassed(): bool
    {
        return $this->status === CheckerInterface::STATUS_PASS;
    }
    /**
     * Check if result is warning.
     *
     * @return bool
     */
    public function isWarning(): bool
    {
        return $this->status === CheckerInterface::STATUS_WARN;
    }
    /**
     * Check if result is failing.
     *
     * @return bool
     */
    public function isFailed(): bool
    {
        return $this->status === CheckerInterface::STATUS_FAIL;
    }

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
