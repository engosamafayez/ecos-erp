<?php

declare(strict_types=1);

namespace Modules\IAM\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\IAM\Application\Services\UserInvitationService;
use Modules\IAM\Presentation\Http\Requests\AcceptInvitationRequest;

/**
 * CORE-02 Task 1 — PUBLIC, unauthenticated invitation acceptance (mirrors AuthController::login's
 * throttled, guest-route pattern; see routes/api.php's `auth` prefix group). The invitee has no
 * account/session at this point, so this deliberately sits outside every `auth:sanctum` group in
 * this file — there is nothing here to Gate::authorize against.
 *
 * Never leaks WHY a token was refused (expired vs. used vs. revoked vs. simply wrong) — a single
 * generic message for all four, exactly like a failed login never reveals whether the email
 * existed. No response here carries a company id, role, or permission: acceptance only ever sets
 * a password on the account UserInvitationService::invite() already provisioned it for.
 */
final class InvitationAcceptController extends Controller
{
    use HasApiResponse;

    public function __construct(private readonly UserInvitationService $invitations) {}

    /** Peek at a token before asking for a password — lets the UI show the invitee's own email or an invalid/expired state. */
    public function show(Request $request): JsonResponse
    {
        $request->validate(['token' => ['required', 'string']]);

        $invitation = $this->invitations->findValidInvitation((string) $request->string('token'));

        if ($invitation === null) {
            return $this->error('This invitation link is invalid or has expired.', 404);
        }

        return $this->success([
            'email' => $invitation->email,
            'expires_at' => $invitation->expires_at?->toIso8601String(),
        ]);
    }

    public function accept(AcceptInvitationRequest $request): JsonResponse
    {
        try {
            $this->invitations->activate(
                (string) $request->validated('token'),
                (string) $request->validated('password'),
                $request->boolean('require_password_change'),
            );
        } catch (InvalidArgumentException) {
            return $this->error('This invitation link is invalid or has expired.', 422);
        }

        return $this->success(null, 'Your account is now active. You can sign in.');
    }
}
