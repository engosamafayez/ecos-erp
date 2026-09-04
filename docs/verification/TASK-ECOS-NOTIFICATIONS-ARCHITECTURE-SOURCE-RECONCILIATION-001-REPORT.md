# TASK-ECOS-NOTIFICATIONS-ARCHITECTURE-SOURCE-RECONCILIATION-001 — Engineering Report

**Workstream:** ECOS ERP — System Notifications & Alerts (Notifications Batch 01, Task 1 of 5)
**Type:** Architecture / Source Reconciliation / Cross-System Notification Contract / Delivery Platform Design
**Device:** Second Device · **Workspace:** `D:\ECOS-Work\ecos-notifications` · **Branch:** `task/notifications-workstream`

---

## 1. Final Status

**NOTIFICATIONS ARCHITECTURE: COMPLETE**

All required locks (existing-infrastructure reconciliation, ownership, source boundary, recipient/security architecture, Notification Center design, semantic types/priorities, dedupe/grouping, reminder/escalation architecture, channel strategy, preference strategy, module integration matrix, Notification Catalogue, bounded V1, ADR committed, exact Tasks 2–5) are locked in [ADR-047](../adr/ADR-047-notification-platform-v1-architecture.md) and this report. Canonical freshness remains NOT VERIFIED (structurally, not merely procedurally — see §4); per policy this does not block Architecture COMPLETE, and Task 2 is APPROVE WITH PRECONDITION rather than unconditional APPROVE.

---

## 2. Starting HEAD

```
16b0ec85df5774f03ccd6dca042528260d66c216
fix(operations): recover latest planning and driver settlement workspaces
2026-08-31 12:27:55 +0300
```
Branch: `task/notifications-workstream`. Working tree: clean at task start (verified via pre-task gate: `git status --short` returned no output). Remote: `origin → E:/ECOS/ecos-develop` (local filesystem path, confirmed unreachable — §4).

## 3. Architecture Commit

One commit, this task only: `docs(notifications): define system notifications architecture` (see §62 for exact evidence, applied after this report was written).

## 4. Canonical Freshness

**NOT VERIFIED — and independently confirmed structurally BLOCKED, not merely skipped.** Three sibling lanes each attempted, on their own initiative, to verify `E:/ECOS/ecos-develop` reachability from this device family and each failed identically:
- `ecos-iam`: `docs/verification/TASK-ECOS-IAM-FIRST-DEVICE-VERIFICATION-GATE-REPORT.md` — `Test-Path "E:\"` → `False`; conclusion *"a drive letter is registered for E: but no volume is currently mounted."*
- `ecos-reporting`: ADR-045 §0 — same finding.
- `ecos-finance`: closure report §36 — same finding; its own remote (`E:/ECOS/ecos-finance`) is separately unreachable too.

This repo's own baseline (16b0ec85, 2026-08-31) is therefore a **LOCAL INTEGRATED BASELINE** that cannot be checked against canonical `develop` from this device today. All findings below are qualified accordingly (LOCAL INTEGRATED BASELINE / LOCAL CANDIDATE SOURCE / FIRST-DEVICE CANONICAL RECONCILIATION REQUIRED, as applicable) rather than presented as current-canonical.

## 5. Repositories Inspected (Read-Only)

| Path | Branch | HEAD | Working Tree | Notes |
|---|---|---|---|---|
| `D:\ECOS-Work\ecos-chat` | `task/chat-workstream` | `3f183df010b3937faa80723d09da023ba740c566` | Clean | ADR-044 (Internal Collaboration), CTO-ratified 2026-09-02 — most directly relevant precedent found |
| `D:\ECOS-Work\ecos-crm` | `task/crm-workstream` | `2e4a1fcac5f2d88166ed6af9f195cb2f819159c4` | **Dirty** — staged rename of ADR-044→046, not committed | Not modified; read only |
| `D:\ECOS-Work\ecos-finance` | `task/finance-gap-closure` | `42788a10f622d5464190ae086186b5254d1292f5` | Clean (12 commits ahead of its own unreachable origin) | No new ADR minted; zero notification mentions in its 381-line closure report |
| `D:\ECOS-Work\ecos-iam` | `task/iam-workstream` | `2686b85804cd917d55cf1a5a7904ac4d02dba594` | **Dirty** — one untracked file (read, not touched) | First-Device Verification Gate report is the load-bearing freshness evidence (§4) |
| `D:\ECOS-Work\ecos-reporting` | `task/system-reporting` | `b8a950fb7f39115d5fe7458ee4acf7e3f0e33a32` | Clean | ADR-045; confirms Reporting is explicitly excluded from EPS scope |

No sibling was modified, staged, committed, or repaired. Dirty siblings were left exactly as found, per instruction.

---

## 6. Existing Notification Infrastructure (Code-Audited, not doc-inferred)

**Central, real, working foundation:**
- `backend/app/Models/User.php` — `Notifiable` trait, real.
- `backend/database/migrations/2026_07_05_200400_create_notifications_table.php` (+ `2026_07_11_000001_add_updated_at...`) — stock Laravel `notifications` table: `id (uuid), type, notifiable (morph), data, read_at, created_at, updated_at`. **No `company_id` column.**
- `backend/app/Http/Controllers/NotificationController.php` + `backend/routes/api.php:325-327` — `GET /notifications`, `PATCH /notifications/{id}/read`, `POST /notifications/mark-all-read`. Ownership-scoped (404, not 403, for another user's notification — deliberate non-disclosure).
- `backend/tests/Feature/Core/NotificationFeedTest.php` — 9 real tests (auth, ownership isolation, unread filter/count, payload passthrough, mark-read/mark-all-read, pagination cap).
- `frontend/src/components/layout/header/notifications/notification-center.tsx` + `frontend/src/features/notifications/{hooks,services,types}` — real, working bell/drawer, 60-second poll, unread badge, mark read/all-read, source-filter tabs.

**Only real producer:** `backend/Modules/Operations/Preparation/Application/Notifications/` — 5 classes defined, 3 actually fired (`WaveStartedNotification`, `WaveCompletedNotification`, `ShortageDetectedNotification`); 2 dead (`QualityCheckFailedNotification`, `ExceptionRaisedNotification`). `ShortageDetectedNotification` has a dead `toMail()` implementation (unreachable — `via()` excludes `mail`).

**Confirmed fragmentation (coexisting, not replacing the above):**
- `backend/Modules/System/Engineering/` — a wholly separate notification system: own `EngineeringNotification` model/table, own service, own controller (`/api/system/engineering/notifications`), own frontend page. Zero code shared with the central system. Scope confirmed CI/CD-pipeline-only by Reporting's own ADR-045 (Decision 8).
- `frontend/src/features/logistics/operations/pages/alert-center-page.tsx` — a third, unrelated concept: the Exception Registry (`ops_exceptions`) with ack/escalate/reconcile — real and working, but not a notification/delivery mechanism despite bell iconography.
- Marketing/ProviderPlatform, Logistics/Automation, POS — each has notification *intent* that resolves only to `Log::` calls, with source comments explicitly stating they are waiting on a "Notification OS."

**Config reality:** no `laravel/reverb`, no `config/broadcasting.php`, no `routes/channels.php`, no `laravel-echo` anywhere — the bell works by polling only. Queue defaults to `database` in code (Redis intended per `.env` examples, not proven live). Mail defaults to `log`; zero notification code path ever actually sends mail. Zero FCM/APNS/WhatsApp/SMS/Twilio provider config anywhere in `config/`.

**Approved-but-unbuilt target:** `docs/architecture/ENTERPRISE-NOTIFICATION-PLATFORM.md` (EPS-04) — full entity/policy/channel-adapter spec, frozen 2026-07-05, **0% implemented** (`Modules/Core/EnterpriseServices` does not exist anywhere in the repo).

**Frontend stack correction:** actual code is **Vite + React Router** (React 19.2.6, react-router-dom 7.18.0), not Next.js as `docs/CLAUDE.md` states — a pre-existing doc/code gap, noted but not corrected (out of this task's scope).

## 7. Frontend Notification Capability

See §6 above (`notification-center.tsx`) for the real, working component. `frontend/src/components/ds/toast-provider.tsx`/`use-toast.ts` is a real, generic ephemeral toast utility, unrelated to backend notifications today, and is the recommended reuse target for the Popup/Toast mechanism (ADR-047 §26.6). `frontend/src/features/orders/components/order-customer-alerts.tsx` is cosmetic client-derived badges only, with no backend notification tie-in.

## 8. Current-State Classification

**PARTIAL — CONSOLIDATE.** Full reasoning and evidence in ADR-047 §1 and §6 above. Not (A) — EPS-04 is 0% real. Not (D) or (E) — real, tested backend + UI exists. Closest to (C) Laravel-foundation-plus-custom-behavior as the dominant pattern, with genuine (B) fragmentation coexisting (System/Engineering's parallel system). "Consolidate" because both extension (of the real central system) and consolidation (absorbing/bounding the parallel systems, not merging them blindly) are required — see DO-NOT-REIMPLEMENT (§57).

---

## 9–33. Architecture Decisions

All decisions for ownership, producer contract, recipient resolution, semantic types, priorities, deep-link/action security, IAM/data scope, tenant isolation, read/unread/ack/archive, expiry/revalidation, dedupe, grouping, spam/noise control, reminders, escalation, and the CRM/Collaboration/Finance/Orders/Preparation/Distribution/Inventory/Procurement/external-communication boundaries are locked in **[ADR-047](../adr/ADR-047-notification-platform-v1-architecture.md) §3–§25**. This report does not restate them; see the ADR for the normative text and citations. Summary pointers:

| Report item | ADR section |
|---|---|
| Ownership model | §3 |
| Producer contract | §4 |
| Recipient resolution | §5 |
| Semantic types | §6 |
| Priorities | §7 |
| Deep-link/action security | §8 |
| IAM/data scope | §5, §19 |
| Tenant isolation | §19 |
| Read/unread/ack/archive | §9, §13 |
| Expiry/revalidation | §8 |
| Dedupe | §10 |
| Grouping | §11 |
| Spam/noise control | §10, §11, §14 |
| Reminders | §12 |
| Escalation | §13 |
| CRM integration | §22 |
| Collaboration integration | §21 |
| Finance integration | §23 |
| Orders integration | §22 (matrix row, §51 below) |
| Preparation integration | §24, §51 below |
| Distribution/Shipping | §51 below |
| Inventory | §51 below |
| Procurement | §51 below |
| External communication boundary | §20 |

---

## 34–38. Channel Strategy Detail

| Channel | Classification | Evidence |
|---|---|---|
| In-App | **V1** | Real, working, tested (§6) |
| Realtime | **Later** | Reverb 0% installed anywhere org-wide, confirmed independently by this repo and by `ecos-chat` ADR-044 |
| Mobile Push | **Later** | No device-token model anywhere in IAM or elsewhere (confirmed via `ecos-iam` model listing) |
| Email | **Later** | SMTP configured for prod, but zero notification code ever sends mail |
| WhatsApp | **Boundary locked now, channel wiring Later** | Real infra exists but owned by `CustomerEngagement` (conversational); reuse recommended over rebuild (ADR-047 §20) |
| SMS | **Later** | Zero provider config anywhere |
| Digest | **Later** | No aggregation logic exists |
| Quiet Hours | **Architecture locked, Later for build** | UX standard already mocks the toggle; no backend enforcement exists |
| Admin Settings | **Architecture locked, Later for build** | ADR-047 §26.1–§26.2, §26.11 |
| Rule Engine | **NOT REQUIRED** | Task's own caution (§49) confirmed by evidence: no lane anywhere evidenced a need for a generic no-code rule builder; typed catalogue + limited policy config is sufficient |

## 39–45. Templates, Delivery, Audit, Retention, Security, Performance, Reliability

- **Templates/localization:** ADR-047 §17. V1 = creation-time rendering; Later = `NotificationTemplate` keyed to `users.locale` (already shipped, ADR-040).
- **Delivery states/retry:** ADR-047 §18. V1 has no external channel, so no retry/backoff/dead-letter is needed yet.
- **Audit/retention:** ADR-047 §18 (EPS-02/EPS-04 audit overlap, resolved as parallel not merged). Retention: no hardcoded period invented — follow the company-wide archive-not-delete policy already stated in `docs/domain/OWNERSHIP-MODEL.md` §5 once a central retention policy exists; V1 does not hard-delete notifications.
- **Security:** ADR-047 §8, §19. Cross-tenant access, forged deep links, entity-ID enumeration, and unauthorized mark-read are all addressed by: `company_id` scoping (new column, Task 2), destination-side re-authorization (never trust the notification payload as an access grant), and the existing ownership-check pattern already tested in `NotificationController` (404, not 403, preserving non-disclosure).
- **Performance/pagination/unread counts:** the existing `NotificationController` already paginates and caps per-page results (test-verified). Unread-count strategy for V1 continues the existing indexed-query approach; a cached/projected counter is a Later optimization only if volume warrants it — not designed now (no evidence of a volume problem today).
- **Transaction/reliability model:** EPS-01 has no explicit outbox pattern — only "publish within the same DB transaction as the state change, roll back together" plus a subscriber-side dead-letter queue (spec-only, unbuilt). V1's direct-dispatch producer contract (ADR-047 §4) sidesteps this entirely: a `Notification::send()` call inside the same request/transaction as the source action is the existing, working pattern (Preparation, Collaboration) — no new distributed-systems framework is introduced.

---

## 46. Domain Integration Matrix

| Module | Potential Notification | Source Event/Condition | Owner | Recipient Strategy | Type | Priority | Deep Link | Reminder/Escalation | V1/Later | Dependency |
|---|---|---|---|---|---|---|---|---|---|---|
| Commerce/Orders | Order on hold | `orders.order.on_hold` (catalogued, no contract) | Commerce | Entity owner/assignee | Warning | High | Order detail | None | Later | SOURCE EVENT REQUIRED |
| Commerce/Orders | Order confirmed/delivered/dispatched (customer-facing) | `orders.order.confirmed/.delivered` (EVT-COM-001/006, contracted) | Commerce | Customer (external) | Alert | Normal | — | None | Later (channel) | CHANNEL PROVIDER DEPENDENCY |
| Commerce/Orders | Delivery failed | `orders.order.delivery_failed` (catalogued only) | Commerce | CRM/ops | Exception | High | Order detail | None | Later | SOURCE EVENT REQUIRED |
| CRM | Follow-up due/overdue | `CustomerTask.due_at` / relationship-state (real code, no Business Event) | CRM | `assignee_id` | Task/Reminder | Normal→High | Customer/Task | Source-owned reminder | **V1** (direct-dispatch per CRM ADR-046) | CANONICAL DEVELOP RECONCILIATION REQUIRED (ADR-046 not committed) |
| CRM | Ownership reassignment warning/due | `PortfolioAssignment` transitions | CRM | Current owner, then supervisor | Warning/Task | High | Customer | CRM-owned escalation | **V1** | Same as above |
| Inventory | Low stock / out of stock | `inventory.raw_material.low_stock_alert`/`.out_of_stock` (catalogued, no contract) | Inventory | Purchasing / permission holders | Warning/Exception | Normal/Critical | Product | None | Later | SOURCE EVENT REQUIRED + IAM/DATA-SCOPE DEPENDENCY |
| Purchasing | PO awaiting approval | `procurement.purchase_order.submitted` (catalogued, no contract; UX standard already mocks this exact case) | Purchasing | Approver (`po_approval_threshold`) | Approval | High | PO detail | None | Later (high-value, contract-blocked) | SOURCE EVENT REQUIRED |
| Operations/Preparation | Wave started/completed | Already real (`WaveStartedNotification`/`WaveCompletedNotification`) | Preparation | Warehouse worker | Alert | Low | Wave | None | **V1 — extend existing** | READY — EXTEND EXISTING |
| Operations/Preparation | Shortage detected | Already real (`ShortageDetectedNotification`) | Preparation | Worker/supervisor | Exception | High | Wave/Product | None | **V1 — extend existing** | READY — EXTEND EXISTING |
| Operations/Preparation | Wave blocked | `fulfillment.preparation_wave.blocked` (catalogued, no contract; dead-code classes already exist) | Preparation | Supervisor | Exception | Critical | Wave | None | Later (cheap — activate existing dead code) | SOURCE EVENT REQUIRED |
| Logistics/Distribution | Shipment dispatched/failed | `fulfillment.shipment.dispatched`/`.failed` (EVT-FUL-007/009, **contracted**) | Logistics | Ops/CRM | Alert/Exception | Normal/High | Shipment | None | **V1-ready (contract exists)** | READY — EXTEND EXISTING |
| Logistics/Drivers | Driver/vehicle assigned | `logistics.driver.assigned` (catalogued; Notifications not a listed consumer at all) | Logistics | Driver | Assignment | Normal | Trip | None | Later | SOURCE EVENT REQUIRED |
| Finance | Invoice issued/paid (customer-facing) | `finance.invoice.issued`/`.paid` (EVT-FIN-001/002, contracted, EPS-04 already registered consumer) | Finance | Customer (external) | Alert | Normal | — | None | Later (channel) | CHANNEL PROVIDER DEPENDENCY |
| Finance | Invoice overdue (AR) | `finance.invoice.overdue` (catalogued, no contract) | Finance | AR owner + CRM | Warning | High | Invoice | Escalation possible | Later | SOURCE EVENT REQUIRED + BUSINESS POLICY REQUIRED |
| Finance | Approval required (Expense/PO/Payment) | Synchronous rejection today, no event | Finance | Approver | Approval | High | — | None | Later | SOURCE EVENT REQUIRED (needs antecedent-event redesign) |
| IAM | Role/permission changed | Not evidenced as a need | IAM | Affected user | Alert | Low | Profile | None | Later | SOURCE EVENT REQUIRED (if ever wanted) |
| Internal Collaboration | Mention / new message | Already real (`MentionedNotification`/`NewMessageNotification`, ADR-044) | Collaboration | Mentioned/participant user | Mention/Alert | Normal | Conversation | None | **V1 — already dispatching** | READY — EXISTING (align to shared contract only) |
| Internal Collaboration | Task assigned/due | `InternalTask` (real model; due-reminder not confirmed dispatched) | Collaboration | Assignee | Assignment/Reminder | Normal | Task | Unconfirmed | Later (confirm with lane) | CANONICAL DEVELOP RECONCILIATION REQUIRED |
| System Reporting | Async export ready | Reporting's own ADR-045 (Decision 8) already commits to "the existing in-app notification feed" | Reporting | Requesting user | Alert | Low | Export/download | None | **V1-adjacent** (once shared contract exists) | READY — EXTEND EXISTING |

## 47. Notification Catalogue (V1)

| Key | Purpose | Source Module | Source Event/Condition | Recipient | Type | Priority | In-App | Push | Email | WhatsApp | Deep Link | Dedupe | Expiry | Reminder | Escalation | User-Configurable | Dependency |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| `PREPARATION_WAVE_STARTED` | Inform worker a wave began | Preparation | Real, dispatched today | Worker | Alert | Low | ✓ | — | — | — | Wave | wave_id+type | On wave close | No | No | Later | READY |
| `PREPARATION_WAVE_COMPLETED` | Inform worker a wave finished | Preparation | Real, dispatched today | Worker | Alert | Low | ✓ | — | — | — | Wave | wave_id+type | On read | No | No | Later | READY |
| `PREPARATION_SHORTAGE_DETECTED` | Flag a material shortage | Preparation | Real, dispatched today | Worker/supervisor | Exception | High | ✓ | Later | — | — | Wave/Product | wave_id+material_id | 24h | No | Reuse Alert Center | Later | READY |
| `COLLAB_MENTION` | Notify a mentioned user | Collaboration | Real (ADR-044) | Mentioned user | Mention | Normal | ✓ | — | Later | — | Conversation | message_id | On read | No | No | Yes | READY |
| `COLLAB_NEW_MESSAGE` | Notify a conversation participant | Collaboration | Real (ADR-044) | Participant | Alert | Normal | ✓ | — | — | — | Conversation | message_id | On read | No | No | Yes | READY |
| `CRM_FOLLOWUP_DUE` | Remind owner of a due follow-up | CRM | `CustomerTask.due_at` (CRM ADR-046) | Assignee | Reminder | Normal | ✓ | Later | Later | — | Customer/Task | task_id+date | At due date | Yes (CRM-scheduled) | No | Yes | CANONICAL RECONCILIATION (ADR-046 not committed) |
| `CRM_FOLLOWUP_OVERDUE` | Escalate an overdue follow-up | CRM | Relationship-state `OVERDUE` | Assignee | Warning | High | ✓ | Later | — | — | Customer/Task | task_id+date | Resolved on action | Yes | Supervisor on threshold | Limited | Same as above |
| `CRM_REASSIGNMENT_WARNING` | Warn owner of pending reassignment | CRM | `PortfolioAssignment` projection | Current owner | Warning | High | ✓ | — | — | — | Customer | customer_id+stage | On reassignment | No | CRM-owned | No | Same as above |
| `LOGISTICS_SHIPMENT_DISPATCHED` | Inform of dispatch | Logistics | EVT-FUL-007 (contracted) | Ops/CRM | Alert | Normal | ✓ | — | — | — | Shipment | shipment_id | On read | No | No | Yes | READY (needs consumer built) |
| `LOGISTICS_SHIPMENT_FAILED` | Flag a failed delivery | Logistics | EVT-FUL-009 (contracted) | Ops/CRM | Exception | High | ✓ | Later | — | — | Shipment | shipment_id | 48h | No | Reuse Alert Center | No | READY (needs consumer built) |
| `REPORTING_EXPORT_READY` | Inform requester an export is ready | Reporting | Reporting's own ADR-045 Decision 8 | Requesting user | Alert | Low | ✓ | — | — | — | Download | export_id | 7 days | No | No | No | READY |

Later (contract-blocked) candidates are listed in the Domain Integration Matrix (§46) above rather than duplicated here — they are not finalized as catalogue keys until their source event is contracted.

## 48. Do-Not-Notify List

- Ordinary successful CRUD saves (use ephemeral toast only, per ADR-047 §26.6).
- Every inventory stock movement (only threshold breaches, once contracted, are notification-worthy).
- Every order status transition (only on-hold/exception states, once contracted — not every `orders.order.*` event).
- Every message in a conversation the user is actively viewing (Collaboration's own unread cursor already covers active-conversation state; a Notification would be redundant noise).
- Every automated accounting/posting event (Finance's routine `posted` transitions are not notification-worthy; only overdue/approval-required conditions are, once contracted).
- Routine `Notification` dispatch retries at the transport layer (§ADR-047 §25 DO-NOT-REIMPLEMENT) — a channel retry must never create a second visible in-app row.

## 49–56. Data Model, Authority Matrix, DO-NOT-REIMPLEMENT, Source Gaps

**Conceptual data model (architecture only, no migration written):** extend the existing `Notification` (the real `notifications` table) with `company_id, priority, source_module, deep_link (json), dedupe_key, group_key, expires_at, dismissed_at`. `NotificationDelivery`, `NotificationTemplate`, `UserNotificationPreference`, `NotificationSchedule` remain conceptual/Later per ADR-047 §17–18, §14. No redundant table is created where an existing one already satisfies the need (the vanilla `notifications` table is reused, not replaced).

**Authority matrix:**

| Authority | Owns |
|---|---|
| Source modules (Commerce, CRM, Inventory, Purchasing, Preparation, Logistics, Finance, Manufacturing) | Business conditions/events, all source truth |
| Notifications (this workstream) | Delivery, attention/read-state, preferences, dedupe/grouping |
| IAM | Users, permissions, tenant/company, data scope |
| CustomerEngagement | External *conversational* customer communication (WhatsApp/Messenger/Instagram inbox) — ADR-047 §20 |
| Internal Collaboration | Internal messaging/mentions/tasks — ADR-047 §21 |
| System Reporting | Analytics/metrics (explicitly excluded from EPS per `ENTERPRISE-PLATFORM-SERVICES.md` §7) |

No overlap ambiguity remains after ADR-047 §20–23; the CustomerEngagement and Collaboration boundaries in particular were previously undocumented anywhere and are resolved here for the first time using code-level evidence.

**DO-NOT-REIMPLEMENT:** full list in ADR-047 §25.

**Source gap classification (representative, non-exhaustive — full detail in the Domain Integration Matrix and Notification Catalogue above):**

| Gap | Classification |
|---|---|
| `notifications` table lacks `company_id`, priority, dedupe/group keys | NOTIFICATION CORE REQUIRED |
| IAM reverse permission-holder lookup | IAM/DATA-SCOPE DEPENDENCY |
| Inventory/Procurement/Finance/Logistics threshold & approval events uncontracted | SOURCE EVENT REQUIRED |
| Finance/CRM query-consumer registration for source-state revalidation | SOURCE QUERY REQUIRED |
| Mobile push provider (FCM/APNS) | CHANNEL PROVIDER DEPENDENCY |
| ADR-047's own number vs. true canonical `develop` | CANONICAL DEVELOP RECONCILIATION REQUIRED |
| CustomerEngagement/EPS-04 WhatsApp boundary formal doc | CANONICAL DEVELOP RECONCILIATION REQUIRED (resolved in this ADR pending formal doc) |
| CRM ADR-046 EPS-04 migration commitment language | BUSINESS POLICY REQUIRED (CTO/CRM lane decision, flagged not enforced) |
| Admin notification-policy settings, quiet hours, digest, sound | DEFERRED (Task 3–4) |

---

## 57. Engineering Context (Persistent)

No cross-task persistent "engineering context" file (e.g. a `MEMORY.md`-equivalent) exists anywhere in this checkout, nor in any of the five sibling lanes inspected — each precedent lane (`ecos-chat`, `ecos-crm`, `ecos-finance`, `ecos-iam`, `ecos-reporting`) recorded its own workstream state as an ADR + engineering report pair only. Per that established convention, this section **is** the persistent engineering-context record for the Notifications workstream; no separate file was invented.

- **Workstream:** ECOS Notifications & Alerts, Batch 01, Task 1 of 5 complete (architecture only).
- **Ownership:** locked — source modules own business truth; Notifications owns delivery/attention/read-state (ADR-047 §3).
- **Source authorities:** Commerce, CRM, Inventory, Purchasing, Preparation, Logistics, Finance, Manufacturing, IAM, CustomerEngagement, Internal Collaboration — see Authority Matrix (§49 above).
- **Protected boundaries:** CustomerEngagement (conversational vs. transactional WhatsApp), Internal Collaboration (conversation-unread ≠ notification-unread), CRM (due-date/ownership truth), Finance (approval/threshold truth) — ADR-047 §20–23.
- **Current baseline:** `16b0ec85df5774f03ccd6dca042528260d66c216` (this repo), local integrated baseline; canonical `develop` unreachable (§4).
- **Canonical freshness:** NOT VERIFIED (structurally blocked, §4).
- **V1:** locked, "Vanilla-Plus" — extend the existing real foundation, not a from-scratch EPS-04 build (ADR-047 §24).
- **Open dependencies:** IAM reverse permission-holder lookup; source-event contracts for Inventory/Procurement/Finance/Logistics thresholds; CRM ADR-046 commit status; `Modules/Platform/EventPlatform`'s real implementation depth (unverified in this audit).
- **Tasks 2–5:** exact scope in ADR-047 §28. Task 2 recommendation: APPROVE WITH CANONICAL RECONCILIATION PRECONDITION (ADR-047 §29).
- **Do not mark implementation complete:** no code, migration, model, controller, route, or frontend change has been made under this task. Architecture and documentation only.

## 58. ADR

**[ADR-047-notification-platform-v1-architecture.md](../adr/ADR-047-notification-platform-v1-architecture.md)** — Proposed, awaiting CTO ratification. Numbering: cross-lane collision-free against 5 sibling candidates inspected 2026-09-03 (044/045/046 already claimed); canonical reconciliation required once first-device `develop` is reachable (ADR-047 §0).

## 59. Tasks 2–5

See ADR-047 §28 (exact scope per task) and §29 (Task 2 release recommendation: **APPROVE WITH CANONICAL RECONCILIATION PRECONDITION**).

## 60. Task 2 Release Recommendation

**APPROVE WITH CANONICAL RECONCILIATION PRECONDITION.** Full rationale in ADR-047 §29.

## 61. Required Final State

```
NOTIFICATIONS ARCHITECTURE:        COMPLETE
CURRENT INFRASTRUCTURE:            PARTIAL
NOTIFICATIONS OWNERSHIP:           LOCKED
SOURCE MODULE BOUNDARY:            LOCKED
NOTIFICATION CENTER:               DESIGNED
ACTIONABLE DEEP LINKS:             LOCKED
IAM / DATA SCOPE:                  LOCKED
DEDUPE:                            LOCKED
GROUPING:                          LOCKED
REMINDERS:                         LOCKED
ESCALATION:                        LOCKED
PREFERENCES:                       LOCKED
IN_APP:                            V1
REALTIME:                          LATER
MOBILE PUSH:                       LATER
EMAIL:                             LATER
WHATSAPP:                          LATER
QUIET HOURS:                       LATER
DIGEST:                            LATER
RULE BUILDER:                      NOT REQUIRED
NOTIFICATION CATALOGUE:            COMPLETE
V1:                                LOCKED
CANONICAL DEVELOP FRESHNESS:       NOT VERIFIED
IMPLEMENTATION:                    NOT STARTED
TASK 2:                            NOT STARTED
TASK 2 RELEASE:                    APPROVE WITH PRECONDITION
```

## 62. Exact Git Evidence

Pre-task gate (verified before any research began):
```
HEAD:   16b0ec85df5774f03ccd6dca042528260d66c216
Branch: task/notifications-workstream
Status: clean
Remote: origin -> E:/ECOS/ecos-develop (fetch/push)
```

Post-task commit (this task's sole commit, architecture/documentation only, not pushed):
```
docs(notifications): define system notifications architecture

TASK-ECOS-NOTIFICATIONS-ARCHITECTURE-SOURCE-RECONCILIATION-001
```
Files: `docs/adr/ADR-047-notification-platform-v1-architecture.md`, `docs/verification/TASK-ECOS-NOTIFICATIONS-ARCHITECTURE-SOURCE-RECONCILIATION-001-REPORT.md`.

---

## 63. Mandatory Final Notification

No cross-task/cross-agent "notify on completion" mechanism exists anywhere in this repo (confirmed independently by this task's own research and by `ecos-reporting`'s ADR-045 Decision 11, which found the same via direct audit: *"this ADR and its accompanying engineering report constitute the notification... the de facto and only pattern used across 250+ prior task-completion reports."*). Per that established, evidence-confirmed convention, this report is the final notification.

```
Task:                    TASK-ECOS-NOTIFICATIONS-ARCHITECTURE-SOURCE-RECONCILIATION-001
Final Status:            COMPLETE
Summary:                 Notifications architecture locked as "Vanilla-Plus" — extend the
                          existing real, tested Laravel notification foundation (already
                          independently adopted by 3 sibling lanes) rather than build the
                          frozen, 0%-implemented EPS-04 spec from scratch. Four previously
                          undocumented boundaries resolved (CustomerEngagement, Collaboration,
                          CRM, Finance) using code-level evidence. ADR-047 selected as the
                          cross-lane collision-free number (044/045/046 already claimed by
                          sibling lanes).
Current Infrastructure:  PARTIAL — CONSOLIDATE
Notifications Ownership: LOCKED
Notification Center:     DESIGNED
Notification Catalogue:  COMPLETE
V1:                       LOCKED
Canonical Freshness:     NOT VERIFIED (structurally blocked — E: drive unmounted, confirmed
                          independently by 3 sibling lanes)
Task 2 Release:          APPROVE WITH PRECONDITION
User Action Required:    CTO Review.
Next:                    Engineering Report -> CTO Review.
```

**STOP. Task 2 is not begun automatically.**
