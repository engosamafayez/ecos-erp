<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Modules\Core\UserPreferences\Domain\Models\UserPreference;
use Modules\Operations\Preparation\Application\Notifications\WaveStartedNotification;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-NOTIFICATIONS-FINAL-USER-REVIEW-REMEDIATION-010 §5/§8.
 *
 * §5: the Unread/Read workspace split needs a real "read only" server filter that did
 * not exist before this task (only "unread only" did) — proven here directly against
 * NotificationController::index(), not assumed from the diff.
 * §8: the new /api/notifications/type-catalog endpoint, proven to both list the real
 * catalog and correctly reflect a user's own override.
 */
final class NotificationReadFilterAndCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create(['company_id' => Company::factory()->create()->id]);
    }

    public function test_unread_query_param_absent_returns_both_read_and_unread(): void
    {
        $user = $this->user();
        NotificationFacade::send($user, new WaveStartedNotification('W-1', 'W-1', 'picker'));
        NotificationFacade::send($user, new WaveStartedNotification('W-2', 'W-2', 'picker'));
        $user->notifications()->first()->markAsRead();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/notifications');

        $response->assertOk();
        $this->assertCount(2, $response->json('data.data'));
    }

    public function test_unread_equals_one_returns_only_unread(): void
    {
        $user = $this->user();
        NotificationFacade::send($user, new WaveStartedNotification('W-1', 'W-1', 'picker'));
        NotificationFacade::send($user, new WaveStartedNotification('W-2', 'W-2', 'picker'));
        $readOne = $user->notifications()->first();
        $readOne->markAsRead();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/notifications?unread=1');

        $response->assertOk();
        $rows = $response->json('data.data');
        $this->assertCount(1, $rows);
        $this->assertNotSame($readOne->id, $rows[0]['id']);
    }

    public function test_unread_equals_zero_returns_only_read(): void
    {
        $user = $this->user();
        NotificationFacade::send($user, new WaveStartedNotification('W-1', 'W-1', 'picker'));
        NotificationFacade::send($user, new WaveStartedNotification('W-2', 'W-2', 'picker'));
        $readOne = $user->notifications()->first();
        $readOne->markAsRead();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/notifications?unread=0');

        $response->assertOk();
        $rows = $response->json('data.data');
        $this->assertCount(1, $rows);
        $this->assertSame($readOne->id, $rows[0]['id']);
    }

    public function test_type_catalog_lists_the_real_types_grouped_by_module(): void
    {
        $user = $this->user();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/notifications/type-catalog');

        $response->assertOk();
        $rows = $response->json('data');
        $keys = array_column($rows, 'key');

        $this->assertContains('pricing_review_required', $keys);
        $this->assertContains('driver_assigned', $keys);
        $this->assertContains('wave_started', $keys);
        // Orphaned/never-dispatched classes must never appear — §8's "no invented types".
        $this->assertNotContains('exception_raised', $keys);
        $this->assertNotContains('quality_check_failed', $keys);

        $waveStarted = collect($rows)->firstWhere('key', 'wave_started');
        $this->assertSame('preparation', $waveStarted['module']);
        $this->assertTrue($waveStarted['enabled']);
    }

    public function test_type_catalog_reflects_the_users_own_disabled_override(): void
    {
        $user = $this->user();
        UserPreference::query()->create([
            'user_id' => $user->id,
            'category' => 'notifications',
            'payload' => ['type_overrides' => ['wave_started' => false]],
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/notifications/type-catalog');

        $waveStarted = collect($response->json('data'))->firstWhere('key', 'wave_started');
        $this->assertFalse($waveStarted['enabled']);

        // A DIFFERENT user's preference must never leak into this one's view of the catalog.
        $otherUser = $this->user();
        $otherResponse = $this->actingAs($otherUser, 'sanctum')->getJson('/api/notifications/type-catalog');
        $otherWaveStarted = collect($otherResponse->json('data'))->firstWhere('key', 'wave_started');
        $this->assertTrue($otherWaveStarted['enabled']);
    }
}
