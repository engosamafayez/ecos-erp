<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use Tests\TestCase;

/**
 * TASK-PREPARATION-WAVE-ORDERS-END-TO-END-REPAIR-001 — dead-queue ratchet.
 *
 * Every Enterprise Event Platform subscription is dispatched onto a named queue. If no
 * worker consumes that queue the bus still publishes, the job still enqueues, nothing
 * fails and nothing errors — the work simply never happens. That silence is the danger:
 * TASK-PREPARATION-PRODUCT-DEMAND-MISSING-DIAGNOSTIC-001 found 12 `HandleEnterpriseEventJob`
 * jobs stranded on `demand` with 0 delayed and 0 reserved, which presented to the user as
 * an empty Product Demand screen saying "Generate demand first".
 *
 * This is the second occurrence of the class — commit 6b02af60 ("give every dispatched
 * queue a consumer") fixed it for other queues. This test is the ratchet that stops a
 * third: it reads the queue names out of the subscription registration and asserts each
 * one has a `queue:work` consumer in the supervisor configuration.
 *
 * It parses configuration text rather than booting the bus deliberately — the failure mode
 * being guarded is a mismatch between two config files, so the test must compare exactly
 * those two artefacts and must not require a running queue.
 */
final class EventPlatformQueueConsumerTest extends TestCase
{
    private const PROVIDER = 'Modules/Platform/EventPlatform/Infrastructure/Providers/EventPlatformServiceProvider.php';

    private const SUPERVISOR = 'docker/php/supervisord.conf';

    private function read(string $relative): string
    {
        // The supervisor config lives beside the backend, not inside it.
        $path = str_starts_with($relative, 'docker/')
            ? dirname(base_path()).'/'.$relative
            : base_path($relative);

        self::assertFileExists($path, "Expected configuration at [{$path}].");

        return (string) file_get_contents($path);
    }

    /** @return list<string> every queue named by a $bus->subscribe(...) registration */
    private function subscribedQueues(): array
    {
        preg_match_all("/queue:\s*'([a-z0-9_-]+)'/i", $this->read(self::PROVIDER), $m);

        return array_values(array_unique($m[1] ?? []));
    }

    /** @return list<string> every queue named by a supervisor `queue:work --queue=` program */
    private function consumedQueues(): array
    {
        preg_match_all('/queue:work[^\n]*--queue=([a-z0-9_,-]+)/i', $this->read(self::SUPERVISOR), $m);

        $queues = [];
        foreach ($m[1] ?? [] as $csv) {
            // A worker may consume several queues: `--queue=health,default`.
            foreach (explode(',', $csv) as $q) {
                $queues[] = trim($q);
            }
        }

        return array_values(array_unique($queues));
    }

    public function test_the_event_platform_subscribes_to_at_least_one_queue(): void
    {
        // Guards the guard: if the regex ever stops matching, the real assertion below
        // would pass vacuously against an empty set.
        self::assertNotEmpty(
            $this->subscribedQueues(),
            'No subscription queues were parsed — the ratchet would pass vacuously.',
        );
    }

    public function test_every_subscribed_queue_has_a_worker(): void
    {
        $subscribed = $this->subscribedQueues();
        $consumed = $this->consumedQueues();

        $dead = array_values(array_diff($subscribed, $consumed));

        self::assertSame(
            [],
            $dead,
            sprintf(
                'Queue(s) [%s] are subscribed by the Enterprise Event Platform but no supervisor '
                .'worker consumes them. Jobs will enqueue silently and never run — no error, no '
                .'failure, just work that never happens. Add a `queue:work --queue=<name>` program '
                .'to %s. Consumed today: [%s].',
                implode(', ', $dead),
                self::SUPERVISOR,
                implode(', ', $consumed),
            ),
        );
    }

    public function test_the_demand_queue_specifically_has_a_consumer(): void
    {
        // Named explicitly because this is the queue that actually broke, and because
        // every current subscription uses it: losing it disables the whole platform.
        self::assertContains(
            'demand',
            $this->consumedQueues(),
            'The `demand` queue carries every Enterprise Event Platform subscription '
            .'(wave created/closed, order added/removed/moved-to-preparing, demand refresh, '
            .'manufacturing completed, goods receipt completed). Without a worker the entire '
            .'platform is inert and wave_product_demand is never built.',
        );
    }
}
