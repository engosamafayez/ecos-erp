<?php

declare(strict_types=1);

namespace Modules\Hr\Compensation\Domain\Exceptions;

use RuntimeException;

/** Every way the compensation domain refuses an instruction, named. */
final class CompensationException extends RuntimeException
{
    public static function periodNotRecalculable(string $status): self
    {
        return new self("A period that is {$status} cannot be recalculated.");
    }

    public static function periodClosedToAdjustments(string $status): self
    {
        return new self("A period that is {$status} no longer accepts bonuses, deductions or advances.");
    }

    public static function invalidPeriodTransition(string $from, string $to): self
    {
        return new self("A payroll period cannot move from {$from} to {$to}.");
    }

    public static function invalidRunTransition(string $from, string $to): self
    {
        return new self("A payroll run cannot move from {$from} to {$to}.");
    }

    public static function noSalaryStructure(string $employeeNumber): self
    {
        return new self("Employee {$employeeNumber} has no salary structure in force for this period.");
    }

    public static function invalidApprovalTransition(string $from, string $to): self
    {
        return new self("This item cannot move from {$from} to {$to}.");
    }

    public static function amountMustBePositive(): self
    {
        return new self('The amount must be greater than zero.');
    }

    public static function installmentsRequired(): self
    {
        return new self('An installment advance must state how many installments to recover it over.');
    }

    public static function advanceNotRecoverable(string $status): self
    {
        return new self("An advance that is {$status} cannot be recovered from pay.");
    }

    public static function tiersRequired(): self
    {
        return new self('A tiered commission rule needs at least one tier.');
    }

    public static function unknownMetric(string $metricKey): self
    {
        return new self("\"{$metricKey}\" is not a metric HR knows how to measure.");
    }

    public static function alreadyApproved(): self
    {
        return new self('This payroll run has already been approved; a correction is a new run.');
    }
}
