<?php

declare(strict_types=1);

namespace Modules\Operations\Preparation\Application\Actions;

use App\Core\Audit\AuditService;
use App\Core\FeatureFlags\FeatureFlagService;
use App\Core\Timeline\TimelineService;
use Illuminate\Support\Facades\DB;
use Modules\Operations\Preparation\Application\DTOs\CreateWaveDTO;
use Modules\Operations\Preparation\Domain\Enums\WaveStatus;
use Modules\Operations\Preparation\Domain\Events\WaveCreated;
use Modules\Operations\Preparation\Domain\Models\PreparationWave;
use Modules\Operations\Preparation\Domain\Models\PreparationWaveOrder;

final class CreateWaveAction
{
    public function __construct(
        private readonly AuditService       $audit,
        private readonly TimelineService    $timeline,
        private readonly FeatureFlagService $flags,
    ) {}

    public function execute(CreateWaveDTO $dto): PreparationWave
    {
        $this->guardWorkflowStage($dto->companyId);

        return DB::transaction(function () use ($dto): PreparationWave {
            $waveNumber = $this->generateWaveNumber($dto->companyId, $dto->planningDate);

            $wave = PreparationWave::create([
                'company_id'       => $dto->companyId,
                'warehouse_id'     => $dto->warehouseId,
                'wave_number'      => $waveNumber,
                'planning_date'    => $dto->planningDate,
                'status'           => WaveStatus::Draft->value,
                'orders_count'     => count($dto->orderLines),
                'config_version_id'=> $dto->configVersionId,
                'notes'            => $dto->notes,
                'created_by'       => $dto->actorId,
                'updated_by'       => $dto->actorId,
            ]);

            foreach ($dto->orderLines as $line) {
                PreparationWaveOrder::create([
                    'company_id'             => $dto->companyId,
                    'preparation_wave_id'    => $wave->id,
                    'order_id'               => $line['order_id'],
                    'order_number'           => $line['order_number'],
                    'order_confirmed_at'     => $line['confirmed_at'],
                    'customer_name_snapshot' => isset($line['customer_name'])
                        ? encrypt($line['customer_name'])
                        : null,
                    'delivery_zone_snapshot' => $line['delivery_zone'] ?? null,
                    'added_by'               => $dto->actorId,
                ]);
            }

            event(new WaveCreated(
                waveId:          $wave->id,
                waveNumber:      $waveNumber,
                companyId:       $dto->companyId,
                warehouseId:     $dto->warehouseId,
                planningDate:    $dto->planningDate,
                ordersCount:     count($dto->orderLines),
                orderIds:        array_column($dto->orderLines, 'order_id'),
                createdBy:       $dto->actorId,
                configVersionId: $dto->configVersionId ?? '',
            ));

            $this->timeline->record(
                companyId:   $dto->companyId,
                subjectType: 'PreparationWave',
                subjectId:   $wave->id,
                eventType:   'wave.created',
                title:       "Wave {$waveNumber} created",
                description: count($dto->orderLines) . ' order(s) added for ' . $dto->planningDate,
                actorId:     (int) $dto->actorId,
                sourceModule:'Operations.Preparation',
            );

            $this->audit->record(
                action:      'preparation.wave.created',
                entityType:  'PreparationWave',
                entityId:    $wave->id,
                companyId:   $dto->companyId,
                userId:      (int) $dto->actorId,
                newValues:   ['wave_number' => $waveNumber, 'orders_count' => count($dto->orderLines)],
            );

            return $wave->fresh(['waveOrders']) ?? $wave;
        });
    }

    private function guardWorkflowStage(string $companyId): void
    {
        if ($this->flags->isDisabled('workflow.stages.preparation', $companyId)) {
            abort(503, 'Preparation stage is not enabled in the active fulfillment profile.');
        }
    }

    private function generateWaveNumber(string $companyId, string $planningDate): string
    {
        $yearMonth = date('Ym', strtotime($planningDate));

        $last = PreparationWave::where('company_id', $companyId)
            ->where('wave_number', 'like', "PREP-{$yearMonth}-%")
            ->max('wave_number');

        if ($last === null) {
            $seq = 1;
        } else {
            $parts = explode('-', $last);
            $seq   = ((int) end($parts)) + 1;
        }

        return sprintf('PREP-%s-%06d', $yearMonth, $seq);
    }
}
