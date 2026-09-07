<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Modules\Notifications\Domain\Catalog\NotificationTypeCatalog;
use Modules\Notifications\Domain\Contracts\NotificationDeliveryPolicyInterface;
use Modules\Notifications\Domain\Enums\NotificationPriority;

/**
 * The authenticated user's own notification feed.
 *
 * ┌─ READ-ONLY OVER AN EXISTING TABLE ────────────────────────────────────────┐
 * │ Laravel's `notifications` table has been written to since 2026-07 by the  │
 * │ Preparation wave lifecycle and the provider health monitor, but nothing   │
 * │ ever read it back: there was no endpoint. This controller adds the read   │
 * │ side and the read-state transitions, and nothing else. No schema change,  │
 * │ no new notification producer, no delivery channel.                        │
 * └───────────────────────────────────────────────────────────────────────────┘
 *
 * The authorization gate is ownership, not a permission: a notification is
 * addressed to one notifiable, and every query below is scoped to the caller.
 * A permission would be the wrong instrument — it would let one user read
 * another's feed, which is the only thing that must never happen here.
 *
 * `data` is whatever the producing notification's `toDatabase()` returned. It is
 * passed through unchanged rather than reshaped, because each producer owns its
 * own payload and a translation layer here would silently drop fields the
 * producers add later.
 *
 * TASK-ECOS-NOTIFICATIONS-FOUNDATION-002 (ADR-047 §24) extended this controller in
 * place — company_id/priority/category/source_module/deep_link/dedupe_key/group_key
 * are surfaced (nullable: rows written before the schema extension have none), and a
 * bulk mark-read-by-ids endpoint was added alongside the existing mark-all-read. No
 * existing route, field, or ownership rule changed.
 */
final class NotificationController extends Controller
{
    use HasApiResponse;

    private const MAX_PER_PAGE = 100;

    /** GET /api/notifications */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $perPage = min((int) $request->query('per_page', 25), self::MAX_PER_PAGE);

        // ->latest() (in the notifications() relation) already sorts by created_at
        // desc; `id` is an explicit tiebreaker so two rows sharing the same
        // second-precision timestamp still sort the same way on every request.
        $query = $user->notifications()->getQuery()->orderByDesc('id');

        // TASK-ECOS-NOTIFICATIONS-FINAL-USER-REVIEW-REMEDIATION-010 §5 — the Unread/Read
        // workspace split needs both directions; `unread` previously only ever meant
        // "true or absent" (whereNull). `?unread=0` now means "read only", the symmetric
        // case, added without touching the pre-existing "absent = both" behavior.
        if ($request->has('unread')) {
            if ($request->boolean('unread')) {
                $query->whereNull('read_at');
            } else {
                $query->whereNotNull('read_at');
            }
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', (int) $request->query('page', 1));

        return $this->success([
            'data' => array_map(
                fn (DatabaseNotification $n): array => $this->payload($n),
                $paginator->items(),
            ),
            'unread_count' => $user->unreadNotifications()->count(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
        ]);
    }

    /** PATCH /api/notifications/{id}/read */
    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->whereKey($id)->first();

        if ($notification === null) {
            return $this->error('Notification not found', 404);
        }

        $notification->markAsRead();

        return $this->success($this->payload($notification->refresh()));
    }

    /** POST /api/notifications/mark-all-read */
    public function markAllRead(Request $request): JsonResponse
    {
        $updated = $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return $this->success(['updated' => $updated]);
    }

    /**
     * POST /api/notifications/mark-read — mark a specific, caller-chosen set as read.
     *
     * Distinct from mark-all-read: this is "the permitted set", not "everything" —
     * still gated by the same ownership rule as every other verb here (whereIn against
     * the caller's own relation only; any id in the request that is not the caller's
     * own is silently excluded, not a 404/403, matching mark-all-read's own semantics
     * of "acts only on what is unambiguously mine").
     */
    public function markSetRead(Request $request): JsonResponse
    {
        $ids = array_values(array_unique(array_filter((array) $request->input('ids', []), 'is_string')));

        $updated = $request->user()->notifications()
            ->whereIn('id', $ids)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return $this->success(['updated' => $updated]);
    }

    /**
     * GET /api/notifications/attention-policy
     *
     * TASK-ECOS-NOTIFICATIONS-ATTENTION-EXPERIENCE-003 (ADR-047 §26.4-§26.8). Resolves,
     * for the authenticated user, whether a popup/sound should accompany a newly-observed
     * notification of each priority — MANDATORY SYSTEM POLICY > COMPANY DEFAULT > USER
     * PREFERENCE (§14/§26.5). A small, rarely-changing map the frontend fetches once and
     * applies client-side per notification, rather than a decision recomputed per row.
     */
    public function attentionPolicy(Request $request, NotificationDeliveryPolicyInterface $policy): JsonResponse
    {
        $user = $request->user();

        $result = [];
        foreach (NotificationPriority::cases() as $priority) {
            $result[$priority->value] = $policy->resolveAttention($user, $priority)->toArray();
        }

        return $this->success($result);
    }

    /**
     * GET /api/notifications/type-catalog
     *
     * TASK-ECOS-NOTIFICATIONS-FINAL-USER-REVIEW-REMEDIATION-010 §8/§9. The canonical,
     * backend-authoritative list of configurable notification types — grouped by
     * business module — merged with the authenticated user's own current per-type
     * enabled state, so the Preferences page needs exactly one request to render both
     * "what exists" and "what's on for me". The frontend never hardcodes this list.
     */
    public function typeCatalog(Request $request, NotificationDeliveryPolicyInterface $policy): JsonResponse
    {
        $user = $request->user();

        $result = array_map(
            fn ($definition): array => [
                'key' => $definition->key,
                'module' => $definition->module,
                'name_ar' => $definition->nameAr,
                'description_ar' => $definition->descriptionAr,
                'user_can_disable' => $definition->userCanDisable,
                'has_destination' => $definition->hasDestination,
                'enabled' => $policy->isTypeEnabledFor($user, $definition->notificationClass),
            ],
            NotificationTypeCatalog::all(),
        );

        return $this->success($result);
    }

    /**
     * The wire shape.
     *
     * `type` is the notification's FQCN — the class name is the only stable
     * discriminator the producers share, so it is exposed verbatim and the
     * caller decides how to group it.
     *
     * The Task 2 columns are nullable: a row written before the schema extension (or
     * by a producer that has not migrated onto the shared contract) simply has none of
     * them, and is exposed as such rather than backfilled with a guessed value.
     *
     * @return array<string, mixed>
     */
    private function payload(DatabaseNotification $notification): array
    {
        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'data' => $notification->data,
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
            'company_id' => $notification->company_id,
            'priority' => $notification->priority,
            'category' => $notification->category,
            'source_module' => $notification->source_module,
            'deep_link' => is_string($notification->deep_link) ? json_decode($notification->deep_link, true) : null,
            'dedupe_key' => $notification->dedupe_key,
            'group_key' => $notification->group_key,
            // Not Carbon-cast on the base DatabaseNotification model (only read_at/
            // created_at/updated_at get that automatically) — parsed defensively rather
            // than assumed, since nothing populates these columns yet (Task 2 lays the
            // foundation only) but a future task will.
            'expires_at' => $this->isoOrNull($notification->expires_at),
            'dismissed_at' => $this->isoOrNull($notification->dismissed_at),
        ];
    }

    private function isoOrNull(mixed $value): ?string
    {
        return $value === null ? null : \Illuminate\Support\Carbon::parse($value)->toIso8601String();
    }
}
