<?php

declare(strict_types=1);

namespace Modules\CostManagement\Application\Listeners;

use App\Models\User;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Modules\CostManagement\Application\Notifications\PricingReviewRequiredNotification;
use Modules\CostManagement\Domain\Events\PriceReviewCreated;
use Modules\IAM\Domain\Contracts\AuthorizationGatewayInterface;
use Modules\Inventory\Products\Domain\Models\Product;

/**
 * TASK-ECOS-NOTIFICATIONS-USER-REVIEW-REMEDIATION-007.
 *
 * Recipients are resolved through the canonical AuthorizationGatewayInterface — never a
 * hardcoded role/user list.
 *
 * Permission choice: `cost.price_review.view`, NOT the `inventory.price_review.*`
 * strings referenced by routes/api.php's own middleware (approve/update/publish). That
 * pre-existing route-level namespace is never actually granted to any role by any
 * migration or seeder in this codebase (confirmed by exhaustive search) — only
 * `cost.price_review.{view,update}` is real, seeded, and granted today (company-admin
 * gets both; viewer gets `.view`) via
 * IAM\Infrastructure\Database\Migrations\2026_12_20_000000_seed_enterprise_permission_matrix.php.
 * This is a pre-existing inconsistency in the Price Review Center's own authorization
 * wiring, out of scope to fix here — `cost.price_review.view` is the one canonical
 * authority that is actually granted to real users, so it is the one that makes this
 * notification reach anyone in practice.
 */
final class NotifyPricingReviewCreated
{
    private const RECIPIENT_PERMISSION = 'cost.price_review.view';

    public function __construct(
        private readonly AuthorizationGatewayInterface $gateway,
    ) {}

    public function handle(PriceReviewCreated $event): void
    {
        $product = Product::find($event->productId);

        $notification = new PricingReviewRequiredNotification(
            reviewId: $event->reviewId,
            productId: $event->productId,
            productName: $product?->name ?? $event->productId,
            previousCost: $event->previousCost,
            newCost: $event->newCost,
            triggerReason: $event->triggerReason,
        );

        // decision(), not can(): can() is a bare permission lookup WITHOUT the
        // system-role bypass (see RequirePermissionMiddleware's own docblock) — using
        // it here would silently exclude every super-admin/system-role user who holds
        // no explicit cost.price_review.view grant, which is exactly how DEV's own
        // admin fixture (AdminUserSeeder — super-admin, is_system) is set up.
        $recipients = User::query()
            ->where('company_id', $event->companyId)
            ->get()
            ->filter(fn (User $user): bool => $this->gateway->decision($user, self::RECIPIENT_PERMISSION)->isAllowed());

        if ($recipients->isEmpty()) {
            return;
        }

        NotificationFacade::send($recipients, $notification);
    }
}
