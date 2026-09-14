<?php

declare(strict_types=1);

namespace Modules\Crm\SelfService\Infrastructure\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * TASK-ECOS-V1.1-CRM-04-SECURE-SELF-SERVICE-BACKEND-IMPLEMENTATION-019 §22 — named limiters,
 * the same RateLimiter::for() convention app/Providers/AppServiceProvider.php already
 * establishes (its own 'ai-assistant' limiter). Kept local to this module's own provider rather
 * than added to the shared AppServiceProvider, since these four limiters exist ONLY for the new
 * guest-tracking surface.
 */
final class SelfServiceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // §22.A — verification REQUEST. Keyed on IP + a normalized contact/order fingerprint so
        // one IP cannot fan out across many different order/contact guesses either — never on
        // the raw secret values themselves.
        RateLimiter::for('customer-tracking-request', function (Request $request) {
            $fingerprint = hash('sha256',
                mb_strtolower(trim((string) $request->input('order_number'))).'|'.
                mb_strtolower(trim((string) $request->input('contact'))),
            );

            return Limit::perHour(5)->by($request->ip().'|'.$fingerprint);
        });

        // §22.B — verification ATTEMPT (code submission). Bounded independently of the
        // per-challenge attempts column, purely at the HTTP/IP layer.
        RateLimiter::for('customer-tracking-verify', function (Request $request) {
            return Limit::perHour(10)->by($request->ip());
        });

        // §22.C — every authenticated tokenized route (order/invoice/support/payment-method).
        RateLimiter::for('customer-tracking-api', function (Request $request) {
            $token = $request->bearerToken() ?? $request->ip();

            return Limit::perMinute(30)->by(hash('sha256', $token));
        });

        // §22.D — support submission specifically, bounded tighter than general API reads.
        RateLimiter::for('customer-tracking-support', function (Request $request) {
            $token = $request->bearerToken() ?? $request->ip();

            return Limit::perHour(5)->by(hash('sha256', $token));
        });
    }
}
