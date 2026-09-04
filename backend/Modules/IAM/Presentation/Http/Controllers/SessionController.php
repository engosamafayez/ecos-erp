<?php

declare(strict_types=1);

namespace Modules\IAM\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\IAM\Application\Services\UserSessionService;
use Modules\IAM\Domain\Models\UserSession;

/**
 * IAM / Admin / Sessions (TASK-ECOS-IAM-SECURE-ADMIN-API-002, §10/§17/§18, D10).
 *
 * Wires the EXISTING UserSessionService (record/activeSessions/revoke/forceLogout) to HTTP —
 * record() is now called from LoginAction (see AuthController), so sessions created from this
 * point forward are tracked; sessions from before this deploy have no UserSession row and
 * therefore cannot be listed/revoked individually here (only force-logout, which also clears
 * all Sanctum tokens directly, covers them). Every action is gated through UserPolicy's existing
 * `manageSessions` ability — tenant-safe (ownsTarget()), identity-safe (targets one user),
 * permission-controlled, and auditable (UserSessionService already logs revoke/force-logout).
 */
final class SessionController extends Controller
{
    use HasApiResponse;

    public function __construct(private readonly UserSessionService $sessions) {}

    public function index(User $user): JsonResponse
    {
        Gate::authorize('manageSessions', $user);

        $sessions = $this->sessions->activeSessions($user)->map(fn (UserSession $s) => [
            'id' => $s->getKey(),
            'ip_address' => $s->ip_address,
            'browser' => $s->browser,
            'platform' => $s->platform,
            'login_at' => $s->login_at?->toIso8601String(),
            'last_activity_at' => $s->last_activity_at?->toIso8601String(),
        ]);

        return $this->success($sessions->values()->all());
    }

    public function destroy(User $user, UserSession $session): JsonResponse
    {
        Gate::authorize('manageSessions', $user);

        if ((string) $session->user_id !== (string) $user->getKey()) {
            // Never confirm a foreign session's existence — same shape as "not found".
            return $this->error('Session not found.', 404);
        }

        $this->sessions->revoke($session);

        return $this->deleted('Session revoked.');
    }

    public function forceLogout(User $user): JsonResponse
    {
        Gate::authorize('manageSessions', $user);

        $count = $this->sessions->forceLogout($user);

        return $this->success(['revoked' => $count], 'All sessions revoked.');
    }
}
