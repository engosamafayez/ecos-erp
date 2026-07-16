<?php

declare(strict_types=1);

namespace Modules\Operations\Distribution\Presentation\Http\Resources;

class DistributionBoardResource
{
    public function __construct(
        private readonly object $wave,
        private readonly array $summary,
    ) {}

    public function resolve(): array
    {
        return [
            'id'               => $this->wave->id,
            'wave_number'      => $this->wave->wave_number,
            'planning_date'    => $this->wave->planning_date,
            'status'           => $this->wave->status,
            'orders_count'     => $this->wave->orders_count,
            'warehouse_id'     => $this->wave->warehouse_id,
            'created_at'       => $this->wave->created_at,
            'summary'          => $this->summary,
        ];
    }
}
