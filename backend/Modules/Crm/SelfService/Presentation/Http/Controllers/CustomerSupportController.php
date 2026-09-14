<?php

declare(strict_types=1);

namespace Modules\Crm\SelfService\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Crm\SelfService\Domain\Models\CustomerTrackingToken;
use Modules\Crm\SelfService\Domain\Services\CustomerOrderReadModel;
use Modules\Crm\Service\Domain\Enums\NoteVisibility;
use Modules\Crm\Service\Domain\Enums\TicketType;
use Modules\Crm\Service\Domain\Models\Ticket;
use Modules\Crm\Service\Domain\Services\TicketService;

/**
 * §13/§14/§15/§16/§17 — reuses Modules\Crm\Service\Ticket/TicketService exactly as-is; this is
 * NOT the internal staff TicketController (which trusts a client-submitted customer_id — the
 * exact anti-pattern ticket 017 already fixed once for Voice identities and this task must not
 * repeat). Every identity fact here — customer_id, company_id, brand_id, order_id — comes
 * SOLELY from the resolved CustomerTrackingToken, never from the request body.
 *
 * Ticket creation is intake only (§15): it never restocks inventory, approves a return, issues a
 * refund/credit note, or changes Order/delivery status — TicketService::create() does none of
 * that (confirmed by reading it), and this controller adds nothing beyond what it already does.
 * The actual execution authority for a return/refund was NOT fully traced within this task's
 * timebox (see the Task 1 report's RETURN/REFUND TRACE section) — CRM-04 V1 exposes ticket/
 * support status only, never a refund/return outcome.
 */
final class CustomerSupportController extends Controller
{
    /** Customer-facing category => canonical Crm\Service TicketType. */
    private const CATEGORY_MAP = [
        'wrong_item' => TicketType::ReturnRma,
        'damaged_item' => TicketType::ReturnRma,
        'missing_item' => TicketType::Complaint,
        'delivery_complaint' => TicketType::Complaint,
        'payment_issue' => TicketType::ServiceRequest,
        'invoice_issue' => TicketType::ServiceRequest,
        'return_request' => TicketType::ReturnRma,
        'general_support' => TicketType::Ticket,
    ];

    public function __construct(
        private readonly TicketService $tickets,
        private readonly CustomerOrderReadModel $readModel,
    ) {}

    public function store(Request $request): JsonResponse
    {
        /** @var CustomerTrackingToken $token */
        $token = $request->attributes->get('customer_tracking_token');

        $order = $token->order_id !== null ? Order::query()->find($token->order_id) : null;

        if ($order === null || $order->customer_id !== $token->customer_id || $order->company_id !== $token->company_id) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        // §18 — the 30-day order-linked window is independently re-derived here from the same
        // canonical read model, never trusted from client input.
        $availability = $this->readModel->resolveSupportAvailability($order);
        if (! $availability['available']) {
            return response()->json([
                'message' => 'Order-linked support is no longer available for this order.',
                'reason' => $availability['reason'],
            ], 422);
        }

        $data = $request->validate([
            'category' => ['required', 'string', Rule::in(array_keys(self::CATEGORY_MAP))],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);

        $type = self::CATEGORY_MAP[$data['category']];
        $brandId = $order->channel?->brand_id;

        $ticket = $this->tickets->create(
            companyId: $token->company_id,
            customerId: $token->customer_id,
            type: $type,
            data: [
                'subject' => $data['subject'],
                'description' => $data['description'] ?? null,
                'channel' => 'customer_portal',
                'category' => $data['category'],
                'source_reference' => [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'brand_id' => $brandId,
                    'channel' => 'customer_portal',
                ],
            ],
            // No staff actor — a customer-initiated ticket has none, and TicketService::create()
            // already supports that (its own $actorId parameter is nullable).
            actorId: null,
        );

        return response()->json(['data' => $this->payload($ticket)], 201);
    }

    /** §17 — the customer's own tickets, public notes only, linked to this order. */
    public function index(Request $request): JsonResponse
    {
        /** @var CustomerTrackingToken $token */
        $token = $request->attributes->get('customer_tracking_token');

        $tickets = Ticket::query()
            ->where('company_id', $token->company_id)
            ->where('customer_id', $token->customer_id)
            ->with(['notes' => fn ($q) => $q->where('visibility', NoteVisibility::Public->value)])
            ->latest('created_at')
            ->get();

        return response()->json(['data' => $tickets->map(fn (Ticket $t) => $this->payload($t, true))->values()->all()]);
    }

    /** @return array<string, mixed> */
    private function payload(Ticket $ticket, bool $withNotes = false): array
    {
        return [
            'ticket_number' => $ticket->ticket_number,
            'type' => $ticket->type->value,
            'subject' => $ticket->subject,
            'status' => $ticket->status->value,
            'created_at' => $ticket->created_at?->toIso8601String(),
            'resolved_at' => $ticket->resolved_at?->toIso8601String(),
            'notes' => $withNotes && $ticket->relationLoaded('notes') ? $ticket->notes->map(fn ($n) => [
                'body' => $n->body,
                'created_at' => $n->created_at?->toIso8601String(),
            ])->values()->all() : null,
        ];
    }
}
