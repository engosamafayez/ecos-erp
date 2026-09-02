<?php

declare(strict_types=1);

namespace Modules\Collaboration\Domain\Enums;

/**
 * The exact 4-level shape the brief itself suggests (§6), not the heavier
 * 5-level weighted `System\Engineering\TaskPriority` /
 * `ClaudeBridge\TaskPriority` precedent found elsewhere in this codebase —
 * those serve engineering/ops task systems; this is deliberately the
 * simpler shape for a lightweight V1 collaboration task, not a
 * prioritization/scoring engine.
 */
enum TaskPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';
}
