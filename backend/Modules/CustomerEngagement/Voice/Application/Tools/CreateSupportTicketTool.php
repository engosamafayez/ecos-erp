<?php

declare(strict_types=1);

namespace Modules\CustomerEngagement\Voice\Application\Tools;

use App\Models\User;
use Modules\AI\Domain\Contracts\AIToolInterface;
use Modules\AI\Domain\Enums\AIToolClassification;
use Modules\AI\Domain\Enums\AIToolScope;
use Modules\AI\Domain\ValueObjects\AIRequestContext;
use Modules\AI\Domain\ValueObjects\AIToolResult;
use Modules\Crm\Customers\Domain\Models\Customer;
use Modules\Crm\Service\Domain\Enums\TicketType;
use Modules\Crm\Service\Domain\Services\TicketService;

/**
 * TASK-ECOS-V1.1-CRM-03-OMNICHANNEL-VOICE-BACKEND-IMPLEMENTATION-015 §12/§36 — reuses the
 * canonical CRM Ticketing authority (`TicketService::create()`, backend-complete per
 * [[ecos-crm-01-reconciliation]]) directly; does not build a second ticket system, and does not
 * build the CRM-04 self-service portal this would eventually surface through.
 *
 * Requires a resolved Customer — an unresolved/Lead-only caller cannot have a ticket opened
 * against them (there is no customer record to attach it to); the AI transfers to a human
 * instead for that case.
 */
final class CreateSupportTicketTool implements AIToolInterface
{
    public function __construct(private readonly TicketService $tickets) {}

    public function name(): string
    {
        return 'create_support_ticket';
    }

    public function description(): string
    {
        return 'Opens a support ticket for the customer describing their issue.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'customer_id' => ['type' => 'string'],
                'subject' => ['type' => 'string'],
                'description' => ['type' => 'string'],
            ],
            'required' => ['customer_id', 'subject'],
        ];
    }

    public function permission(): string
    {
        // Matches CrmTicketController::store()'s own middleware exactly
        // (Route::post('/', [CrmTicketController::class, 'store'])->middleware('permission:crm.service.manage')) —
        // the tool's own domain permission must be the SAME one ticket creation already requires.
        return 'crm.service.manage';
    }

    public function scope(): AIToolScope
    {
        return AIToolScope::Company;
    }

    public function classification(): AIToolClassification
    {
        return AIToolClassification::ConfirmedAction;
    }

    public function requiresConfirmation(): bool
    {
        return false;
    }

    public function execute(AIRequestContext $context, User $user, array $input): AIToolResult
    {
        $customerId = is_string($input['customer_id'] ?? null) ? $input['customer_id'] : null;
        $subject = is_string($input['subject'] ?? null) ? trim($input['subject']) : '';

        if ($customerId === null || $subject === '') {
            return AIToolResult::invalidInput('customer_id and subject are required.');
        }

        $customer = Customer::query()->where('company_id', $context->companyId)->find($customerId);

        if ($customer === null) {
            return AIToolResult::notFound('No such customer in your company.');
        }

        $description = is_string($input['description'] ?? null) ? $input['description'] : null;

        $ticket = $this->tickets->create(
            $context->companyId,
            $customer->id,
            TicketType::Ticket,
            [
                'subject' => $subject,
                'description' => $description,
                'channel' => 'voice',
            ],
            $user->id,
        );

        return AIToolResult::success([
            'ticket_id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
        ]);
    }
}
