<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-DELIVERY-POD-SECURE-UPLOAD-001 — the SECURE driver delivery proof-of-delivery
 * upload + retrieval.
 *
 * The legacy POST /driver/stops/{id}/proof accepted client-supplied path STRINGS and
 * an empty body as "proof". This suite pins the new, safe contract:
 *   POST /driver/stops/{id}/delivery-proof     — real multipart upload
 *   GET  /driver/stops/{id}/delivery-proof/...  — tenant-scoped retrieval
 *
 * Everything runs over the real driver stack (HTTP → DriverRuntimeController →
 * UploadDeliveryProofAction), through the identity/ownership guards.
 */
final class DriverDeliveryProofSecureUploadTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Warehouse $warehouse;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
        $this->customer = Customer::factory()->create();
    }

    private function url(string $stopUuid): string
    {
        return '/api/driver/stops/'.$stopUuid.'/delivery-proof';
    }

    // ── Happy path + server-controlled storage ────────────────────────────────

    public function test_a_valid_multipart_upload_is_stored_under_a_server_generated_private_path(): void
    {
        Storage::fake('local');
        $a = $this->driverStop();

        $response = $this->actingAs($a['user'])->postJson($this->url($a['stop_uuid']), [
            'signature' => UploadedFile::fake()->image('signature.jpg'),
            'photos' => [UploadedFile::fake()->image('door.jpg')],
            'notes' => 'Left with the customer.',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.has_signature', true)
            ->assertJsonPath('data.photo_count', 1);

        $proof = DB::table('distribution_delivery_proofs')->where('stop_id', $a['stop_id'])->first();
        self::assertNotNull($proof, 'a proof row was written');
        self::assertSame('local', $proof->storage_disk, 'stored on the PRIVATE disk');

        // The stored path is SERVER-generated (company-scoped ULID), never the client filename.
        self::assertStringStartsWith('delivery-proofs/'.$this->company->id.'/', (string) $proof->signature_path);
        self::assertStringNotContainsString('signature.jpg', (string) $proof->signature_path);
        Storage::disk('local')->assertExists((string) $proof->signature_path);

        $photos = json_decode((string) $proof->photos, true);
        self::assertCount(1, $photos);
        self::assertStringStartsWith('delivery-proofs/'.$this->company->id.'/', $photos[0]['path']);
        Storage::disk('local')->assertExists($photos[0]['path']);
    }

    public function test_a_signature_only_upload_is_accepted(): void
    {
        Storage::fake('local');
        $a = $this->driverStop();

        $this->actingAs($a['user'])->postJson($this->url($a['stop_uuid']), [
            'signature' => UploadedFile::fake()->image('sig.png'),
        ])->assertStatus(201)->assertJsonPath('data.photo_count', 0);
    }

    // ── The holes the legacy contract left open ───────────────────────────────

    public function test_an_empty_upload_is_rejected(): void
    {
        Storage::fake('local');
        $a = $this->driverStop();

        $this->actingAs($a['user'])->postJson($this->url($a['stop_uuid']), [
            'notes' => 'nothing attached',
        ])->assertStatus(422);

        self::assertSame(0, DB::table('distribution_delivery_proofs')->where('stop_id', $a['stop_id'])->count());
    }

    public function test_an_arbitrary_client_path_string_is_not_accepted_as_proof(): void
    {
        Storage::fake('local');
        $a = $this->driverStop();

        // The old attack: pass a path string. The new endpoint accepts only real files,
        // so a string 'signature' fails file validation and no proof is created.
        $this->actingAs($a['user'])->postJson($this->url($a['stop_uuid']), [
            'signature' => 'proofs/evil.png',
            'photos' => ['proofs/a.jpg', 'proofs/b.jpg'],
        ])->assertStatus(422);

        self::assertSame(0, DB::table('distribution_delivery_proofs')->where('stop_id', $a['stop_id'])->count());
    }

    public function test_an_invalid_mime_is_rejected(): void
    {
        Storage::fake('local');
        $a = $this->driverStop();

        $this->actingAs($a['user'])->postJson($this->url($a['stop_uuid']), [
            'signature' => UploadedFile::fake()->create('evil.txt', 10, 'text/plain'),
        ])->assertStatus(422);
    }

    public function test_an_oversized_file_is_rejected(): void
    {
        Storage::fake('local');
        $a = $this->driverStop();

        $this->actingAs($a['user'])->postJson($this->url($a['stop_uuid']), [
            'signature' => UploadedFile::fake()->image('big.jpg')->size(11000), // > 10240 KB
        ])->assertStatus(422);
    }

    // ── Tenancy / ownership / auth ────────────────────────────────────────────

    public function test_a_driver_cannot_upload_a_proof_to_another_drivers_stop(): void
    {
        Storage::fake('local');
        $a = $this->driverStop();
        $b = $this->driverStop();

        // Driver B aims at driver A's stop — fail-closed via ownedStop().
        $this->actingAs($b['user'])->postJson($this->url($a['stop_uuid']), [
            'signature' => UploadedFile::fake()->image('sig.jpg'),
        ])->assertStatus(404);

        self::assertSame(0, DB::table('distribution_delivery_proofs')->where('stop_id', $a['stop_id'])->count());
    }

    public function test_a_non_driver_user_is_refused(): void
    {
        Storage::fake('local');
        $a = $this->driverStop();
        $notADriver = User::factory()->create(['company_id' => $this->company->id]);

        $this->actingAs($notADriver)->postJson($this->url($a['stop_uuid']), [
            'signature' => UploadedFile::fake()->image('sig.jpg'),
        ])->assertStatus(403);
    }

    public function test_unauthenticated_upload_is_denied(): void
    {
        $a = $this->driverStop();

        $this->postJson($this->url($a['stop_uuid']), [
            'signature' => UploadedFile::fake()->image('sig.jpg'),
        ])->assertStatus(401);
    }

    // ── Secure retrieval ──────────────────────────────────────────────────────

    public function test_a_stored_proof_is_retrievable_only_through_the_tenant_scoped_download(): void
    {
        Storage::fake('local');
        $a = $this->driverStop();

        $this->actingAs($a['user'])->postJson($this->url($a['stop_uuid']), [
            'signature' => UploadedFile::fake()->image('sig.jpg'),
            'photos' => [UploadedFile::fake()->image('door.jpg')],
        ])->assertStatus(201);

        // The owner streams their own signature + photo …
        $this->actingAs($a['user'])
            ->get('/api/driver/stops/'.$a['stop_uuid'].'/delivery-proof/signature')
            ->assertStatus(200);
        $this->actingAs($a['user'])
            ->get('/api/driver/stops/'.$a['stop_uuid'].'/delivery-proof/photo/0')
            ->assertStatus(200);

        // … a non-existent photo index is a 404, never a path-traversal read …
        $this->actingAs($a['user'])
            ->get('/api/driver/stops/'.$a['stop_uuid'].'/delivery-proof/photo/9')
            ->assertStatus(404);

        // … and another driver cannot retrieve it.
        $b = $this->driverStop();
        $this->actingAs($b['user'])
            ->get('/api/driver/stops/'.$a['stop_uuid'].'/delivery-proof/signature')
            ->assertStatus(404);
    }

    // ── The legacy contract still works (no dispatcher regression) ────────────

    public function test_the_legacy_string_proof_endpoint_still_functions(): void
    {
        $a = $this->driverStop();

        // The shared legacy endpoint is untouched by this task — it still records a
        // (weak) proof. This guards against breaking the dispatcher contract.
        $this->actingAs($a['user'])->postJson('/api/driver/stops/'.$a['stop_uuid'].'/proof', [
            'signature_path' => 'legacy/sig.png',
            'photos' => ['legacy/a.jpg'],
        ])->assertStatus(204);

        self::assertSame(1, DB::table('distribution_delivery_proofs')->where('stop_id', $a['stop_id'])->count());
    }

    // ── Migration verification ────────────────────────────────────────────────

    public function test_the_secure_storage_columns_exist(): void
    {
        foreach (['storage_disk', 'signature_mime', 'signature_size'] as $column) {
            self::assertTrue(
                Schema::hasColumn('distribution_delivery_proofs', $column),
                "the secure-storage migration added `{$column}`",
            );
        }
    }

    // ── Fixture: one driver with an owned trip + delivery stop ────────────────

    /**
     * @return array{user: User, stop_uuid: string, stop_id: int}
     */
    private function driverStop(): array
    {
        $user = User::factory()->create(['company_id' => $this->company->id]);

        $driverId = (int) DB::table('logistics_drivers')->insertGetId([
            'company_id' => $this->company->id,
            'user_id' => $user->id,
            'driver_code' => 'DRV-'.substr(uniqid(), -6),
            'full_name' => 'Driver '.substr(uniqid(), -4),
            'mobile' => '0100'.random_int(1000000, 9999999),
            'national_id' => (string) random_int(10000000000000, 99999999999999),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $vehicleId = (int) DB::table('logistics_vehicles')->insertGetId([
            'company_id' => $this->company->id,
            'plate_number' => 'PL-'.strtoupper(substr(uniqid(), -6)),
            'name' => 'V-'.substr(uniqid(), -4),
            'capacity_orders' => 25,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $pairingId = (int) DB::table('logistics_driver_vehicle_assignments')->insertGetId([
            'driver_id' => $driverId,
            'vehicle_id' => $vehicleId,
            'assigned_at' => now(),
            'active_flag' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $tripId = (int) DB::table('distribution_trips')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => $this->company->id,
            'trip_number' => 'TRP-'.substr(uniqid(), -6),
            'name' => 'proof trip',
            'status' => 'out_for_delivery',
            'driver_vehicle_assignment_id' => $pairingId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $orderId = (string) Str::uuid();
        DB::table('orders')->insert([
            'id' => $orderId,
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-'.strtoupper(substr(uniqid(), -8)),
            'order_date' => now()->toDateString(),
            'assigned_warehouse_id' => $this->warehouse->id,
            'city' => 'Cairo',
            'governorate' => 'Cairo',
            'status' => 'in_progress',
            'subtotal' => 100, 'total' => 100, 'deposit_amount' => 0,
            'shipping_total' => 0, 'discount_total' => 0, 'tax_total' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $stopUuid = (string) Str::uuid();
        $stopId = (int) DB::table('distribution_delivery_stops')->insertGetId([
            'uuid' => $stopUuid,
            'trip_id' => $tripId,
            'order_id' => $orderId,
            'sequence' => 1,
            'status' => 'in_progress',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['user' => $user, 'stop_uuid' => $stopUuid, 'stop_id' => $stopId];
    }
}
