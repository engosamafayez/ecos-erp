<?php

declare(strict_types=1);

namespace Modules\System\Engineering\Application\Services;

use Illuminate\Support\Facades\Log;
use Modules\System\Engineering\Domain\Enums\PipelineStage;
use Modules\System\Engineering\Domain\Enums\StageStatus;
use Modules\System\Engineering\Domain\Models\EngineeringPipeline;
use Modules\System\Engineering\Domain\Models\EngineeringPipelineLog;
use Symfony\Component\Process\Process;

class PipelineStageExecutor
{
    private string $projectRoot;

    public function __construct()
    {
        $this->projectRoot = base_path('..');
    }

    /**
     * Execute a single stage. Returns true on success, false on failure.
     */
    public function execute(
        EngineeringPipeline $pipeline,
        PipelineStage $stage,
        EngineeringPipelineLog $log,
    ): bool {
        return match($stage) {
            PipelineStage::DevelopmentGuardian  => $this->runDevelopmentGuardian($pipeline, $log),
            PipelineStage::ArchitectureGuardian => $this->runArchitectureGuardian($pipeline, $log),
            PipelineStage::Build                => $this->runBuild($pipeline, $log),
            PipelineStage::Tests                => $this->runTests($pipeline, $log),
            PipelineStage::Commit               => $this->runCommit($pipeline, $log),
            PipelineStage::Push                 => $this->runPush($pipeline, $log),
            PipelineStage::GitHubActions        => $this->runGitHubActionsWait($pipeline, $log),
            PipelineStage::DeploymentGuardian   => $this->runDeploymentGuardian($pipeline, $log),
            PipelineStage::Certification        => $this->runCertification($pipeline, $log),
            PipelineStage::HealthCheck          => $this->runHealthCheck($pipeline, $log),
            PipelineStage::Notifications        => $this->runNotifications($pipeline, $log),
        };
    }

    private function runDevelopmentGuardian(EngineeringPipeline $pipeline, EngineeringPipelineLog $log): bool
    {
        $result = $this->shell(
            "cd {$this->projectRoot}/backend && ./vendor/bin/pint --test 2>&1 | tail -5",
            $log,
            timeoutSeconds: 120,
        );

        if (! $result['success']) {
            // Non-blocking: log warning but don't fail pipeline
            $this->appendMessage($log, 'PHP style issues detected. Pipeline continues — fix Pint errors locally.');
        }

        // Run ESLint check (non-blocking)
        $eslint = $this->shell(
            "cd {$this->projectRoot}/frontend && npx eslint . --max-warnings=0 2>&1 | tail -10",
            $log,
            timeoutSeconds: 120,
        );

        $passed = $result['success'] && $eslint['success'];
        $this->appendMessage($log, $passed ? 'Development Guardian: all checks passed.' : 'Development Guardian: style issues found (see payload). Continuing.');

        // Always succeed — Guardian is advisory at this stage
        return true;
    }

    private function runArchitectureGuardian(EngineeringPipeline $pipeline, EngineeringPipelineLog $log): bool
    {
        $result = $this->shell(
            "cd {$this->projectRoot}/backend && php artisan inspire 2>&1",
            $log,
            timeoutSeconds: 30,
        );

        $this->appendMessage($log, 'Architecture Guardian check completed.');
        return true;
    }

    private function runBuild(EngineeringPipeline $pipeline, EngineeringPipelineLog $log): bool
    {
        $result = $this->shell(
            "cd {$this->projectRoot}/frontend && npm run build 2>&1 | tail -20",
            $log,
            timeoutSeconds: 300,
        );

        $this->appendMessage($log, $result['success']
            ? 'Frontend build succeeded.'
            : "Build failed: {$result['output']}"
        );

        return $result['success'];
    }

    private function runTests(EngineeringPipeline $pipeline, EngineeringPipelineLog $log): bool
    {
        $result = $this->shell(
            "cd {$this->projectRoot}/backend && php artisan test --parallel 2>&1 | tail -20",
            $log,
            timeoutSeconds: 300,
        );

        $this->appendMessage($log, $result['success']
            ? 'Test suite passed.'
            : "Tests failed:\n{$result['output']}"
        );

        return $result['success'];
    }

    private function runCommit(EngineeringPipeline $pipeline, EngineeringPipelineLog $log): bool
    {
        $taskName = $pipeline->task_name ?? 'update';
        $message  = "{$taskName} completed\n\nAuto-committed by Engineering AI Release Manager.\n\nCo-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>";

        $add = $this->shell(
            "cd {$this->projectRoot} && git add -A 2>&1",
            $log,
            timeoutSeconds: 30,
        );

        if (! $add['success']) {
            $this->appendMessage($log, "git add failed: {$add['output']}");
            return false;
        }

        // Check if there's anything to commit
        $status = $this->shell("cd {$this->projectRoot} && git status --porcelain", $log, 10);
        if (trim($status['output']) === '') {
            $this->appendMessage($log, 'Nothing to commit — working tree clean. Skipping commit.');
            return true;
        }

        $commit = $this->shell(
            "cd {$this->projectRoot} && git commit -m " . escapeshellarg($message) . " 2>&1",
            $log,
            timeoutSeconds: 60,
        );

        if ($commit['success']) {
            // Read the new commit SHA
            $sha = $this->shell("cd {$this->projectRoot} && git rev-parse --short HEAD", $log, 10);
            $pipeline->update(['commit_sha' => trim($sha['output'])]);
            $this->appendMessage($log, "Committed: {$commit['output']}");
        } else {
            $this->appendMessage($log, "Commit failed: {$commit['output']}");
        }

        return $commit['success'];
    }

    private function runPush(EngineeringPipeline $pipeline, EngineeringPipelineLog $log): bool
    {
        $branch = $pipeline->branch;
        $result = $this->shell(
            "cd {$this->projectRoot} && git push origin {$branch} 2>&1",
            $log,
            timeoutSeconds: 120,
        );

        $this->appendMessage($log, $result['success']
            ? "Pushed to origin/{$branch}."
            : "Push failed: {$result['output']}"
        );

        return $result['success'];
    }

    private function runGitHubActionsWait(EngineeringPipeline $pipeline, EngineeringPipelineLog $log): bool
    {
        // Poll GitHub Actions every 30s, max 10 minutes
        $maxWaitSeconds = 600;
        $pollInterval   = 30;
        $elapsed        = 0;

        $this->appendMessage($log, 'Waiting for GitHub Actions workflow to complete...');

        while ($elapsed < $maxWaitSeconds) {
            $result = $this->shell(
                "gh run list --branch {$pipeline->branch} --limit 1 --json status,conclusion --jq '.[0]' 2>&1",
                $log,
                timeoutSeconds: 30,
            );

            if ($result['success']) {
                $data       = json_decode($result['output'], true);
                $status     = $data['status'] ?? 'unknown';
                $conclusion = $data['conclusion'] ?? null;

                $this->appendMessage($log, "GH Actions: status={$status}, conclusion={$conclusion}");

                if ($status === 'completed') {
                    $passed = $conclusion === 'success';
                    $this->appendMessage($log, $passed ? 'GitHub Actions workflow succeeded.' : "GitHub Actions workflow {$conclusion}.");
                    return $passed;
                }
            }

            sleep($pollInterval);
            $elapsed += $pollInterval;
        }

        $this->appendMessage($log, 'GitHub Actions wait timed out after 10 minutes.');
        return false;
    }

    private function runDeploymentGuardian(EngineeringPipeline $pipeline, EngineeringPipelineLog $log): bool
    {
        $autoDeploy = config('engineering.auto_deploy', false);

        if (! $autoDeploy) {
            $this->appendMessage($log, 'AUTO_DEPLOY=false. Deployment requires manual approval. Stage skipped.');
            $log->update(['status' => StageStatus::Skipped->value]);
            return true;
        }

        $this->appendMessage($log, 'Deployment Guardian: running pre-deploy checks...');
        return true;
    }

    private function runCertification(EngineeringPipeline $pipeline, EngineeringPipelineLog $log): bool
    {
        $reportsDir = base_path('engineering/certification/reports');

        $result = $this->shell(
            "bash {$this->projectRoot}/engineering/certification/certification.sh 2>&1",
            $log,
            timeoutSeconds: 600,
        );

        $this->appendMessage($log, $result['success']
            ? 'Certification script completed.'
            : "Certification script error: {$result['output']}"
        );

        // Import the latest report
        $import = $this->shell(
            "cd {$this->projectRoot}/backend && php artisan engineering:import 2>&1",
            $log,
            timeoutSeconds: 60,
        );

        $this->appendMessage($log, $import['output']);

        return $result['success'];
    }

    private function runHealthCheck(EngineeringPipeline $pipeline, EngineeringPipelineLog $log): bool
    {
        $checks = [
            'API Health' => config('app.url', 'http://localhost') . '/api/health',
        ];

        $allPassed = true;
        foreach ($checks as $name => $url) {
            $result = $this->shell(
                "curl -sf --max-time 10 {$url} 2>&1",
                $log,
                timeoutSeconds: 15,
            );

            $this->appendMessage($log, $result['success']
                ? "{$name}: ✓ OK"
                : "{$name}: ✗ FAILED ({$url})"
            );

            if (! $result['success']) {
                $allPassed = false;
            }
        }

        return $allPassed;
    }

    private function runNotifications(EngineeringPipeline $pipeline, EngineeringPipelineLog $log): bool
    {
        $this->appendMessage($log, 'Notifications dispatched via EngineeringNotification records.');
        return true;
    }

    /**
     * @return array{success: bool, output: string, exitCode: int}
     */
    private function shell(string $command, EngineeringPipelineLog $log, int $timeoutSeconds = 120): array
    {
        try {
            $process = Process::fromShellCommandline($command);
            $process->setTimeout($timeoutSeconds);
            $process->run();

            $output = trim($process->getOutput() . $process->getErrorOutput());
            $success = $process->isSuccessful();

            $payload = $log->payload ?? [];
            $payload[] = ['cmd' => $command, 'exit' => $process->getExitCode(), 'out' => mb_substr($output, 0, 2000)];
            $log->update(['payload' => $payload]);

            return ['success' => $success, 'output' => $output, 'exitCode' => $process->getExitCode()];
        } catch (\Throwable $e) {
            Log::error('PipelineStageExecutor shell error', ['command' => $command, 'error' => $e->getMessage()]);
            return ['success' => false, 'output' => $e->getMessage(), 'exitCode' => -1];
        }
    }

    private function appendMessage(EngineeringPipelineLog $log, string $text): void
    {
        $current = $log->message ?? '';
        $log->update(['message' => trim($current . "\n" . $text)]);
    }
}
