<?php

declare(strict_types=1);

namespace Modules\Hr\Recruitment\Domain\Exceptions;

use RuntimeException;

/** Every way the recruitment domain refuses an instruction, named. */
final class RecruitmentException extends RuntimeException
{
    public static function jobNotAcceptingApplications(): self
    {
        return new self('This job opening is not accepting applications.');
    }

    public static function alreadyApplied(): self
    {
        return new self('This applicant has already applied to this opening.');
    }

    public static function invalidJobTransition(string $from, string $to): self
    {
        return new self("A job opening cannot move from {$from} to {$to}.");
    }

    public static function invalidApplicationTransition(string $from, string $to): self
    {
        return new self("An application cannot move from {$from} to {$to}.");
    }

    public static function noPipelineConfigured(): self
    {
        return new self('No recruitment stages are configured for this company.');
    }

    public static function stageNotInPipeline(): self
    {
        return new self('That stage does not belong to this company\'s pipeline.');
    }

    public static function notReadyToHire(string $status): self
    {
        return new self("An application that is {$status} cannot be hired; it must be accepted first.");
    }

    public static function alreadyHired(): self
    {
        return new self('This applicant has already been hired.');
    }

    public static function cannotMergeIntoSelf(): self
    {
        return new self('An applicant cannot be merged into themselves.');
    }

    public static function applicantAlreadyMerged(): self
    {
        return new self('This applicant has already been merged into another record.');
    }

    public static function interviewNotCompleted(): self
    {
        return new self('A decision can only be recorded on a completed interview.');
    }

    public static function contactRequired(): self
    {
        return new self('An applicant needs at least a mobile number to be reachable.');
    }
}
