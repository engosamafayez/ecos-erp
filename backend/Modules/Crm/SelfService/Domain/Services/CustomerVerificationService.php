<?php

declare(strict_types=1);

namespace Modules\Crm\SelfService\Domain\Services;

use App\Core\Audit\AuditService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Crm\Customers\Domain\Models\Customer;
use Modules\Crm\SelfService\Application\Notifications\CustomerTrackingChallengeNotification;
use Modules\Crm\SelfService\Domain\Models\CustomerTrackingToken;
use Modules\Crm\SelfService\Domain\Models\CustomerVerificationChallenge;

/**
 * §2/§3/§4 — proof of control, not mere knowledge. Knowing an order number plus a phone/email
 * that happens to match is NOT sufficient (§2) — this class is the one boundary where a genuine
 * one-time code, delivered ONLY to the email already on file for that order's Customer, must be
 * proven back before any tracking access is issued.
 *
 * ENUMERATION SAFETY BY CONSTRUCTION (§3/§23): request()/verify() never return a
 * distinguishable signal for "order not found" vs "contact mismatch" vs "different company/
 * Brand" vs "no email on file" vs (for verify) "wrong code" vs "expired" vs "attempts
 * exhausted" — every one of those paths returns exactly null (request() returns void). The
 * HTTP controller therefore has nothing to leak: it always answers with the same generic body.
 *
 * V1 is EMAIL-ONLY (see the Task 1 report's VERIFICATION DELIVERY section for why: this
 * codebase has a real Mail/Notification authority but no SMS/WhatsApp-for-OTP authority). A
 * caller may still submit a phone number as `$contact` — it will simply never match an email on
 * file, and resolves to the same silent no-op as any other non-match. No SMS is fabricated.
 */
final class CustomerVerificationService
{
    private const CODE_TTL_MINUTES = 10;

    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly CustomerTrackingTokenService $tokens,
        private readonly AuditService $audit,
    ) {}

    public function request(string $orderNumber, string $contact): void
    {
        $match = $this->resolveMatch($orderNumber, $contact);

        if ($match === null) {
            return;
        }

        [$order, $customer] = $match;

        $code = (string) random_int(100000, 999999);

        CustomerVerificationChallenge::create([
            'customer_id' => $customer->id,
            'company_id' => $order->company_id,
            'brand_id' => $order->channel?->brand_id,
            'order_id' => $order->id,
            'channel' => 'email',
            // Hashed exactly like a password — never stored, logged, or returned in plaintext.
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'max_attempts' => self::MAX_ATTEMPTS,
            'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
        ]);

        Notification::route('mail', (string) $customer->email)
            ->notify(new CustomerTrackingChallengeNotification($code, $order->order_number));
    }

    /** Returns a freshly issued tracking token, or null for any failure reason whatsoever. */
    public function verify(string $orderNumber, string $contact, string $code): ?CustomerTrackingToken
    {
        $match = $this->resolveMatch($orderNumber, $contact);

        if ($match === null) {
            return null;
        }

        [$order, $customer] = $match;

        $challenge = CustomerVerificationChallenge::query()
            ->where('customer_id', $customer->id)
            ->where('company_id', $order->company_id)
            ->where('order_id', $order->id)
            ->where('channel', 'email')
            ->whereNull('consumed_at')
            ->latest('created_at')
            ->first();

        if ($challenge === null || ! $challenge->isLive()) {
            return null;
        }

        if (! Hash::check($code, $challenge->code_hash)) {
            $challenge->increment('attempts');

            return null;
        }

        // Single-use: consumed immediately on success, so the same code can never be replayed.
        $challenge->update(['consumed_at' => now()]);

        $token = $this->tokens->issue($customer, $order);

        $this->audit->record(
            action: 'customer_self_service.tracking_token_issued',
            entityType: 'customer_tracking_token',
            entityId: $token->id,
            companyId: $order->company_id,
            metadata: ['customer_id' => $customer->id, 'order_id' => $order->id],
        );

        return $token;
    }

    /** @return array{0: Order, 1: Customer}|null */
    private function resolveMatch(string $orderNumber, string $contact): ?array
    {
        $orderNumber = trim($orderNumber);
        $contact = trim($contact);

        if ($orderNumber === '' || $contact === '') {
            return null;
        }

        $order = Order::query()
            ->where('order_number', $orderNumber)
            ->with(['customer', 'channel'])
            ->first();

        if ($order === null || $order->customer === null) {
            return null;
        }

        /** @var Customer $customer */
        $customer = $order->customer;

        if ($customer->email === null || $customer->email === '') {
            return null;
        }

        if (mb_strtolower($customer->email) !== mb_strtolower($contact)) {
            return null;
        }

        return [$order, $customer];
    }
}
