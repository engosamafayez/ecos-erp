<?php

declare(strict_types=1);

namespace Modules\System\Engineering\Application\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\System\Engineering\Domain\Enums\PipelineStage;
use Modules\System\Engineering\Domain\Enums\PipelineStatus;
use Modules\System\Engineering\Domain\Enums\StageStatus;
use Modules\System\Engineering\Domain\Models\EngineeringPipeline;
use Modules\System\Engineering\Domain\Models\EngineeringPipelineLog;

class ReleasePipelineService
{
    public function __construct(
        private readonly PipelineStageExecutor      $executor,
        private readonly PipelineNotificationService $notifications,
    ) {}

    /**
     * Create a new pipeline (does not start execution).
     * Call dispatch(new ExecutePipelineJob($pipeline->id)) after this.
     *
     * @param array{task_name?:string, branch?:string, initiated_by?:string, initiated_by_user_id?:string|null} $params
     */
    public function create(array $params = []): EngineeringPipeline
    {
        $pipeline = EngineeringPipeline::create([
            'id'                   => Str::uuid()->toString(),
            'task_name'            => $params['task_name'] ?? 'Manual Run',
            'branch'               => $params['branch'] ?? 'main',
            'status'               => PipelineStatus::Pending->value,
            'initiated_by'         => $params['initiated_by'] ?? 'Claude',
            'initiated_by_user_id' => $params['initiated_by_user_id'] ?? null,
            'auto_deploy'          => false,
        ]);

        // Pre-create all stage log records as pending
        foreach (PipelineStage::orderedStages() as $stage) {
            EngineeringPipelineLog::create([
                'id'          => Str::uuid()->toString(),
                'pipeline_id' => $pipeline->id,
                'stage'       => $stage->value,
                'status'      => StageStatus::Pending->value,
            ]);
        }

        return $pipeline;
    }

    /**
     * Run all stages of the pipeline synchronously.
     * Designed to be called from a queued Job.
     */
    public function run(string $pipelineId): void
    {
        $pipeline = EngineeringPipeline::find($pipelineId);

        if ($pipeline === null) {
            Log::error('ReleasePipelineService::run — pipeline not found', ['id' => $pipelineId]);
            return;
        }

        if ($pipeline->isTerminal()) {
            return; // Already done
        }

        $pipeline->update([
            'status'     => PipelineStatus::Running->value,
            'started_at' => now(),
        ]);

        $this->notifications->pipelineStarted($pipeline);

        foreach (PipelineStage::orderedStages() as $stage) {
            $log = $pipeline->logs()->where('stage', $stage->value)->first();

            if ($log === null) {
                continue;
            }

            // Update pipeline's current stage
            $pipeline->update(['current_stage' => $stage->value]);

            // Mark stage running
            $log->update([
                'status'     => StageStatus::Running->value,
                'started_at' => now(),
            ]);

            $startTime = microtime(true);
            $success   = false;
            $retries   = 0;
            $maxRetries = $stage->canAutoRetry() ? $stage->maxRetries() : 0;

            while (true) {
                try {
                    $success = $this->executor->execute($pipeline, $stage, $log);
                } catch (\Throwable $e) {
                    Log::error('Pipeline stage exception', [
                        'pipeline' => $pipelineId,
                        'stage'    => $stage->value,
                        'error'    => $e->getMessage(),
                    ]);
                    $log->update(['message' => ($log->message ?? '') . "\nException: " . $e->getMessage()]);
                    $success = false;
                }

                if ($success || $retries >= $maxRetries) {
                    break;
                }

                $retries++;
                $log->update([
                    'status'      => StageStatus::Retrying->value,
                    'retry_count' => $retries,
                ]);

                sleep(5 * $retries); // Back-off: 5s, 10s, 15s
            }

            $elapsed = (int) round(microtime(true) - $startTime);
            $finalStatus = $log->status === StageStatus::Skipped->value
                ? StageStatus::Skipped
                : ($success ? StageStatus::Success : StageStatus::Failed);

            $log->update([
                'status'           => $finalStatus->value,
                'finished_at'      => now(),
                'duration_seconds' => $elapsed,
            ]);

            if (! $success && $finalStatus !== StageStatus::Skipped) {
                $this->failPipeline($pipeline, $log->message ?? "Stage {$stage->value} failed.");
                return;
            }
        }

        // All stages passed
        $finished = now();
        $pipeline->update([
            'status'           => PipelineStatus::Completed->value,
            'current_stage'    => null,
            'finished_at'      => $finished,
            'duration_seconds' => (int) $finished->diffInSeconds($pipeline->started_at),
        ]);

        $this->notifications->pipelineCompleted($pipeline->fresh());
    }

    public function cancel(string $pipelineId): bool
    {
        $pipeline = EngineeringPipeline::find($pipelineId);

        if ($pipeline === null || $pipeline->isTerminal()) {
            return false;
        }

        $pipeline->update([
            'status'      => PipelineStatus::Cancelled->value,
            'finished_at' => now(),
        ]);

        // Mark any pending/running stages as cancelled
        $pipeline->logs()
            ->whereIn('status', [StageStatus::Pending->value, StageStatus::Running->value])
            ->update(['status' => StageStatus::Cancelled->value]);

        return true;
    }

    public function retry(string $pipelineId): bool
    {
        $pipeline = EngineeringPipeline::find($pipelineId);

        if ($pipeline === null || $pipeline->status !== PipelineStatus::Failed->value) {
            return false;
        }

        // Reset failed stage and all subsequent stages
        $failedStage = $pipeline->current_stage;
        $stages      = PipelineStage::orderedStages();
        $resetFrom   = false;

        foreach ($stages as $stage) {
            if ($stage->value === $failedStage) {
                $resetFrom = true;
            }

            if ($resetFrom) {
                $pipeline->logs()->where('stage', $stage->value)->update([
                    'status'           => StageStatus::Pending->value,
                    'started_at'       => null,
                    'finished_at'      => null,
                    'duration_seconds' => null,
                    'message'          => null,
                    'payload'          => null,
                    'retry_count'      => 0,
                ]);
            }
        }

        $pipeline->update([
            'status'        => PipelineStatus::Pending->value,
            'error_message' => null,
        ]);

        return true;
    }

    private function failPipeline(EngineeringPipeline $pipeline, string $reason): void
    {
        $finished = now();
        $pipeline->update([
            'status'           => PipelineStatus::Failed->value,
            'finished_at'      => $finished,
            'error_message'    => $reason,
            'duration_seconds' => (int) $finished->diffInSeconds($pipeline->started_at),
        ]);

        $this->notifications->pipelineFailed($pipeline->fresh(), $reason);
    }
}
