<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Presentation\Http\Controllers;

use App\Core\Company\CurrentCompanyService;
use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Modules\Commerce\Orders\Application\Actions\RejectPaymentProofAction;
use Modules\Commerce\Orders\Application\Actions\UploadPaymentProofAction;
use Modules\Commerce\Orders\Application\Actions\VerifyPaymentProofAction;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\PaymentProof;
use Modules\Commerce\Orders\Presentation\Http\Requests\RejectPaymentProofRequest;
use Modules\Commerce\Orders\Presentation\Http\Requests\UploadPaymentProofRequest;
use Modules\Commerce\Orders\Presentation\Http\Resources\PaymentProofResource;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Payment-proof lifecycle HTTP surface. TASK-PAYMENT-PROOF-LIFECYCLE-001 §14.
 *
 * Every order/proof lookup is scoped to the current company, so a cross-tenant
 * request resolves to 404 (no information leak) — see §13.
 */
class PaymentProofController extends Controller
{
    use HasApiResponse;

    public function __construct(private readonly CurrentCompanyService $currentCompany) {}

    /** GET /orders/{order}/payment-proofs — full history, newest first. */
    public function index(string $order): JsonResponse
    {
        $model = $this->resolveOrder($order);
        $proofs = PaymentProof::query()
            ->where('order_id', $model->id)
            ->orderByDesc('uploaded_at')
            ->get();

        return $this->success(PaymentProofResource::collection($proofs));
    }

    /** POST /orders/{order}/payment-proofs — upload (also used to replace). */
    public function upload(UploadPaymentProofRequest $request, string $order, UploadPaymentProofAction $action): JsonResponse
    {
        $model = $this->resolveOrder($order);
        $result = $action->execute($model, $request->file('file'));

        return $this->success(new PaymentProofResource($result->data()), $result->message());
    }

    /** POST /payment-proofs/{proof}/verify. */
    public function verify(string $proof, VerifyPaymentProofAction $action): JsonResponse
    {
        $result = $action->execute($this->resolveProof($proof));

        return $this->success(new PaymentProofResource($result->data()), $result->message());
    }

    /** POST /payment-proofs/{proof}/reject. */
    public function reject(RejectPaymentProofRequest $request, string $proof, RejectPaymentProofAction $action): JsonResponse
    {
        $result = $action->execute($this->resolveProof($proof), (string) $request->validated()['reason']);

        return $this->success(new PaymentProofResource($result->data()), $result->message());
    }

    /** GET /orders/{order}/payment-proofs/{proof}/download — tenant-scoped stream. */
    public function download(string $order, string $proof): StreamedResponse
    {
        $this->resolveOrder($order);
        $model = $this->resolveProof($proof);

        abort_unless(
            $model->order_id === $order && Storage::disk($model->storage_disk)->exists($model->storage_path),
            404,
        );

        return Storage::disk($model->storage_disk)->download(
            $model->storage_path,
            $model->original_filename ?? 'payment-proof',
        );
    }

    private function resolveOrder(string $id): Order
    {
        return Order::query()
            ->where('id', $id)
            ->where('company_id', $this->currentCompany->id())
            ->firstOrFail();
    }

    private function resolveProof(string $id): PaymentProof
    {
        return PaymentProof::query()
            ->where('id', $id)
            ->where('company_id', $this->currentCompany->id())
            ->firstOrFail();
    }
}
