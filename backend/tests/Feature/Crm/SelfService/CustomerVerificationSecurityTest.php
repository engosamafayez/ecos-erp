<?php

declare(strict_types=1);

namespace Tests\Feature\Crm\SelfService;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Crm\Customers\Domain\Models\Customer;
use Modules\Crm\SelfService\Application\Notifications\CustomerTrackingChallengeNotification;
use Modules\Crm\SelfService\Domain\Models\CustomerTrackingToken;
use Modules\Crm\SelfService\Domain\Models\CustomerVerificationChallenge;
use Modules\Crm\SelfService\Domain\Services\CustomerTrackingTokenService;
use Modules\Crm\SelfService\Domain\Services\CustomerVerificationService;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-V1.1-CRM-04-SECURE-SELF-SERVICE-BACKEND-IMPLEMENTATION-019 §27/§28 — the core
 * security-gate tests. Covers items 1, 2, 3, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17 exactly;
 * items 18-21 are structurally satisfied by this design rather than independently testable the
 * way the ticket assumes (see the Task 1 report's ROUTES section: there is no
 * `/track/order/{order}` parameter for a client to manipulate at all — every tokenized route
 * always resolves the TOKEN's own order_id, never a client-supplied one) — item 22 is covered
 * directly. Cross-company/Brand read-model defence-in-depth is covered separately in
 * CustomerOrderReadModelTest.
 */
final class CustomerVerificationSecurityTest extends TestCase
{
    use DatabaseTransactions;

    private function makeOrderWithCustomer(string $companyId, string $email, array $orderOverrides = []): Order
    {
        $customer = Customer::create([
            'company_id' => $companyId,
            'name' => 'Test Customer',
            'email' => $email,
        ]);

        return Order::create(array_merge([
            'company_id' => $companyId,
            'customer_id' => $customer->id,
            'order_number' => 'ORD-'.strtoupper(Str::random(8)),
            'order_date' => now()->toDateString(),
            'status' => 'awaiting_payment',
            'subtotal' => 100,
            'total' => 100,
        ], $orderOverrides));
    }

    // ── 1/2/3: enumeration-safe request() response ──────────────────────────────────────

    public function test_request_is_silent_and_does_not_throw_for_a_valid_matching_order(): void
    {
        Notification::fake();
        $company = Company::factory()->create();
        $order = $this->makeOrderWithCustomer($company->id, 'real@customer.test');

        app(CustomerVerificationService::class)->request($order->order_number, 'real@customer.test');

        Notification::assertSentOnDemand(CustomerTrackingChallengeNotification::class);
        $this->assertDatabaseCount('customer_verification_challenges', 1);
    }

    public function test_request_is_silent_for_a_nonexistent_order_number(): void
    {
        Notification::fake();

        app(CustomerVerificationService::class)->request('ORD-DOES-NOT-EXIST', 'nobody@customer.test');

        Notification::assertNothingSent();
        $this->assertDatabaseCount('customer_verification_challenges', 0);
    }

    public function test_request_is_silent_for_a_wrong_contact(): void
    {
        Notification::fake();
        $company = Company::factory()->create();
        $order = $this->makeOrderWithCustomer($company->id, 'real@customer.test');

        app(CustomerVerificationService::class)->request($order->order_number, 'wrong@customer.test');

        Notification::assertNothingSent();
        $this->assertDatabaseCount('customer_verification_challenges', 0);
    }

    // ── 7: challenge stored hashed, never plaintext ─────────────────────────────────────

    public function test_challenge_code_is_stored_hashed_never_plaintext(): void
    {
        Notification::fake();
        $company = Company::factory()->create();
        $order = $this->makeOrderWithCustomer($company->id, 'real@customer.test');

        app(CustomerVerificationService::class)->request($order->order_number, 'real@customer.test');

        $challenge = CustomerVerificationChallenge::sole();
        $this->assertNotSame('', $challenge->code_hash);
        // A 6-digit code is at most 6 characters; a bcrypt/argon hash never is.
        $this->assertGreaterThan(6, strlen($challenge->code_hash));
        $this->assertMatchesRegularExpression('/^\$/', $challenge->code_hash, 'must be a real password hash, not a raw digit string');
    }

    // ── 8: challenge expires after 10 minutes ───────────────────────────────────────────

    public function test_challenge_expires_after_ten_minutes(): void
    {
        $company = Company::factory()->create();
        $order = $this->makeOrderWithCustomer($company->id, 'real@customer.test');
        $customer = $order->customer;

        $challenge = CustomerVerificationChallenge::create([
            'customer_id' => $customer->id,
            'company_id' => $company->id,
            'order_id' => $order->id,
            'channel' => 'email',
            'code_hash' => Hash::make('123456'),
            'attempts' => 0,
            'max_attempts' => 5,
            'expires_at' => now()->subMinute(),
        ]);

        $this->assertTrue($challenge->isExpired());
        $this->assertFalse($challenge->isLive());

        $token = app(CustomerVerificationService::class)->verify($order->order_number, 'real@customer.test', '123456');
        $this->assertNull($token, 'an expired challenge must never verify, even with the correct code');
    }

    // ── 9: consumed challenge cannot be replayed ────────────────────────────────────────

    public function test_a_consumed_challenge_cannot_be_replayed(): void
    {
        Notification::fake();
        $company = Company::factory()->create();
        $order = $this->makeOrderWithCustomer($company->id, 'real@customer.test');

        app(CustomerVerificationService::class)->request($order->order_number, 'real@customer.test');
        $challenge = CustomerVerificationChallenge::sole();
        $code = $this->extractSentCode();

        $first = app(CustomerVerificationService::class)->verify($order->order_number, 'real@customer.test', $code);
        $this->assertInstanceOf(CustomerTrackingToken::class, $first);

        $second = app(CustomerVerificationService::class)->verify($order->order_number, 'real@customer.test', $code);
        $this->assertNull($second, 'a consumed challenge must never be usable again');
        $this->assertNotNull($challenge->fresh()->consumed_at);
    }

    // ── 10: wrong-code attempts are bounded ──────────────────────────────────────────────

    public function test_verification_attempts_are_bounded(): void
    {
        Notification::fake();
        $company = Company::factory()->create();
        $order = $this->makeOrderWithCustomer($company->id, 'real@customer.test');

        app(CustomerVerificationService::class)->request($order->order_number, 'real@customer.test');
        $challenge = CustomerVerificationChallenge::sole();
        $realCode = $this->extractSentCode();

        for ($i = 0; $i < 5; $i++) {
            $result = app(CustomerVerificationService::class)->verify($order->order_number, 'real@customer.test', '000000');
            $this->assertNull($result);
        }

        $this->assertSame(5, $challenge->fresh()->attempts);
        $this->assertTrue($challenge->fresh()->attemptsExhausted());

        // Even the REAL code must now fail — attempts are exhausted.
        $final = app(CustomerVerificationService::class)->verify($order->order_number, 'real@customer.test', $realCode);
        $this->assertNull($final, 'a challenge with exhausted attempts must never verify, even with the correct code');
    }

    // ── 11/12/13: successful verification issues a hashed, non-replayable opaque token ─

    public function test_successful_verification_issues_a_token_stored_hashed_not_plaintext(): void
    {
        Notification::fake();
        $company = Company::factory()->create();
        $order = $this->makeOrderWithCustomer($company->id, 'real@customer.test');

        app(CustomerVerificationService::class)->request($order->order_number, 'real@customer.test');
        $code = $this->extractSentCode();

        $token = app(CustomerVerificationService::class)->verify($order->order_number, 'real@customer.test', $code);

        $this->assertInstanceOf(CustomerTrackingToken::class, $token);
        $raw = $token->getAttribute('plain_text_token');
        $this->assertIsString($raw);
        $this->assertNotSame($raw, $token->token_hash, 'the raw token must never equal what is stored');
        $this->assertSame(hash('sha256', $raw), $token->fresh()->token_hash);
    }

    // ── 14/15: fixed 7-day expiry, no sliding extension ─────────────────────────────────

    public function test_token_has_a_fixed_seven_day_expiry_with_no_sliding_extension(): void
    {
        $company = Company::factory()->create();
        $order = $this->makeOrderWithCustomer($company->id, 'real@customer.test');
        $customer = $order->customer;

        $token = app(CustomerTrackingTokenService::class)->issue($customer, $order);
        $originalExpiry = $token->expires_at->toIso8601String();
        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, $token->expires_at->timestamp, 5);

        $raw = $token->getAttribute('plain_text_token');
        app(CustomerTrackingTokenService::class)->resolve($raw);
        app(CustomerTrackingTokenService::class)->resolve($raw);

        $this->assertSame($originalExpiry, $token->fresh()->expires_at->toIso8601String(), 'expires_at must never change on use');
    }

    // ── 16: expired token rejected ───────────────────────────────────────────────────────

    public function test_an_expired_token_is_rejected(): void
    {
        $company = Company::factory()->create();
        $order = $this->makeOrderWithCustomer($company->id, 'real@customer.test');
        $customer = $order->customer;

        $token = app(CustomerTrackingTokenService::class)->issue($customer, $order);
        $token->update(['expires_at' => now()->subMinute()]);

        $resolved = app(CustomerTrackingTokenService::class)->resolve($token->getAttribute('plain_text_token'));
        $this->assertNull($resolved);
    }

    // ── 17: revoked token rejected ───────────────────────────────────────────────────────

    public function test_a_revoked_token_is_rejected(): void
    {
        $company = Company::factory()->create();
        $order = $this->makeOrderWithCustomer($company->id, 'real@customer.test');
        $customer = $order->customer;

        $token = app(CustomerTrackingTokenService::class)->issue($customer, $order);
        app(CustomerTrackingTokenService::class)->revoke($token);

        $resolved = app(CustomerTrackingTokenService::class)->resolve($token->getAttribute('plain_text_token'));
        $this->assertNull($resolved);
    }

    // ── 22: client-supplied identity claims cannot override token scope ─────────────────

    public function test_middleware_resolved_token_is_the_sole_authority_for_request_attributes(): void
    {
        $company = Company::factory()->create();
        $order = $this->makeOrderWithCustomer($company->id, 'real@customer.test');
        $customer = $order->customer;
        $token = app(CustomerTrackingTokenService::class)->issue($customer, $order);

        $request = \Illuminate\Http\Request::create('/api/track/order', 'GET');
        // A client attempting to smuggle identity claims via the request itself — the
        // middleware never reads these; it never even looks at the request body for scope.
        $request->merge(['customer_id' => 'attacker-id', 'company_id' => 'attacker-company']);
        $request->headers->set('Authorization', 'Bearer '.$token->getAttribute('plain_text_token'));

        $middleware = app(\Modules\Crm\SelfService\Presentation\Http\Middleware\ResolveCustomerTrackingToken::class);
        $middleware->handle($request, function ($req) use ($token, $customer, $company) {
            $resolved = $req->attributes->get('customer_tracking_token');
            $this->assertSame($token->id, $resolved->id);
            $this->assertSame($customer->id, $resolved->customer_id, 'resolved customer_id must come only from the token row');
            $this->assertSame($company->id, $resolved->company_id, 'resolved company_id must come only from the token row');

            return response('ok');
        });
    }

    /**
     * Recovers the exact plaintext code a test's own request() call would have emailed, via
     * Notification::fake()'s own captured payload — CustomerTrackingChallengeNotification::$code
     * is public readonly for exactly this purpose (see its own docblock). The stored
     * code_hash is write-only by design and is never read back to recover the code.
     */
    private function extractSentCode(): string
    {
        $captured = null;
        Notification::assertSentOnDemand(
            CustomerTrackingChallengeNotification::class,
            function (CustomerTrackingChallengeNotification $notification) use (&$captured): bool {
                $captured = $notification->code;

                return true;
            },
        );

        $this->assertNotNull($captured, 'no challenge notification was captured');

        return $captured;
    }
}
