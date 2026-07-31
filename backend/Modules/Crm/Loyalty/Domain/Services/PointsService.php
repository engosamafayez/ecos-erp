<?php

declare(strict_types=1);

namespace Modules\Crm\Loyalty\Domain\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Crm\Loyalty\Domain\Enums\LoyaltyTransactionType;
use Modules\Crm\Loyalty\Domain\Exceptions\LoyaltyException;
use Modules\Crm\Loyalty\Domain\Models\LoyaltyAccount;
use Modules\Crm\Loyalty\Domain\Models\LoyaltyTier;
use Modules\Crm\Loyalty\Domain\Models\LoyaltyTransaction;

/**
 * The points engine — the append-only writer of the loyalty ledger.
 *
 * ┌─ SIGNED LEDGER · DERIVED BALANCE · TIER RECOMPUTED ─────────────────────┐
 * │ Earning, redeeming, adjusting and expiring points are immutable ledger      │
 * │ rows; the balance is their SUM. After every movement the membership tier   │
 * │ is recomputed from the balance. Points earned from an order or a promotion  │
 * │ carry only an opaque source reference — the CRM copies neither.            │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class PointsService
{
    /** Earn points for a spend amount, applying the program rate and tier multiplier. */
    public function earnForSpend(LoyaltyAccount $account, float $amount, ?string $sourceType = 'order', ?string $sourceReference = null, ?int $actorId = null): LoyaltyTransaction
    {
        $account->loadMissing('program', 'tier');
        $rate = (float) ($account->program->points_per_currency ?? 1);
        $multiplier = (float) ($account->tier->earn_multiplier ?? 1);
        $points = (int) floor($amount * $rate * $multiplier);

        return $this->record($account, LoyaltyTransactionType::Earn, max(0, $points), $sourceType, $sourceReference, "Earned on spend {$amount}", $actorId);
    }

    /** Earn a raw number of points (e.g. a promotion bonus). */
    public function earn(LoyaltyAccount $account, int $points, ?string $sourceType = 'manual', ?string $sourceReference = null, ?int $actorId = null): LoyaltyTransaction
    {
        $this->assertPositive($points);

        return $this->record($account, LoyaltyTransactionType::Earn, $points, $sourceType, $sourceReference, 'Points earned', $actorId);
    }

    public function redeem(LoyaltyAccount $account, int $points, ?string $sourceType = 'manual', ?string $sourceReference = null, ?int $actorId = null): LoyaltyTransaction
    {
        $this->assertPositive($points);
        $this->assertActive($account);
        $balance = $account->balance();
        if ($points > $balance) {
            throw LoyaltyException::insufficientPoints($balance, $points);
        }

        return $this->record($account, LoyaltyTransactionType::Redeem, -$points, $sourceType, $sourceReference, 'Points redeemed', $actorId);
    }

    /** A signed manual correction. */
    public function adjust(LoyaltyAccount $account, int $signedPoints, ?string $reason = null, ?int $actorId = null): LoyaltyTransaction
    {
        if ($signedPoints === 0) {
            throw LoyaltyException::mustBePositive();
        }

        return $this->record($account, LoyaltyTransactionType::Adjust, $signedPoints, 'manual', null, $reason ?? 'Adjustment', $actorId);
    }

    /** Spend points on a reward (used by the reward service). */
    public function spendOnReward(LoyaltyAccount $account, int $points, string $rewardId, ?int $actorId = null): LoyaltyTransaction
    {
        $this->assertActive($account);
        $balance = $account->balance();
        if ($points > $balance) {
            throw LoyaltyException::insufficientPoints($balance, $points);
        }

        return $this->record($account, LoyaltyTransactionType::Reward, -$points, 'reward', null, 'Reward redeemed', $actorId, $rewardId);
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function record(LoyaltyAccount $account, LoyaltyTransactionType $type, int $signedPoints, ?string $sourceType, ?string $sourceReference, string $description, ?int $actorId, ?string $rewardId = null): LoyaltyTransaction
    {
        return DB::transaction(function () use ($account, $type, $signedPoints, $sourceType, $sourceReference, $description, $actorId, $rewardId): LoyaltyTransaction {
            $txn = LoyaltyTransaction::create([
                'company_id' => $account->company_id,
                'account_id' => $account->id,
                'type' => $type->value,
                'points' => $signedPoints,
                'source_type' => $sourceType,
                'source_reference' => $sourceReference,
                'reward_id' => $rewardId,
                'description' => $description,
                'occurred_at' => Carbon::now(),
                'actor_id' => $actorId,
            ]);

            $this->recomputeTier($account);

            return $txn;
        });
    }

    public function recomputeTier(LoyaltyAccount $account): void
    {
        $balance = $account->balance();
        $tier = LoyaltyTier::query()
            ->where('program_id', $account->program_id)
            ->where('min_points', '<=', $balance)
            ->orderByDesc('min_points')
            ->first();

        if (($account->tier_id ?? null) !== ($tier->id ?? null)) {
            $account->update(['tier_id' => $tier?->id]);
        }
    }

    private function assertPositive(int $points): void
    {
        if ($points <= 0) {
            throw LoyaltyException::mustBePositive();
        }
    }

    private function assertActive(LoyaltyAccount $account): void
    {
        if ($account->status !== 'active') {
            throw LoyaltyException::accountSuspended();
        }
    }
}
