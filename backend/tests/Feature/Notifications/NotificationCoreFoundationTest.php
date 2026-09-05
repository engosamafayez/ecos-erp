<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Modules\Notifications\Domain\ValueObjects\DeepLink;
use Modules\Operations\Preparation\Application\Notifications\ExceptionRaisedNotification;
use Modules\Operations\Preparation\Application\Notifications\QualityCheckFailedNotification;
use Modules\Operations\Preparation\Application\Notifications\ShortageDetectedNotification;
use Modules\Operations\Preparation\Application\Notifications\WaveCompletedNotification;
use Modules\Operations\Preparation\Application\Notifications\WaveStartedNotification;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-NOTIFICATIONS-FOUNDATION-002 — the shared producer contract, its delivery
 * channel, and the schema extension. Complements the pre-existing
 * tests/Feature/Core/NotificationFeedTest.php (ownership/read-lifecycle contract, still
 * green, unmodified) rather than replacing it: that file proves the read side against
 * raw table rows; this one proves the write side against the real dispatch path.
 */
class NotificationCoreFoundationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $otherCompanyUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['company_id' => Company::factory()->create()->id]);
        $this->otherCompanyUser = User::factory()->create(['company_id' => Company::factory()->create()->id]);
    }

    // ── Preparation notifications remain constructible/compatible ──────────────────

    public function test_all_five_preparation_notification_classes_remain_constructible(): void
    {
        $this->assertSame(
            'wave_started',
            (new WaveStartedNotification('W-1', 'wave-1', 'Picker'))->toDatabase($this->user)['type'],
        );
        $this->assertSame(
            'wave_completed',
            (new WaveCompletedNotification('W-1', 'wave-1', 100.0, 3))->toDatabase($this->user)['type'],
        );
        $this->assertSame(
            'shortage_detected',
            (new ShortageDetectedNotification('W-1', 'wave-1', []))->toDatabase($this->user)['type'],
        );
        $this->assertSame(
            'quality_check_failed',
            (new QualityCheckFailedNotification('W-1', 'wave-1', 'SKU-1', 'Widget'))->toDatabase($this->user)['type'],
        );
        $this->assertSame(
            'exception_raised',
            (new ExceptionRaisedNotification('W-1', 'wave-1', 'stock', 'desc', 'high'))->toDatabase($this->user)['type'],
        );
    }

    public function test_the_three_migrated_producers_use_the_shared_channel(): void
    {
        $this->assertSame(['notifications-core'], (new WaveStartedNotification('W-1', 'wave-1', 'Picker'))->via($this->user));
        $this->assertSame(['notifications-core'], (new WaveCompletedNotification('W-1', 'wave-1', 100.0, 3))->via($this->user));
        $this->assertSame(['notifications-core'], (new ShortageDetectedNotification('W-1', 'wave-1', []))->via($this->user));
    }

    // ── Shared channel: metadata columns, company scoping ───────────────────────────

    public function test_sending_populates_the_new_schema_columns_from_producer_metadata(): void
    {
        NotificationFacade::send($this->user, new ShortageDetectedNotification('W-1', 'wave-1', [['material_id' => 'm1']]));

        $row = DB::table('notifications')->where('notifiable_id', $this->user->getKey())->sole();

        $this->assertSame((string) $this->user->company_id, $row->company_id);
        $this->assertSame('high', $row->priority);
        $this->assertSame('exception', $row->category);
        $this->assertSame('Operations', $row->source_module);
        $this->assertSame('shortage_detected:wave-1', $row->dedupe_key);
    }

    public function test_deep_link_round_trips_through_the_api_response(): void
    {
        // A standalone stub, not a Preparation class (all `final`) — this only needs to
        // prove the channel/controller round-trip a deep link correctly.
        $notification = new class extends \Illuminate\Notifications\Notification implements \Modules\Notifications\Domain\Contracts\ProvidesNotificationMetadataInterface {
            use \Modules\Notifications\Application\Concerns\HasNotificationMetadata;

            public function via(mixed $notifiable): array
            {
                return ['notifications-core'];
            }

            public function toDatabase(mixed $notifiable): array
            {
                return ['message' => 'test'];
            }

            public function notificationDeepLink(): ?DeepLink
            {
                return new DeepLink(entityType: 'wave', entityId: 'wave-1', actionKey: 'view');
            }
        };

        NotificationFacade::send($this->user, $notification);

        $response = $this->actingAs($this->user)->getJson('/api/notifications')->assertOk();
        $row = $response->json('data.data.0');

        // assertEquals, not assertSame: MySQL's JSON column type does not guarantee
        // preserving object key order on retrieval — the value is what must match.
        $this->assertEquals(['entity_type' => 'wave', 'entity_id' => 'wave-1', 'action_key' => 'view', 'route' => null], $row['deep_link']);
    }

    // ── Dedupe (ADR-047 §10) ─────────────────────────────────────────────────────────

    public function test_sending_the_same_condition_twice_does_not_create_a_second_row(): void
    {
        NotificationFacade::send($this->user, new WaveStartedNotification('W-1', 'wave-1', 'Picker'));
        NotificationFacade::send($this->user, new WaveStartedNotification('W-1', 'wave-1', 'Picker'));

        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $this->user->getKey())->count());
    }

    public function test_distinct_conditions_are_not_suppressed_by_dedupe(): void
    {
        NotificationFacade::send($this->user, new WaveStartedNotification('W-1', 'wave-1', 'Picker'));
        NotificationFacade::send($this->user, new WaveStartedNotification('W-2', 'wave-2', 'Picker'));

        $this->assertSame(2, DB::table('notifications')->where('notifiable_id', $this->user->getKey())->count());
    }

    public function test_the_same_dedupe_key_for_two_different_recipients_does_not_collide(): void
    {
        // ADR-047 §10: dedupe is per-recipient, not global — this must not throw a
        // unique-constraint violation.
        NotificationFacade::send($this->user, new WaveStartedNotification('W-1', 'wave-1', 'Picker'));
        NotificationFacade::send($this->otherCompanyUser, new WaveStartedNotification('W-1', 'wave-1', 'Packer'));

        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $this->user->getKey())->count());
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $this->otherCompanyUser->getKey())->count());
    }

    // ── Tenant/user isolation (extends the existing ownership contract to company_id) ─

    public function test_company_id_reflects_each_recipients_own_company_not_the_others(): void
    {
        NotificationFacade::send($this->user, new WaveStartedNotification('W-1', 'wave-1', 'Picker'));
        NotificationFacade::send($this->otherCompanyUser, new WaveStartedNotification('W-1', 'wave-1', 'Picker'));

        $mine = DB::table('notifications')->where('notifiable_id', $this->user->getKey())->sole();
        $theirs = DB::table('notifications')->where('notifiable_id', $this->otherCompanyUser->getKey())->sole();

        $this->assertNotSame($mine->company_id, $theirs->company_id);
        $this->assertSame((string) $this->user->company_id, $mine->company_id);
        $this->assertSame((string) $this->otherCompanyUser->company_id, $theirs->company_id);
    }

    public function test_the_feed_still_never_returns_another_users_notification(): void
    {
        NotificationFacade::send($this->user, new WaveStartedNotification('W-1', 'wave-1', 'Picker'));
        NotificationFacade::send($this->otherCompanyUser, new WaveStartedNotification('W-2', 'wave-2', 'Picker'));

        $ids = array_column(
            $this->actingAs($this->user)->getJson('/api/notifications')->json('data.data'),
            'id',
        );

        $this->assertCount(1, $ids);
    }

    // ── Deterministic ordering ───────────────────────────────────────────────────────

    public function test_ordering_is_deterministic_even_when_created_at_ties(): void
    {
        $now = now();
        DB::table('notifications')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'type' => WaveStartedNotification::class,
            'notifiable_type' => $this->user->getMorphClass(),
            'notifiable_id' => $this->user->getKey(),
            'data' => json_encode(['message' => 'first']),
            'created_at' => $now,
        ]);
        DB::table('notifications')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'type' => WaveStartedNotification::class,
            'notifiable_type' => $this->user->getMorphClass(),
            'notifiable_id' => $this->user->getKey(),
            'data' => json_encode(['message' => 'second']),
            'created_at' => $now,
        ]);

        $first = $this->actingAs($this->user)->getJson('/api/notifications')->json('data.data');
        $second = $this->getJson('/api/notifications')->json('data.data');

        $this->assertSame(array_column($first, 'id'), array_column($second, 'id'), 'Same-timestamp rows must sort identically on every request.');
    }

    // ── Mark permitted set as read ───────────────────────────────────────────────────

    public function test_mark_read_set_only_marks_the_ids_given_and_only_the_callers_own(): void
    {
        NotificationFacade::send($this->user, new WaveStartedNotification('W-1', 'wave-1', 'Picker'));
        NotificationFacade::send($this->user, new WaveCompletedNotification('W-2', 'wave-2', 100.0, 1));
        NotificationFacade::send($this->otherCompanyUser, new WaveStartedNotification('W-3', 'wave-3', 'Picker'));

        $mine = DB::table('notifications')->where('notifiable_id', $this->user->getKey())->pluck('id');
        $theirs = DB::table('notifications')->where('notifiable_id', $this->otherCompanyUser->getKey())->value('id');

        $response = $this->actingAs($this->user)
            ->postJson('/api/notifications/mark-read', ['ids' => [$mine->first(), $theirs]])
            ->assertOk();

        $this->assertSame(1, $response->json('data.updated'), 'Only the callers own id among those given may be marked.');
        $this->assertNotNull(DB::table('notifications')->where('id', $mine->first())->value('read_at'));
        $this->assertNull(DB::table('notifications')->where('id', $mine->last())->value('read_at'));
        $this->assertNull(DB::table('notifications')->where('id', $theirs)->value('read_at'));
    }
}
