<?php

declare(strict_types=1);

namespace Tests\Feature\POS\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\POS\Session\Domain\Contracts\SessionRepositoryInterface;
use Modules\POS\Session\Domain\Enums\DeviceType;
use Modules\POS\Session\Domain\Models\Session;
use Modules\POS\Session\Domain\ValueObjects\DeviceFingerprint;
use Modules\POS\Shift\Domain\Contracts\ShiftRepositoryInterface;
use Tests\TestCase;

/**
 * PKG-POS-018: Shift API endpoints.
 */
final class ShiftApiTest extends TestCase
{
    use RefreshDatabase;

    private User   $user;
    private string $sessionId;

    private const TERMINAL_ID = 'a0000000-0000-4000-a000-000000000002';
    private const CASHIER_ID  = 'b0000000-0000-4000-b000-000000000002';

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $sessionRepo = app(SessionRepositoryInterface::class);
        $session     = Session::open(
            terminalId:  self::TERMINAL_ID,
            cashierId:   self::CASHIER_ID,
            fingerprint: DeviceFingerprint::of('fp-shift-test'),
            ipAddress:   '127.0.0.1',
            deviceType:  DeviceType::Browser,
        );
        $sessionRepo->save($session);
        $this->sessionId = (string) $session->id;
    }

    public function test_open_shift_returns_201_with_shift_data(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/pos/shifts', [
                'session_id'           => $this->sessionId,
                'terminal_id'          => self::TERMINAL_ID,
                'cashier_id'           => self::CASHIER_ID,
                'opening_cash'         => ['amount' => '500.00', 'currency' => 'EGP'],
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.session_id', $this->sessionId)
            ->assertJsonPath('data.shift_number', 1);
    }

    public function test_open_shift_validates_required_fields(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/pos/shifts', []);

        $response->assertStatus(422);
    }

    public function test_get_shift_returns_shift_data(): void
    {
        $openResponse = $this->actingAs($this->user)
            ->postJson('/api/pos/shifts', [
                'session_id'  => $this->sessionId,
                'terminal_id' => self::TERMINAL_ID,
                'cashier_id'  => self::CASHIER_ID,
                'opening_cash'=> ['amount' => '200.00', 'currency' => 'EGP'],
            ]);

        $shiftId = $openResponse->json('data.id');

        $response = $this->actingAs($this->user)
            ->getJson('/api/pos/shifts/' . $shiftId);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $shiftId);
    }

    public function test_get_shift_returns_404_when_not_found(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/pos/shifts/00000000-0000-4000-0000-000000000000');

        $response->assertStatus(404);
    }
}
