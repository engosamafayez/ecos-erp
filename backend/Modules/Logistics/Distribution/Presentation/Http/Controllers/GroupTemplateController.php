<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Logistics\Distribution\Domain\Exceptions\DistributionException;
use Modules\Logistics\Distribution\Domain\Models\DistributionGroupTemplate;
use Modules\Logistics\Distribution\Domain\Services\GroupTemplateService;

/**
 * Transport adapter for Distribution Group Templates.
 *
 * Holds no business logic. Authorisation is the existing
 * `permission:logistics.distribution.*` middleware on the routes — NO new
 * permission was introduced: a template is Distribution configuration, so creating
 * one is `create`, editing it is `update`, archiving it is `delete` and listing is
 * `view`, exactly as for a Zone.
 *
 * TENANT SCOPING FAILS CLOSED, copied from DistributionWindowController rather than
 * reinvented: `companyId()` aborts instead of returning null, so an actor with no
 * company sees nothing and can change nothing. A template belonging to another
 * company is reported 404, never 403 — its existence is not something a foreign
 * tenant is entitled to learn.
 */
final class GroupTemplateController extends Controller
{
    public function __construct(
        private readonly GroupTemplateService $templates,
    ) {}

    /** Every live template for the acting company. */
    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $templates = $this->templates->listForCompany($companyId);

        // Zone ownership travels WITH the list, from the same method the write-path guard
        // uses. A separate endpoint would be a second source for one fact, and the picker
        // would eventually offer a Zone the guard then refuses.
        $ownership = $this->templates->zoneOwnership($companyId);

        return response()->json([
            'data' => array_map(fn (DistributionGroupTemplate $t): array => $this->payload($t), $templates),
            'zone_ownership' => array_map(
                static fn (int $zoneId, array $owner): array => [
                    'zone_id' => $zoneId,
                    'template_id' => $owner['template_id'],
                    'template_name' => $owner['template_name'],
                ],
                array_keys($ownership),
                array_values($ownership),
            ),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            // min:1, not min:0. A maximum of zero is a Group that could never hold an
            // order; "no maximum" is expressed as null, matching the Group contract.
            'capacity_orders' => ['nullable', 'integer', 'min:1'],
            'zone_ids' => ['array'],
            'zone_ids.*' => ['integer', 'min:1'],
            // Recommended Drivers — suggestions only, never an assignment. Eligibility
            // (tenant + not archived) is enforced in the service.
            'driver_ids' => ['array'],
            'driver_ids.*' => ['integer', 'min:1'],
            // Preferred Driver/Vehicle — attempted at generation time, never
            // guaranteed. Eligibility (tenant + not archived) is enforced in the
            // service, same as the fields above.
            'preferred_driver_id' => ['nullable', 'integer', 'min:1'],
            'preferred_vehicle_id' => ['nullable', 'integer', 'min:1'],
            // The operator's confirmation of the Move dialog, and nothing else. Absent or
            // false, a Zone owned by another template is refused rather than stolen.
            'move_zones' => ['sometimes', 'boolean'],
        ]);

        try {
            $template = $this->templates->create(
                $companyId,
                $validated['name'],
                $validated['capacity_orders'] ?? null,
                array_map('intval', $validated['zone_ids'] ?? []),
                $this->actorId($request),
                $request->boolean('move_zones'),
                array_map('intval', $validated['driver_ids'] ?? []),
                $validated['preferred_driver_id'] ?? null,
                $validated['preferred_vehicle_id'] ?? null,
            );
        } catch (DistributionException $e) {
            return $this->rejected($e);
        }

        return response()->json(['data' => $this->payload($template)], 201);
    }

    public function update(Request $request, string $template): JsonResponse
    {
        $model = $this->template($request, $template);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'capacity_orders' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'zone_ids' => ['sometimes', 'array'],
            'zone_ids.*' => ['integer', 'min:1'],
            'driver_ids' => ['sometimes', 'array'],
            'driver_ids.*' => ['integer', 'min:1'],
            'preferred_driver_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'preferred_vehicle_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'move_zones' => ['sometimes', 'boolean'],
        ]);

        try {
            $updated = $this->templates->update(
                $model,
                $validated['name'] ?? null,
                $validated['capacity_orders'] ?? null,
                // "sent as null" and "not sent" mean different things: the first
                // removes the maximum, the second leaves it alone.
                array_key_exists('capacity_orders', $validated),
                array_key_exists('zone_ids', $validated)
                    ? array_map('intval', $validated['zone_ids'])
                    : null,
                $this->actorId($request),
                $request->boolean('move_zones'),
                // Same null-vs-absent contract as zones: absent leaves recommendations
                // alone, an empty array clears them.
                array_key_exists('driver_ids', $validated)
                    ? array_map('intval', $validated['driver_ids'])
                    : null,
                $validated['preferred_driver_id'] ?? null,
                array_key_exists('preferred_driver_id', $validated),
                $validated['preferred_vehicle_id'] ?? null,
                array_key_exists('preferred_vehicle_id', $validated),
            );
        } catch (DistributionException $e) {
            return $this->rejected($e);
        }

        return response()->json(['data' => $this->payload($updated)]);
    }

    /** Archive a template. Groups already created from it are untouched. */
    public function destroy(Request $request, string $template): JsonResponse
    {
        $this->templates->archive($this->template($request, $template));

        return response()->json(null, 204);
    }

    // ── Presentation ─────────────────────────────────────────────────────────

    /**
     * A template as the client sees it.
     *
     * Configuration only — there is no runtime field to expose, because there is no
     * runtime column on the table to expose one from.
     *
     * @return array<string, mixed>
     */
    private function payload(DistributionGroupTemplate $template): array
    {
        return [
            'id' => $template->id,
            'name' => $template->name,
            'capacity_orders' => $template->capacity_orders,
            'zone_ids' => $template->zoneIds(),
            'zones_count' => count($template->zoneIds()),
            // Recommended Drivers — suggestions only. Ids, plus a count for the list.
            'driver_ids' => $template->recommendedDriverIds(),
            'drivers_count' => count($template->recommendedDriverIds()),
            // Preferred Driver/Vehicle — attempted automatically at generation time,
            // never guaranteed. Null means no preference.
            'preferred_driver_id' => $template->preferred_driver_id,
            'preferred_vehicle_id' => $template->preferred_vehicle_id,
            'created_at' => $template->created_at?->toIso8601String(),
            'updated_at' => $template->updated_at?->toIso8601String(),
        ];
    }

    // ── Tenant boundary ──────────────────────────────────────────────────────

    /**
     * The acting company, or a hard failure.
     *
     * Never returns null: a null company must not degrade into "see everything".
     */
    private function companyId(Request $request): string
    {
        $companyId = $request->user()?->company_id;

        if ($companyId === null || $companyId === '') {
            abort(403, 'No company scope for the acting user.');
        }

        return (string) $companyId;
    }

    private function actorId(Request $request): ?int
    {
        $id = $request->user()?->getAuthIdentifier();

        return $id === null ? null : (int) $id;
    }

    /** Load a template inside the tenant boundary, or 404. */
    private function template(Request $request, string $templateId): DistributionGroupTemplate
    {
        $template = $this->templates->findForCompany($this->companyId($request), $templateId);

        if ($template === null) {
            abort(404);
        }

        return $template;
    }

    /** Render a domain rule violation as 422, matching the module's convention. */
    private function rejected(DistributionException $e): JsonResponse
    {
        return response()->json(['message' => $e->getMessage()], 422);
    }
}
