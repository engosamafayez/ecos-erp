<?php

declare(strict_types=1);

namespace Modules\Finance\CostAllocation\Domain\Enums;

/**
 * V1 allocation basis — deliberately minimal (TASK §21): fixed amount or
 * percentage only. No activity-based driver (hours, square meters, machine
 * time) is modelled — none is source-approved.
 */
enum CostAllocationMethod: string
{
    case Fixed = 'fixed';
    case Percentage = 'percentage';
}
