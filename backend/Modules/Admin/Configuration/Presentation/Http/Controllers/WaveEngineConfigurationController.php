<?php

declare(strict_types=1);

namespace Modules\Admin\Configuration\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Configuration\Domain\Services\ConfigAuditService;
use Modules\Operations\Preparation\Application\Services\WaveEngine\CompanyTimezoneResolver;
use Modules\Operations\Preparation\Application\Services\WaveEngine\WaveScheduleResolver;
use Modules\Operations\Preparation\Domain\Models\WaveEngineConfiguration;

/**
 * Configuration OS facade over the Preparation Wave engine — PART 4.
 *
 * Reads/writes the existing `wave_engine_configurations` table. Before this, the table
 * had no API, no UI and no controller: the operational cycle of the entire Preparation
 * module was editable only by hand in the database, which is also how it came to hold
 * 00:00 / 23:59 / 23:59:59 with automatic progression switched off.
 *
 * GET /configuration/wave-engine
 * PUT /configuration/wave-engine/{id}
 *
 * THE THREE TIMES ARE WALL-CLOCK, IN COMPANY-LOCAL TIME, AND MAY CROSS MIDNIGHT.
 * They are not validated against each other: 18:00 / 08:00 / 15:00 is a legitimate cycle
 * that ends on the following day. Ordering is guaranteed downstream instead, by
 * WaveScheduleResolver resolving each boundary as the next occurrence after the previous
 * one — which is why the old CHECK constraint that forbade this shape was dropped.
 *
 * TIMEZONE IS READ-ONLY HERE, DELIBERATELY. `companies.timezone` is the single authority
 * (G-2) and is edited through Company settings. Exposing a second, writable copy on this
 * screen is exactly the duplication that let the engine's own `timezone` column drift to
 * UTC while every company declared Africa/Cairo.
 */
final class WaveEngineConfigurationController extends Controller
{
    use HasApiResponse;

    public function __construct(
        private readonly ConfigAuditService $audit,
        private readonly CompanyTimezoneResolver $timezones,
        private readonly WaveScheduleResolver $schedule,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = (string) (Auth::user()?->company_id ?? '');

        $configs = WaveEngineConfiguration::where('company_id', $companyId)
            ->orderBy('warehouse_id')
            ->get();

        return $this->success([
            'operational_timezone' => $this->timezones->resolve($companyId),
            // Surfaced so the screen can say WHY the engine is idle instead of showing a
            // silently empty list. The scheduler skips a company with no usable timezone.
            'timezone_source' => 'companies.timezone',
            'configurations' => $configs->map(fn (WaveEngineConfiguration $c) => $this->present($c, $companyId))->all(),
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $companyId = (string) (Auth::user()?->company_id ?? '');

        $config = WaveEngineConfiguration::where('company_id', $companyId)->findOrFail($id);

        $validated = $request->validate([
            'collection_start_time' => 'sometimes|required|date_format:H:i',
            'preparation_start_time' => 'sometimes|required|date_format:H:i',
            'wave_end_time' => 'sometimes|required|date_format:H:i',
            'auto_create' => 'sometimes|boolean',
            'auto_assign_orders' => 'sometimes|boolean',
            'auto_move_to_preparing' => 'sometimes|boolean',
            'is_active' => 'sometimes|boolean',
        ]);

        foreach (['collection_start_time', 'preparation_start_time', 'wave_end_time'] as $field) {
            if (isset($validated[$field])) {
                $validated[$field] .= ':00';
            }
        }

        $old = $config->toArray();
        $config->update([...$validated, 'updated_by' => (string) (Auth::id() ?? 'system')]);

        $this->audit->record(
            companyId: $companyId,
            module: 'preparation_wave_engine',
            category: 'operational_cycle',
            action: 'update',
            oldValue: $old,
            newValue: $config->fresh()?->toArray() ?? [],
        );

        return $this->updated(
            $this->present($config->refresh(), $companyId),
            'Wave engine configuration updated.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function present(WaveEngineConfiguration $config, string $companyId): array
    {
        $timezone = $this->timezones->resolve($companyId);

        // A worked preview of the cycle the current settings produce, resolved exactly the
        // way the scheduler resolves it. This is what makes a cross-day window legible on
        // the screen: the operator sees 18:00 → next-day 08:00 → next-day 15:00 rather
        // than three times that look like they run backwards.
        $cycle = $timezone !== null
            ? $this->schedule->resolveCycleAt($config, $timezone, CarbonImmutable::now())
            : null;

        return [
            'id' => $config->id,
            'warehouse_id' => $config->warehouse_id,
            'warehouse_name' => DB::table('warehouses')->where('id', $config->warehouse_id)->value('name'),
            'collection_start_time' => substr($config->collection_start_time, 0, 5),
            'preparation_start_time' => substr($config->preparation_start_time, 0, 5),
            'wave_end_time' => substr($config->wave_end_time, 0, 5),
            'auto_create' => $config->auto_create,
            'auto_assign_orders' => $config->auto_assign_orders,
            'auto_move_to_preparing' => $config->auto_move_to_preparing,
            'eligible_order_statuses' => $config->eligible_order_statuses,
            'is_active' => $config->is_active,
            'operational_timezone' => $timezone,
            'current_cycle' => $cycle === null ? null : [
                'planning_date' => $cycle->planningDate,
                'starts_at' => $cycle->startsAt->toIso8601String(),
                'intake_closes_at' => $cycle->intakeClosesAt->toIso8601String(),
                'ends_at' => $cycle->endsAt->toIso8601String(),
            ],
            // null current_cycle is meaningful: either there is no usable company
            // timezone, or now falls in the gap between two cycles.
            'crosses_midnight' => $cycle !== null
                && $cycle->startsAt->setTimezone($timezone)->toDateString()
                    !== $cycle->endsAt->setTimezone($timezone)->toDateString(),
        ];
    }
}
