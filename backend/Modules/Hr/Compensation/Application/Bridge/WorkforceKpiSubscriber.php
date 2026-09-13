<?php

declare(strict_types=1);

namespace Modules\Hr\Compensation\Application\Bridge;

use Modules\Hr\Compensation\Domain\Services\KpiFactService;
use Modules\Hr\Workforce\Domain\Models\Employee;

/**
 * The bridge subscriber: it receives an operational domain event from the
 * enterprise bus, translates it through the catalog, and records the fact.
 *
 * ┌─ ZERO COUPLING · SAFE-BY-DEFAULT ───────────────────────────────────────┐
 * │ The event is read purely through its marker contract (eventName / eventId  │
 * │ / toArray) by duck typing, so HR imports NO operational class. It is        │
 * │ registered on the bus only when hr.kpi.auto_subscribe is enabled — off by  │
 * │ default, so existing environments see no behaviour change and turning it   │
 * │ on is a deliberate decision once employees are mapped to operational       │
 * │ actors. An event it cannot attribute is dropped, never guessed.            │
 * │                                                                            │
 * │ Some sources attribute themselves to the acting User rather than an        │
 * │ Employee (a User is the login/actor identity; an Employee is the workforce │
 * │ record a fact must be credited to). Employee IS an HR model, so resolving   │
 * │ User → Employee here — the one identity step the catalog itself must never │
 * │ do — is still zero coupling to any OPERATIONAL module.                     │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class WorkforceKpiSubscriber
{
    public function __construct(
        private readonly WorkforceKpiCatalog $catalog,
        private readonly KpiFactService $facts,
    ) {}

    /** Enterprise-bus entry point. */
    public function handle(object $event): void
    {
        $this->consume($event);
    }

    /** Invokable form, for buses that call subscribers as callables. */
    public function __invoke(object $event): void
    {
        $this->consume($event);
    }

    /** Returns whether a workforce fact was recorded — useful in tests and sync paths. */
    public function consume(object $event): bool
    {
        $name = $this->call($event, 'eventName');
        $id = $this->call($event, 'eventId');
        $payload = $this->call($event, 'toArray');

        if (! is_string($name) || ! is_array($payload)) {
            return false;
        }

        $payload = $this->attributeEmployee($this->mergeNestedPayload($payload));

        $fact = $this->catalog->translate($name, is_string($id) ? $id : $name.':'.spl_object_hash($event), $payload);

        if ($fact === null) {
            return false;
        }

        $this->facts->record($fact);

        return true;
    }

    /** Read a method off the event if it exposes one — never assume it does. */
    private function call(object $event, string $method): mixed
    {
        return method_exists($event, $method) ? $event->{$method}() : null;
    }

    /**
     * Some operational events envelope their business fields under a nested
     * "payload" key instead of the event's own top level. Merge it up without
     * ever overwriting an envelope field already present at the top.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function mergeNestedPayload(array $payload): array
    {
        if (is_array($payload['payload'] ?? null)) {
            $payload += $payload['payload'];
        }

        return $payload;
    }

    /**
     * Resolve a "triggered_by" User id into the employee_id the catalog
     * understands, through the same company the fact itself claims — never
     * across companies, and never when an employee_id is already present.
     * No matching Employee (wrong company or no employee record at all)
     * leaves employee_id unset, so the catalog drops the fact exactly as it
     * does for anyone with nobody to credit. Never a name or phone guess.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function attributeEmployee(array $payload): array
    {
        if (($payload['triggered_by_type'] ?? null) !== 'user') {
            return $payload;
        }

        if (! isset($payload['triggered_by']) || isset($payload['employee_id'])) {
            return $payload;
        }

        $employeeId = Employee::query()
            ->where('user_id', $payload['triggered_by'])
            ->where('company_id', $payload['company_id'] ?? null)
            ->value('id');

        if ($employeeId !== null) {
            $payload['employee_id'] = (string) $employeeId;
        }

        return $payload;
    }
}
