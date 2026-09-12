# TASK-ECOS-V1.1-FIN-01-ARCHITECTURE-044B — Report

PROJECT: ECOS ERP V1.1 · TRACK 4 (Finance + HR + Performance + Wallets) · TASK FIN-01 (HR Attendance & Performance)
WORKSPACE: E:\ECOS\ECOS-NEXT-FIN · BRANCH: feature/finance-v1.1 · BASE: cce124e3d7edf46a09b5fbd0a6686f35fab53c30 (verified: `git merge-base HEAD cce124e3` = HEAD, working tree clean)
PHASE: Architecture & Design / Read-only reconciliation. **No implementation, no commit, no push performed.**

**Headline finding, stated up front because it changes the shape of everything below:** this is not a greenfield design task. `backend/Modules/Hr` already ships a complete Workforce + Attendance + Performance + Compensation + Recruitment + Executive stack, built pre-V1.1 as epics **H1+H2** (`a994ab9b feat(hr): workforce foundation & attendance`), **H3+H4** (`1f32070c feat(hr): compensation & performance engines`), **H5+H6** (`c50d463b feat(hr): recruitment, hiring & executive intelligence`), plus later hardening (`8ef069f7`/`571a1af1 feat(hr): post-review enhancements`). A CTO-authored architecture-guard test (`backend/tests/Feature/Hr/CompensationArchitectureGuardTest.php`) already encodes almost exactly the FIN-01/FIN-02 boundary this ticket asks to design — "Payroll calculates only," "reference-only integration," "commission is configured, not coded," "KPI facts are collected... append-only." FIN-01's job is therefore **reconciliation and completion**, not invention: confirm the existing boundary, wire the parts that are built-but-inactive, and fix the parts that are genuinely missing.

---

## 1. Current HR Authority Map

| Entity | Model / Table | Writer (service) | Application layer | Permissions | Audit | Company scope |
|---|---|---|---|---|---|---|
| **Employee** | `Hr\Workforce\Domain\Models\Employee` / `hr_employees` | `EmployeeService` | `EmployeeController`, `Employee360Service` | `hr.employees.view` / `.manage` | Not integrated with central `Core\Audit\AuditService` (none found) | `company_id` FK → Organization's `companies`; enforced by controller (`ResolvesHrContext::companyId()`), **not** an Eloquent global scope — caller-trusted |
| **IAM User ↔ Employee** | `hr_employees.user_id` → `users.id` (nullable, `nullOnDelete`) | Stamped at employee create/update | — | — | — | One-directional: `Employee` carries `user_id` but declares **no `user()` relation method**; `User` model declares **no `employee()` relation** either. IAM's `EmployeeDirectory` reads `hr_employees` directly via raw `DB::table()` (documented, deliberate, read-only — not a duplicate store) rather than through Hr's own model/service. |
| **Driver ↔ Employee/User** | `Logistics\Drivers\Domain\Models\Driver` / `logistics_drivers`, own independent `user_id` → `users.id` | `Driver` model directly (no dedicated service layer found) | `FleetIdentityResolver` (resolves **vehicle↔driver uuid/bigint pairing identity only** — has zero references to `Employee`/`hr_employees`) | n/a | — | Per-model copy-pasted `addGlobalScope('tenant', …)` keyed on `Auth::user()->company_id` (identical in `Driver`, `Vehicle`, `Delivery` — no shared trait) |
| **company / branch** | `Organization\Companies\...\Company` (`companies`), `Organization\Branches\...\Branch` (`branches`) | Organization module | — | (Organization's own permissions, out of this investigation's scope) | — | Branch `belongsTo` Company; confirmed real FKs: `hr_employees.company_id → companies`, `hr_employees.branch_id → branches` (migration `2026_11_03_100004_create_hr_employees_table.php:38-40`) |
| **department** | `Hr\Workforce\Domain\Models\Department` / `hr_departments` (**Hr-owned**, not an Organization entity — Organization has no Department model at all) | `DepartmentService` | `HrStructureController` | part of `hr.workforce.view`/`.manage` | none | FK'd to `companies`/`branches` directly |
| **team** | `Organization\Teams\Domain\Models\Team` / `teams`, company-scoped | Organization module | — | — | — | **No membership link to Employee or User exists anywhere in the codebase.** `Team` is consumed only as a single nullable tag (`team_id`) on Collaboration's `InternalTask`/`Conversation` — `Conversation.php`'s own docblock explicitly warns "never to be confused with… an Organizational Team (see ADR-044 §7)." A loose, **unconstrained** `team_id` column also exists on `crm_service_tickets` (no FK). Team is not part of the HR picture today. |
| **attendance** | `Hr\Attendance\Domain\Models\AttendanceDay` / `hr_attendance_days` | `AttendanceRegistrationService` | `AttendanceController` | `hr.attendance.view` / `.register` | none | Service filters `company_id`/`department_id` explicitly; **`AttendanceSummaryProvider` filters only by `employee_id` + date range — no company check at all**, trusting the caller |
| **shifts** | `Hr\Attendance\...\Shift`, `EmployeeShiftAssignment` | `WorkScheduleService` | `WorkScheduleController` | `hr.attendance.*` | none | via controller |
| **check-in/out** | `AttendanceDay.check_in` / `.check_out` — **explicitly documented as free-text/time "notes," never totalled** (own docblock: *"no worked hours, no overtime, no time off in lieu"*) | `AttendanceRegistrationService::register()`/`registerMany()` | same | `hr.attendance.register` | none | — |
| **leave/absence** | `Hr\Attendance\...\LeaveRequest` / `hr_leave_requests`, with `payroll_flag` (`LeavePayrollFlag`) and `deductsSalary()` | `LeaveRequestService` | `LeaveRequestController` | `hr.leave.view`/`.request`/`.approve` | none | approval writes `AttendanceDay` rows via `leave_request_id` |
| **work schedules** | `WorkCalendar`, `OfficialHoliday` | `WorkScheduleService`, `HolidayService` | `WorkScheduleController` | `hr.attendance.*` | none | via controller |
| **performance** | `Hr\Performance\...\Goal`, `PerformanceSnapshot`, `ManagerReview`, `BonusRecommendation`, `EmployeeIncident` | `GoalService`, `PerformanceEvaluationService`, `ManagerReviewService`, `IncidentService`, `KpiEngine` | `PerformanceController`, `PerformanceReviewController` | `hr.performance.view`/`.manage`/`.review` | none | explicit `company_id` filter; **no manager/department-membership restriction** — any holder of `hr.performance.view` sees any employee/department in-company |
| **targets** | No standalone "target" entity. `Goal` (generic, subject = Employee or Department, any `metric_key`, `comparison` gte/lte) is the closest — qualitative/KPI-linked, distinct from Compensation's `CommissionRule`/`CommissionRuleTier` (rate/tier thresholds), which is the true sales/ops-target-to-money mechanism | `GoalService` / `CommissionRuleService` | — | `hr.performance.manage` / `hr.commission.manage` | none | — |
| **KPI inputs** | `Hr\Compensation\...\KpiFact` / `hr_kpi_facts` — append-only & idempotent (model-level `updating(fn () => false)`/`deleting(fn () => false)`; opaque `source_reference`+`source_module`, **no FK into any operational table**) | `KpiFactService`; bridge `WorkforceKpiCatalog`+`WorkforceKpiSubscriber` (**currently INACTIVE**, `hr.kpi.auto_subscribe` defaults `false`) | `KpiFactController` | read `hr.performance.view`; write `hr.kpi.ingest` | immutability substitutes for audit | explicit `company_id` filter |
| **payroll-facing employee facts** | `SalaryStructure`, `PayrollPeriod`, `PayrollRun`, `Payslip`(+Line), `Advance`, `Bonus`, `Deduction`, `CompensationAdjustment` | `PayrollRunService`, `CompensationCalculator`, `SalaryStructureService`, `AdvanceService`, `BonusService`, `DeductionService` | `PayrollController`, `CompensationController` | `hr.compensation.*`, `hr.commission.*` | bespoke **in-row** "audit trail" methods (`CompensationAdjustment::auditTrail()`, `Bonus::decisionAudit()`) — **not** the central `AuditLog`; salary/compensation fields are **not registered** with IAM's `SensitiveFieldRegistry` (registry has zero registrations from any module, platform-wide) | explicit `company_id` filter; architecture-guard-tested to **never** write a journal/ledger row, move money, or import an operational module |

---

## 2. Attendance Capability Matrix

| Capability | Status | Evidence |
|---|---|---|
| Attendance capture | **(A) EXISTING** | `AttendanceDay` (`hr_attendance_days`), one row per employee per day, `status` enum, registered via `AttendanceRegistrationService::register()`/`registerMany()`. UI: `attendance-workspace-page.tsx` (per-date register sheet, mark-all, save). |
| Shift assignment | **(A) EXISTING** | `Shift` + `EmployeeShiftAssignment` models, `WorkScheduleService`, `WorkScheduleController`. Frontend page not independently confirmed — verify during Slice implementation. |
| Check-in | **(A) EXISTING, narrow** | `check_in` column on `AttendanceDay` — but it is a **free note of when someone arrived**, not a timestamped event compared against a shift start time. |
| Check-out | **(A) EXISTING, narrow** | Same as above, symmetric. |
| Late | **(D/G) NOT SUPPORTED** | `AttendanceStatus` enum has exactly five cases: `Present, Absent, Leave, Holiday, RestDay`. **There is no `Late` status**, and `check_in` is never compared to `Shift` start time to derive lateness. This is confirmed absent, not merely unwired. |
| Absence | **(A) EXISTING** | `Absent` status; `LeaveRequest.payroll_flag` distinguishes paid/unpaid; `ProvidesAbsenceFacts::deductibleDaysFor()` returns `unauthorized_absence_days`/`unpaid_leave_days`/`paid_leave_days`/`present_days`/`working_days_recorded` per employee per window — already the exact "attendance counts days, payroll prices them" interface FIN-02 needs. |
| Overtime | **(G) DELIBERATELY NOT SUPPORTED — business decision, not a gap** | `AttendanceDay`'s own docblock states plainly: *"Check-in and check-out are optional notes... They are never totalled: no worked hours, no overtime, no time off in lieu."* This was a conscious H1/H2 design choice. Building it is a scope decision, not a bug fix. |
| Approved corrections | **(C) FIN-01 GAP (likely)** | No distinct "correct a past attendance day" workflow was found separate from initial registration; combined with the complete absence of `Core\Audit` integration (§6), there is no evidence of a gated, logged correction flow. |
| Leave impact | **(A) EXISTING** | `LeaveRequest.attendanceDays()` — an approved leave request writes/owns the corresponding `AttendanceDay` rows via `leave_request_id`; payroll consumes the result only through `ProvidesAbsenceFacts` (never a raw join). |

**No biometric/device integration exists or is implied anywhere — correctly not invented.**

---

## 3. Performance Capability Matrix

| Capability | Status | Evidence |
|---|---|---|
| Generic goal/target definition | **(A) EXISTING** | `Goal` (`hr_goals`): subject = `Employee` or `Department` (`GoalSubject`), any `metric_key`, `target_value`, `comparison` (gte/lte — "lower is better" goals like failed deliveries are natively supported), `weight`, `period_month`. |
| Target-vs-actual computation | **(A) EXISTING** | `PerformanceSnapshot`: `target_value`/`actual_value`/`achievement_percent`/`fact_count`/`explanation` (array — traces which facts fed the number), computed monthly by `KpiEngine`/`PerformanceEvaluationService`. Achievement is capped at 200% so one runaway month can't distort a trend (`Goal::achievement()`). |
| Employee performance dashboard | **(A) EXISTING** | `PerformanceDashboardService`, UI `employee-performance-page.tsx` + `department-performance-page.tsx`. |
| Manager review | **(B) PARTIAL — backend done, UI missing** | `ManagerReview` model + `POST /hr/performance/employees/{id}/review` (`hr.performance.review`) + REST client method (`compensation-service.ts:269`) all exist, but **no React Query hook wraps it and no page invokes it** — `employee-performance-page.tsx` only renders a read-only review card if one already exists via another path. There is currently no way for a manager to submit a review through the UI. |
| Bonus recommendation | **(A) EXISTING** | `BonusRecommendation` + `BonusRecommendationService`, generate/approve/modify/reject flow in `performance-workspace-page.tsx`. |
| Incident tracking | **(A) EXISTING** | `EmployeeIncident` + `IncidentService`. |
| Manager-scoped visibility ("see only my reports") | **(C) FIN-01 GAP** | Confirmed absent: any actor holding `hr.performance.view` can view **any** employee's or department's dashboard in-company; `OrganizationChartService::build()` takes only a `companyId` (no `$managerId` parameter anywhere in the class) and always returns the full company tree. |
| Driver performance presentation | **(C) FIN-01 GAP, but built on mature existing data** | See §4/§5 — the raw facts and even aggregation services already exist in Logistics; only the identity link and the presentation layer are missing. |
| Commission calculation | **(D) FIN-02, already built** | `CommissionEngine`/`CommissionRule`/`CommissionRuleTier` — architecture-guard-tested to name no role/scheme and hard-code no rate ("commission is configured, not coded"). **Out of FIN-01 scope by the ticket's own §5 instruction; already implemented, not something to design.** |
| Payroll calculation | **(D) FIN-02, already built** | `PayrollRun`/`Payslip`/`CompensationCalculator` — guard-tested to never write Finance's ledger, never move money. Out of FIN-01 scope. |

**The FIN-01/FIN-02 split the ticket asks for already exists in code**, expressed as the `KpiFact`/`KpiMetric` stream: Performance (`Goal`, `PerformanceSnapshot`) and Compensation (`CommissionRule`, `PayrollRun`) both only *consume* that same shared, append-only fact vocabulary — they do not share code, only the read-model. FIN-01 should treat this boundary as **already correct** and avoid re-architecting it.

---

## 4. Driver/Employee Identity Boundary

This is the single most consequential reconciliation finding.

- `Employee` (`hr_employees`) carries `user_id → users.id`, but **declares no `user()` relation method.**
- `Driver` (`logistics_drivers`) carries its **own, independent** `user_id → users.id`, but **has no `employee_id` column, and no relation to `Employee` of any kind.**
- `User` declares **neither** an `employee()` **nor** a `driver()` inverse relation. The only way from a `User` to a `Driver` is a manual `Driver::where('user_id', ...)` query; the only way from a `User` to an `Employee` is a manual `Employee::where('user_id', ...)` query. Nothing joins the two together.
- `FleetIdentityResolver` (Logistics) resolves **vehicle↔driver identity** (a uuid/bigint reconciliation for `Operations\Loading`'s contract) — it has zero relationship to HR identity, despite a superficially similar name.
- `Employee.php`'s own class docblock states, aspirationally: *"Shipping's driver, Inventory's warehouse operative, Manufacturing's operator, Commerce's and CRM's salesperson are all THIS row, held by id."* **This is not true of the current implementation for Driver.** No code path today can go from a `Driver` row to a matching `Employee` row.
- Consequence: `WorkforceKpiCatalog`'s `driver_employee_id` payload key (and the analogous `salesperson_employee_id`/`operator_employee_id`/`picker_employee_id` keys) has **no producer anywhere in the codebase.** The bridge is built to consume an attribution that nothing currently supplies.

This is not a small wiring gap — it is an unresolved identity model question that blocks: Driver performance presentation inside HR, any future FIN-02 driver commission, and the KPI bridge's Shipping-sourced metrics entirely. See §10 for the business decision this requires.

---

## 5. Operational-Fact Reuse Model

**The interface side is already fully built.** `WorkforceKpiCatalog` (event-name → `KpiMetric` translation, duck-typed, zero coupling to any operational class) + `WorkforceKpiSubscriber` (consumes via `method_exists($event,'eventName'/'toArray')`) + `KpiFact` (append-only, idempotent, opaque references) together are exactly the "reference/aggregate authoritative facts, never copy another module's operational facts into a second engine" model the ticket asks for in §3. **The gap is entirely on the producing side.** Verified event-by-event:

| Catalog's expected event | Live today? | Reality |
|---|---|---|
| `commerce.order.completed` / `.placed` | **NO** | No matching class found anywhere. Nearest candidate (`Operations\Fulfillment\...\OrderCompletedEvent`) is a plain `Dispatchable` with no `toArray()`/`eventName()`, not bus-bridged. |
| `shipping.shipment.delivered` / `.failed` | **NO** | Real events exist under **different names and in different sub-modules** — `Logistics\Distribution\...\DeliveryStopCompleted` and `Logistics\Delivery\...\DeliveryFailed` — both plain `Dispatchable` (no `toArray()`/`eventName()`), neither bus-bridged, and neither carries a `driver_id` field directly (only reachable via a 3-hop join: `DeliveryAttempt → trip_id → Trip → driver_vehicle_assignment_id → Driver`). |
| `inventory.count.completed` | **NO (misnamed, half-wired)** | Real, live event `InventoryCountApproved` exists, dispatched, and **is** bridged onto the enterprise bus — but its `eventName()` is `'inventory.count.approved'` (not `.completed`), its payload key is `approved_by` (not one the catalog recognizes), and **no subscriber is registered for it at all.** |
| `inventory.shortage.recorded` / `.damage.recorded` | **NO** | No matching class found. |
| `crm.ticket.closed` | **NO — dead code** | `TicketClosed` event class exists with the correct `eventName()`, and its own docblock names `TicketService::transition()` as the publisher — but that method's `Closed` branch only sets `closed_at` and writes an in-module `TicketEvent` audit row; it **never constructs `TicketClosed`.** Scaffolding, never wired. Not in the bus allowlist either. |
| `preparation.order.prepared` | **NO (misnamed, unbridged, but real attribution exists)** | Real, live event `ProductPrepared` is dispatched from `CompleteProductAction` with `eventName() = 'preparation.product.prepared'` and a genuine `prepared_by` field — but under a different name, and not in the bus's `bridgeLegacyEvents()` allowlist. |
| `packing.order.packed` | **NO** | `Modules/Operations/Packing` does not exist at all. |

**The enterprise bus itself** (`Modules\Platform\EventPlatform\Application\Services\EnterpriseEventBus`, `publish()`/`subscribe()`) is real and shared with Finance's own (also currently-inconsistent — see below) integration bridge. Its actual operating pattern, per `EventPlatformServiceProvider::bridgeLegacyEvents()`, is an **explicit allowlist** of specific event classes republished onto the bus — today covering Preparation *wave* events, six Inventory stock events + `InventoryCountApproved`, and CRM Customer/Sales(Lead/Opportunity/Quote)/Loyalty events. None of the ten events the HR catalog wants are in that allowlist under a matching name.

**A second, independent pattern already exists and is arguably a better fit for pure presentation:** the Reporting module's `ExecutiveOverviewQuery` (per its own docblock, "Pattern C," citing ADR-045) does **not** use the bus/fact-table approach at all — it directly composes other modules' own read-model Query classes (e.g. it already injects `Logistics\Distribution\...\DeliveryPerformanceQuery`, `Sales\Customers\...\CustomerOverviewQuery`, `Operations\Preparation\...\PreparationCompletionAndShortagesQuery`) and copies their computed values verbatim — "no metric is recomputed." **This is directly reusable for Driver Performance presentation** without waiting on the event-contract fixes above, since `DeliveryPerformanceQuery` and Distribution's `DriverDaySettlementReadService`/`DriverReportsReadService` already compute delivery rate, failures, returns, and settlement discrepancies today.

**Recommendation:** treat these as two separate tracks, not one blocked chain — (1) real-time KPI-fact collection via the bus (needed for anything Compensation/commission will eventually price) stays blocked on fixing event contracts module-by-module plus the identity decision in §4; (2) **read-only Driver Performance presentation can ship now** via Pattern-C query composition, independent of (1).

---

## 6. Authorization / Tenant Model

- **IAM already has a complete, generic scoping/visibility engine** that Hr does not yet use: `DataScope` enum (`SELF, TEAM, BRANCH, WAREHOUSE, CHANNEL, COMPANY, REGION, BUSINESS_UNIT, DEPARTMENT, CUSTOM, ALL`), resolved by `ScopeResolver::resolve()` into an immutable `ScopeConstraint`, applied via a `Builder::macro('scopedTo', ...)` — e.g. `Model::query()->scopedTo($user, 'resource.key')`. This is **already consumed by another module** (`Collaboration\...\DriverMessagingAuthorizer` scopes `Driver` queries this way) — it is a proven, cross-module-adopted pattern, not theoretical.
- **Hr does not use it.** `Employee` has no global scope at all; every service (`EmployeeService`, `AttendanceRegistrationService`, `PerformanceEvaluationService`, `KpiFactService`) does its own explicit `->where('company_id', $companyId)`, with `$companyId` sourced from the controller (`ResolvesHrContext` trait) and trusted downstream. This gets company-level isolation right but provides **no mechanism at all** for branch/department/team/self-only grants — exactly the finer-grained scopes (`manager view`, `HR view`, `employee self-view`) the ticket asks to reconcile in §6 do not exist as enforced data-scopes today; they exist only as coarser permission strings (`hr.performance.view` = see everything in-company; no permission = see nothing).
- **`EmployeePolicy` is not wired into Laravel's authorization at all.** It is a plain container singleton, manually invoked from exactly three call sites (`terminate()`, `manageReportingLine()`); it does two things only — same-company check and self-action prevention. No `Gate::`/`authorize()` call exists anywhere in `backend/Modules/Hr`. Real enforcement is 100% route-middleware (`permission:hr.xxx` → IAM's `RequirePermissionMiddleware` → `AuthorizationGatewayInterface`), which is coarse allow/deny per endpoint, not row-level.
- **Sensitive-field masking does not exist for compensation data.** IAM's `SensitiveFieldRegistry`/`FieldVisibility` engine exists and is documented ("nothing is sensitive until declared here") but **has zero registrations from any module in the entire codebase** — not just Hr. Salary, payslip, and adjustment fields are protected only by the coarse `hr.compensation.view` permission gate, with no field-level distinction between (say) an employee's own gross pay and a peer's.
- **Frontend is already permission-key-based, correctly.** `RequirePermission`/`Can` components and the `module-navigation.ts` `GATE` map both key on backend permission strings (e.g. `hr.performance.view`), never role names. FIN-01 UI work should simply continue this pattern — no remediation needed here.
- **Tenant/org scope note:** IAM's `OrganizationScopeDirectory` — the canonical source list used to validate a scope assignment — covers `companies, brands, branches, warehouses, regions, channels, teams, business_accounts`, and **explicitly excludes `department`/`cost_center`** as "free-form, unvalidated" types. Since Hr's `Department` is Hr-owned (not an Organization entity), a future `DataScope::DEPARTMENT` grant cannot today be validated against a real canonical table the way `DataScope::BRANCH` can. This matters directly for any "manager sees only their department" scoping FIN-01 might build.
- **No cross-company aggregation exists anywhere checked** — every service scopes to one `company_id`; this is consistent with the ticket's requirement.

---

## 7. Required Read Models

| Read model | Status | Source authority | Date authority | Scope | Formula | Null/zero semantics |
|---|---|---|---|---|---|---|
| `ProvidesAttendanceSummary` | **(A) EXISTING** (`AttendanceSummaryProvider`) | `hr_attendance_days` | `work_date` range (inclusive) | one employee | count of each `AttendanceStatus` per range | absent day rows simply aren't counted in `present_days`; no row ⇒ zero, never inferred |
| `ProvidesAbsenceFacts` | **(A) EXISTING** (`AbsenceFactsProvider`) | `hr_attendance_days` + `hr_leave_requests` | `from`/`to` inclusive `Y-m-d` | one employee | `unauthorized_absence_days`/`unpaid_leave_days`/`paid_leave_days`/`present_days`/`working_days_recorded` | returns days only, never a monetary amount — enforced by an architecture-guard test |
| Employee goal achievement (`Goal`+`PerformanceSnapshot`) | **(A) EXISTING** | `hr_goals`/`hr_performance_snapshots`, fed by `hr_kpi_facts` | `period_month` | employee or department | `achievement_percent = actual/target *100` (inverted for lower-is-better), capped [0,200] | target=0 on a "reach this" goal ⇒ 0%; target=0 on a "keep it down" goal with actual≤0 ⇒ 100% (explicit in code, not a default) |
| `WorkforceKpiFactStream` (`KpiFact`/`KpiMetric`) — **the FIN-01→FIN-02 interface** | **(A) EXISTING**, externally unfed (§5) | `hr_kpi_facts`, append-only | `occurred_at` on ingestion | company + employee (+ optional department) | per-metric `aggregation()` = Sum or Average (`InventoryAccuracy`/`CustomerSatisfaction` average, rest sum) | idempotent on `idempotency_key`; an event with no resolvable employee is **dropped, never guessed** |
| Department performance rollup | **(A) EXISTING**, gap in access control | `PerformanceDashboardService` | `period_month` | department, company-filtered only (no manager restriction — §6) | average of member snapshots | — |
| **Driver Performance Summary** | **(C) FIN-01 GAP — new** | Compose (read-only) `Logistics\Distribution\...\DriverDaySettlementReadService` + `DriverReportsReadService` (+ Reporting's already-existing `DeliveryPerformanceQuery` as precedent) | per-day or date range, driver-day grain | one driver (pending §4) | `delivery_rate`, `total_failed/returned/skipped`, settlement `discrepancy`/`is_balanced` — **all already computed** by the source services, copied verbatim per Pattern C, never recomputed in Hr | `DriverReportsReadService.shortages()` already flags `value_available: false` where no monetary authority exists — FIN-01's read model must preserve, not paper over, that honesty |
| Manager-scoped employee/subtree view | **(C) FIN-01 GAP — new** | `hr_employees.reportingLines` (`ReportingLine`) already models the manager graph | n/a | employees under one manager, recursively | walk `ReportingLine` from a manager's `employee_id` | an employee with no reports ⇒ empty set, not an error |

---

## 8. FIN-01 Implementation Slices

**Slice 1 — Driver↔Employee Identity Resolution**
- Goal: give the platform one authoritative, queryable answer to "which Employee (if any) does this Driver correspond to," gated on the CTO decision in §10.
- Canonical authorities: `Hr\Workforce\Employee` (reference only), `Logistics\Drivers\Driver` (reference only) — new code should live in a neutral seam (candidate: a new `Hr\Workforce` read-only resolver consuming both by id, or an IAM-side directory analogous to `EmployeeDirectory`), never a direct FK from Driver into `hr_employees` unless the business decision explicitly wants Drivers-are-Employees.
- Expected files: one resolver service + its interface/contract (mirroring `ProvidesAttendanceSummary`'s port pattern); no changes to `Driver`'s or `Employee`'s own tables unless the decision requires a new nullable link column.
- Schema impact: none-to-minimal (a single nullable FK, only if the decision requires persisting the link rather than deriving it from a shared `user_id`).
- Tests: resolver unit tests + an architecture-guard test (mirroring `CompensationArchitectureGuardTest`) asserting Hr still imports no operational module.
- Browser/runtime evidence: none required (backend-only).
- Dependencies: **blocked on §10 decision.**
- Risk: low technically, but silent scope creep risk if resolution logic drifts into Logistics owning HR concerns or vice versa.

**Slice 2 — Manager-Scoped Performance & Org Experience**
- Goal: make "manager view" a real, enforced data scope (not just a permission string), and finish the already-built Manager Review submission path.
- Canonical authorities: `hr_employees.reportingLines`/`directReportLines` (already modeled), IAM's `ScopeResolver`/`DataScope` (already built, already adopted by Collaboration).
- Expected files: `OrganizationChartService` gains a manager-scoped variant; `PerformanceDashboardService`/`PerformanceController` apply `scopedTo($user, 'hr.performance')` or an equivalent reporting-line walk; frontend gains the missing `useManagerReview`-style hook + a review-submission form wired to the already-existing `POST /hr/performance/employees/{id}/review` endpoint.
- Schema impact: none.
- Tests: policy/scope unit tests (manager sees only own subtree; non-manager sees only self); feature test for review submission round-trip.
- Browser/runtime evidence: manually exercise the new review form against the dev stack.
- Dependencies: none blocking — can start immediately.
- Risk: low; must resolve the `DataScope::DEPARTMENT` validation gap noted in §6 if department-level (not just manager-subtree) scoping is wanted.

**Slice 3 — Sensitive-Field & Audit-Trail Compliance**
- Goal: close the two concrete compliance gaps in §6/§1 — register Compensation's sensitive fields with IAM's existing (currently empty) `SensitiveFieldRegistry`, and wire attendance correction / leave approval / performance review / compensation adjustment into the central `Core\Audit\AuditService` instead of (or alongside) their bespoke in-row audit methods.
- Canonical authorities: `IAM\...\SensitiveFieldRegistry` (register-only, reference), `Core\Audit\AuditService` (call-only, reference).
- Expected files: a registration call in `HrServiceProvider::boot()`; `AuditService::record(...)`-style calls added to the four named service methods.
- Schema impact: none.
- Tests: assert masked fields don't leak to an unauthorized role; assert an `AuditLog` row is created per operation.
- Browser/runtime evidence: not required.
- Dependencies: none — fully self-contained, no cross-team coordination.
- Risk: very low.

**Slice 4 — Driver Performance Presentation Read Model**
- Goal: ship a read-only "Driver Performance" view inside HR by composing Logistics's already-existing settlement/delivery read-services (Pattern C, per §5), without waiting on the KPI-fact bridge.
- Canonical authorities: `Logistics\Distribution\DriverDaySettlementReadService`, `DriverReportsReadService`, Reporting's `DeliveryPerformanceQuery` (as precedent/possible direct reuse).
- Expected files: one new Hr-side query/service that injects and calls the above (never re-derives their math, per Reporting's own rule); one new frontend page alongside the existing Employee/Department performance pages.
- Schema impact: none — pure read composition.
- Tests: contract test asserting the new service recomputes nothing and only copies values.
- Browser/runtime evidence: verify the new page in the dev stack against real Distribution data.
- Dependencies: **presentation only work can start without Slice 1**; attributing a row to a specific *Employee* (vs. showing it by raw Driver id) needs Slice 1 resolved first.
- Risk: low; main risk is showing Driver-labelled data without an Employee identity and having that quietly become "the" driver performance UI, foreclosing the §10 decision by default.

**Slice 5 — Operational-Fact Bridge: One Real End-to-End Path**
- Goal: prove the KpiFact bridge end-to-end using the single lowest-risk real candidate found — Preparation's `ProductPrepared` event, which already carries genuine `prepared_by` attribution and is already live (just unbridged and misnamed relative to the catalog).
- Canonical authorities: `Operations\Preparation\...\ProductPrepared` (reference only), `EventPlatformServiceProvider::bridgeLegacyEvents()` allowlist (addition), `WorkforceKpiCatalog` (mapping key fix: `preparation.product.prepared`, not `preparation.order.prepared`).
- Expected files: one allowlist entry, one catalog key rename/addition, environment-gated `HR_KPI_AUTO_SUBSCRIBE` flip (never default-on) once operators are confirmed to hold Employee records.
- Schema impact: none.
- Tests: extend `CompensationArchitectureGuardTest`-style coverage to assert the real event now translates end-to-end.
- Browser/runtime evidence: not required (backend event pipeline).
- Dependencies: independent of Slice 1 (Preparation operators, unlike Drivers, are presumably already Employees — verify this assumption before starting).
- Risk: low, and it produces a reusable template for the Commerce/Shipping/CRM contract fixes that remain out of scope for FIN-01 itself.

---

## 9. Items Deferred to FIN-02/03/04

- **(D) FIN-02** — Commission calculation (`CommissionEngine`/`CommissionRule`), payroll run/payslip generation (`PayrollRunService`/`CompensationCalculator`), salary structures, advances, deductions, bonuses-as-money: **already implemented** under `Hr\Compensation`, already architecture-guard-tested to the correct boundary (no journals, no money movement, config-driven commission). FIN-01 should not touch this code; it should only ensure FIN-01's own facts (§7) remain a clean, stable input to it.
- **(D) FIN-02, open question** — whether Compensation should be physically relocated out of `Modules\Hr\Compensation` to sit nearer Finance, given the V1.1 track split names it separately from FIN-01. See §10.
- **FIN-03/FIN-04 (wallets; brand/parent-company economics)** — no code under `Hr` overlaps this; nothing found that needs deferring here specifically, beyond the general rule that FIN-01 must not invent wallet or inter-brand settlement logic.
- **Commerce → Finance / WooCommerce refund / CreditNote integration** — confirmed out of scope per the ticket's concurrency rule; not investigated beyond noting its existence in `Finance\Integration`. Owned by WOO-01 in `E:\ECOS\ECOS-NEXT-WOO`.
- **Recruitment/Exit/Executive (`Hr\Recruitment`, `Hr\Executive`)** — fully built (H5/H6), out of FIN-01's stated scope (attendance + performance only); one incidental finding worth flagging to whoever owns that area: `BulkRecruitmentService`'s per-action permission map is UI-descriptive only and is not re-checked server-side in `execute()`, so a user holding only `hr.recruitment.bulk` can bulk-execute actions (e.g. `schedule_interview`) that should require `hr.interviews.manage`. Not a FIN-01 deliverable; noted for awareness.

---

## 10. True Business Decisions Required

1. **Driver↔Employee identity model.** Should every Driver be required to have a corresponding Employee record (drivers are formally employees, enforce a link), should the link stay optional/best-effort (correlated only where both happen to share a `user_id`), or should Driver deliberately stay outside the Employee-based HR model entirely (Driver performance presented as its own surface, never rolled into "employee performance")? This single decision determines the feasibility and shape of Slice 1, Slice 4's identity attribution, and any future FIN-02 driver commission. The current code's own docblock claims the unified-identity answer; the current code's actual implementation delivers the separated answer. Someone must pick one on purpose.
2. **Physical ownership of `Hr\Compensation`.** The V1.1 track plan names FIN-01 (attendance+performance) and FIN-02 (targets+commission+payroll) as separate tasks/tracks, but the already-shipped code has both living under one `Modules\Hr` namespace with only an internal (well-tested) boundary between them. Does FIN-02 inherit `Hr\Compensation` in place, or does it get relocated to a new module? Relocating is pure churn risk against a working, guard-tested system; not relocating means "FIN-02" is, physically, still "Hr."
3. **Is "Late" a required attendance status?** Currently structurally absent (not merely unwired) — adding it means defining how a "late" threshold relates to `Shift` start time and whether/how it feeds `KpiFact`/Performance, none of which exists today.
4. **Is overtime a required capability?** Explicitly and deliberately excluded from the current design (§2). Adding it is a scope expansion, not a bug fix, and interacts directly with FIN-02's payroll calculation.
5. **Should Hr's `Department` become a canonical `OrganizationScopeDirectory` source** so that a `DataScope::DEPARTMENT` grant can be validated the same way `DataScope::BRANCH`/`DataScope::TEAM` already are? Needed only if department-level (not just manager-subtree) authorization scoping is required.
6. **Does "team" matter to HR performance at all?** Today `Team` has zero connection to `Employee`/`User` anywywhere and is explicitly documented (Collaboration's ADR-044 §7 reference) as not to be conflated with an organizational team. Building team-based performance rollups means building team membership from scratch — confirm this is actually wanted before scoping it as FIN-01 work.

---

## 11. Recommended First Implementation Slice

**Slice 3 — Sensitive-Field & Audit-Trail Compliance.**

Rationale: it is the only slice that (a) requires no unresolved business decision, (b) has zero dependency on another track (WOO/OPS/UI) or on facts that don't exist yet, (c) is purely additive against already-shipped, already-tested code (no risk of destabilizing `CompensationArchitectureGuardTest`'s guarantees), and (d) directly closes two gaps the ticket explicitly asked to reconcile in §6 (sensitive compensation fields, audit trail) that are unambiguously real today. Slices 1 and 4 (Driver identity + Driver performance) carry materially higher long-term value but Slice 1 is explicitly gated on a CTO decision (§10.1) — that decision should be escalated in parallel, now, so Slice 1/4 can begin immediately once it lands, rather than after.

---
STOP. Not implemented. Awaiting CTO review.
