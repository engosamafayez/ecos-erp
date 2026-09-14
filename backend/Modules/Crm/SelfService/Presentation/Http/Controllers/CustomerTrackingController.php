<?php

declare(strict_types=1);

namespace Modules\Crm\SelfService\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Crm\SelfService\Domain\Services\CustomerVerificationService;

/**
 * §3/§23 — both endpoints are deliberately enumeration-safe: every branch of
 * CustomerVerificationService::request()/verify() collapses to the exact same response here.
 * Nothing about which branch was taken (order missing, contact mismatch, different company/
 * Brand, no email on file, wrong code, expired, attempts exhausted) is ever distinguishable from
 * the response alone.
 */
final class CustomerTrackingController extends Controller
{
    public function __construct(private readonly CustomerVerificationService $verification) {}

    public function request(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_number' => ['required', 'string', 'max:100'],
            'contact' => ['required', 'string', 'max:255'],
        ]);

        $this->verification->request($data['order_number'], $data['contact']);

        return response()->json([
            'message' => 'If the details you entered match an order on file, a verification code has been sent.',
        ]);
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_number' => ['required', 'string', 'max:100'],
            'contact' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:20'],
        ]);

        $token = $this->verification->verify($data['order_number'], $data['contact'], $data['code']);

        if ($token === null) {
            return response()->json(['message' => 'That code is invalid or has expired.'], 422);
        }

        return response()->json([
            'data' => [
                // The ONLY response that ever carries the raw token — never persisted anywhere.
                'tracking_token' => $token->getAttribute('plain_text_token'),
                'expires_at' => $token->expires_at->toIso8601String(),
            ],
        ]);
    }
}
