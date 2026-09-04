# ADR-047: Enterprise Notification Platform — V1 Architecture & Source Reconciliation

**Document:** ADR-047
**Version:** 1.0
**Status:** Proposed — Awaiting CTO Ratification
**Date:** 2026-09-03
**Task:** TASK-ECOS-NOTIFICATIONS-ARCHITECTURE-SOURCE-RECONCILIATION-001 (Notifications Batch 01, Task 1 of 5)
**Parent:** `docs/architecture/ENTERPRISE-NOTIFICATION-PLATFORM.md` (EPS-04), `docs/architecture/ENTERPRISE-PLATFORM-SERVICES.md`, `docs/ux/NOTIFICATION-UX-STANDARD.md`
**Supersedes:** none (first Notifications-specific ADR in this repo)

---

## 0. ADR Numbering Reconciliation

This repo's own `docs/adr/` tops out at ADR-043 (no local collision at ADR-044+). However, per this task's mandate to inspect sibling candidates before assuming a number, five sibling workstream checkouts were inspected read-only:

| Number | Claimed by | Status (as of 2026-09-03) |
|---|---|---|
| ADR-044 | `ecos-chat` — Internal Collaboration Bounded Context | Committed, final |
| ADR-044→045 | `ecos-reporting` — System Reporting & Analytics Architecture | Committed rename |
| ADR-044→046 | `ecos-crm` — CRM Bounded Context & Sales Execution | **Staged, not yet committed** |
| ADR-047 | *(unclaimed by any of the five as of this scan)* | Selected here |

**Recorded status: CROSS-LANE COLLISION-FREE (against the 5 sibling lanes reachable from this device) — CANONICAL RECONCILIATION REQUIRED** against the true first-device `develop` before this number is permanent. First-device `develop` (`E:/ECOS/ecos-develop`) was independently confirmed **unreachable** (registered drive letter, no volume mounted) by three separate sibling verification attempts (`ecos-iam` TASK-ECOS-IAM-FIRST-DEVICE-VERIFICATION-GATE-REPORT.md, `ecos-reporting` ADR-045 §0, `ecos-finance` closure report §36) — this is a structural block on this device family, not a skipped step.

---

## 1. Context

`ENTERPRISE-NOTIFICATION-PLATFORM.md` (EPS-04) is a CTO-approved, frozen (2026-07-05) specification for a full policy-driven, multi-channel notification platform. It is **0% implemented** — no `Modules/Core/EnterpriseServices/NotificationPlatform` exists anywhere in this codebase, and byte-identical copies of the same spec exist untouched in all five sibling checkouts.

What actually exists and works today is narrower and different in shape: Laravel's stock `Notifiable`/`Notification` classes writing to a generic `notifications` table, read through a real, tested `NotificationController` and a real, working header-bell UI (`notification-center.tsx`, 60-second polling). This foundation is already in active, independent use by three lanes:

- **Operations/Preparation** (this repo) — 3 of 5 defined `Notification` subclasses are actually fired in production code paths.
- **Internal Collaboration** (`ecos-chat`, ADR-044, CTO-ratified 2026-09-02) — `MentionedNotification`/`NewMessageNotification`, explicitly framed as *"not a new notification engine... if the Enterprise Notification Platform is ever implemented, Collaboration's stock-Laravel notifications should migrate to it."*
- **CRM** (`ecos-crm`, ADR-046, staged) — plans identical reuse, citing Preparation as precedent, for follow-up/overdue/reassignment notifications.

Three independent lanes converging on the same pragmatic answer, without coordinating with each other, is strong bottom-up evidence for what V1 actually is. This ADR's central act is to **elevate that convergence into one explicit, cross-cutting decision**, close the specific open questions this task was commissioned to answer, and draw the boundary lines the frozen architecture left ambiguous — rather than either (a) rubber-stamping a from-scratch EPS-04 build no lane is actually pursuing, or (b) leaving every module to independently re-justify the same shortcut forever.

Full findings, evidence, and file:line citations are in the companion Task 1 Engineering Report. This ADR states decisions only.

---

## 2. Decision Summary

| # | Area | Decision |
|---|---|---|
| 1 | Ownership | Source modules own business truth; Notifications owns delivery/attention/read-state only |
| 2 | Source-module boundary | Notifications is a Shared Kernel (CTX-11); never an Actor on any other module's commands |
| 3 | Producer contract | V1 = direct dispatch (Vanilla-Plus); event-sourced (EPS-01) is the named future migration, not V1 |
| 4 | Recipient resolution | Reuse IAM's `AuthorizationGatewayInterface`/`DataScope`/`scopedTo()`; flag missing reverse permission-holder lookup |
| 5 | Semantic model | Adopt existing approved taxonomy: Alert / Task / Approval / Assignment / Warning / Mention / AI Notification / Exception |
| 6 | Priority | LOW / NORMAL / HIGH / CRITICAL, orthogonal to Type |
| 7 | Deep-link security | Typed reference only, never a raw URL; destination re-authorizes independently |
| 8 | Read/unread | `read_at` (exists); never affects source-domain workflow state |
| 9 | Dedupe | `dedupe_key` — greenfield, V1 required |
| 10 | Grouping | `group_key` + UI-side collapsing — greenfield, V1 required |
| 11 | Reminders | Source module owns due date; direct-dispatch in V1, Notifications-scheduled Later |
| 12 | Escalation | Reuse existing Exception Registry (`ops_exceptions`) ack/escalate workflow where applicable; generic `NotificationPolicy.escalation_rules` is Later |
| 13 | Preferences | Architecture locked now; `UserNotificationPreference` table is Later (Task 3) |
| 14 | Channels | IN_APP = V1; REALTIME/PUSH/EMAIL/WHATSAPP/SMS/WEBHOOK = Later |
| 15 | Realtime/fallback | Polling is canonical for V1 (Reverb is 0% installed org-wide); realtime is an additive upgrade, never a correctness dependency |
| 16 | Templates/localization | V1 renders at creation time using acting locale; template-key+per-recipient-locale model (using shipped `users.locale`) is Later |
| 17 | Delivery/audit | V1 has no external channel, so "delivery" = row insert; full `NotificationDelivery` audit is Later |
| 18 | IAM/security | `company_id` tenant scoping mandatory; destination-side re-authorization mandatory |
| 19 | CustomerEngagement boundary | CEP owns 2-way conversational messaging; EPS-04 owns 1-way transactional/system messaging; share WhatsApp provider infra |
| 20 | Collaboration boundary | Fully resolved by ADR-044; conversation-unread ≠ notification-unread; adopt as-is |
| 21 | CRM boundary | CRM owns due-date/ownership/reassignment truth; recommend CRM also publish Business Events, not only direct-dispatch |
| 22 | Finance boundary | Finance owns AP/AR/GL truth; no notification hooks defined yet by Finance — all Finance catalogue entries are Later |
| 23 | V1 scope | "Vanilla-Plus": extend the existing real foundation; do not build the full EPS-04 DDD module now |
| 24 | DO-NOT-REIMPLEMENT | See §25 |
| A–L | CTO Addendum (settings/priority/popup/sound) | See §26 |

---

## 3. Ownership & Source-Module Boundary

Per `docs/domain/OWNERSHIP-MODEL.md` §3 (Platform Ownership): *"Notification \| EPS-04 \| Created by platform; consumed by recipient User."* Per `docs/contracts/COMMAND-CONTRACTS.md`: Notifications' only command is `CMD-EPS-002 SendNotification`; it is never listed as an `Actor` on any other module's command. Per `docs/contracts/BOUNDARY-CONTEXT-MAP.md`: Notifications is CTX-11, a Shared Kernel — *"the pipe, not the water. It has no business logic."*

**Locked:** Source modules (Commerce/Orders, CRM, Inventory, Purchasing, Operations/Preparation, Logistics/Distribution/Drivers, Finance, IAM, Manufacturing) remain sole authority over their own state. Notifications may read (via events or registered queries) and may deliver/track attention state; it may never mutate source-domain state, and cannot command another module to do so — this is a hard architectural boundary, not a convention.

---

## 4. Producer Contract

Three options existed: (A) event-sourced via EPS-01, (B) explicit Intent/Command, (C) direct Laravel `Notification` from source module, (D) hybrid.

**Evidence:** `docs/domain/DOMAIN-EVENT-CATALOG.md` §12 already models Notifications as subscribing to *"ALL (policy-filtered)"* events — the only module with universal subscription — which is the correct long-term shape. But EPS-01's own implementation status is unconfirmed (a `Modules/Platform/EventPlatform` directory exists locally under a different path than the spec's `Modules/Core/EnterpriseServices/EventPlatform`, but its actual BusinessEvent publish/subscribe depth was not verified in this audit). Meanwhile every lane that has actually shipped notification behavior (Preparation, Collaboration) uses direct dispatch (C), and CRM plans the same.

**Locked — Option D (Hybrid), with an explicit sequencing rule:**
- **V1:** source modules dispatch directly via the shared contract defined in §24, exactly as Preparation/Collaboration do today.
- **Later:** as EPS-01's real implementation depth is confirmed, migrate high-volume/cross-cutting producers to event-sourced consumption, matching Collaboration's own explicit migration commitment. Direct dispatch is not deprecated for low-volume, module-local cases even after EPS-01 exists — it is a legitimate permanent option for simple cases, not merely a stopgap.
- Notifications must never be an Actor on a Command (§3); "hybrid" refers only to Event vs. direct-dispatch as production mechanisms, not to Notifications acquiring write authority elsewhere.

---

## 5. Recipient Resolution

**Locked:** Recipient resolution reuses canonical IAM contracts exclusively — `Modules\IAM\Domain\Contracts\AuthorizationGatewayInterface` (`can`, `scopeFor`, `decide`), the `DataScope` enum, and the `Builder::scopedTo($user, $resource, $ownerColumn)` query macro (ADR-038). Notifications must not build its own role/permission/scope engine.

**Confirmed gap:** no reverse lookup ("list all users holding permission P in scope S") exists anywhere in IAM today — every existing IAM contract answers "can this user act," never "who can act." This blocks the `PERMISSION HOLDERS` and `TEAM/SCOPE` recipient strategies from working until a contract such as `AuthorizationGatewayInterface::usersWithPermission(permission, scopeType, scopeId): User[]` is added to IAM. **Classification: IAM/DATA-SCOPE DEPENDENCY.** Until resolved, only `EXPLICIT USER`, `ENTITY OWNER`, and `ASSIGNED USER` recipient strategies are usable (all resolvable from the source aggregate directly, e.g. `assignee_id`, `created_by`).

---

## 6. Semantic Model

`docs/ux/NOTIFICATION-UX-STANDARD.md` §2 already locks, CTO-approved: **Alert, Task, Approval, Assignment, Warning, Mention, AI Notification, Exception** (each with a "Requires Action" flag).

**Locked:** adopt this taxonomy as-is. Do not introduce a competing 5-value model. Where useful for internal reasoning, this task's suggested categories map onto it: INFORMATION→Alert, ACTION_REQUIRED→Task/Approval/Exception, REMINDER→Task (time-triggered), WARNING→Warning, SUCCESS/COMPLETION→Alert. This mapping is descriptive only — the 8-value taxonomy is the one system of record.

---

## 7. Priority

**Locked:** `LOW | NORMAL | HIGH | CRITICAL`, already specified in `NOTIFICATION-UX-STANDARD.md` §4 with badge treatment, and independently confirmed by the CTO addendum. Priority is always orthogonal to Type (e.g., Type=Task, Priority=Critical is valid; Type is never inferred from Priority or vice versa). No additional severity levels.

---

## 8. Actionable / Deep-Link Security

**Locked fields** (per task §13): `source_module`, `entity_type`, `entity_id`, `action_key`, optional `route/context`. The notification payload stores this typed reference — never a raw frontend URL.

**Locked security rule:** seeing a notification never grants access. The destination endpoint re-checks authorization independently via `AuthorizationGatewayInterface::can()` at render time — the notification is a pointer, not a capability grant. This is directly supported by existing precedent: `QRY-COM-002 OrderDetailQuery` is already registered "Company-scoped; returns 403 if company_id mismatch," and the current `NotificationController` already enforces per-notification ownership (test-verified: accessing another user's notification returns 404, not 403 — deliberately not revealing existence). Preserve this pattern.

**Source-state revalidation:** for actionable notifications, the frontend must re-fetch current state via a registered Query Contract before rendering an action button (per ADR-024 Single Source of Truth — the notification payload is a snapshot, never authoritative). `QRY-COM-002` is already open to "any module" and is the first usable hook; Finance/CRM/Inventory queries are **not yet registered for Notifications as a consumer** (`CON-GOV-010` requires registration before adoption) — **classification: SOURCE QUERY REQUIRED**, a concrete Task 2/3 prerequisite per domain.

---

## 9. Read / Unread

**Locked:** `read_at` timestamp (already exists on the real `notifications` table); "mark all read" already implemented and tested. Reading a notification never changes source-domain workflow state — this is enforced by construction, since Notifications has no command authority over any other module (§3).

---

## 10. Deduplication

**Confirmed 100% absent from code** — no `dedupe_key`/idempotency concept exists anywhere in the current notification-adjacent code. **Locked, greenfield (NOTIFICATION CORE REQUIRED):** `dedupe_key` derived from `(notification_type, recipient_id, source entity, relevant time window)`; uniqueness enforced at write time. Distinct genuine events (e.g., two separate shortage detections on different days) must not be suppressed — dedupe collapses re-delivery of the *same* underlying condition, not repeated occurrences of a recurring one.

---

## 11. Grouping

**Confirmed 100% absent from code** (spec's `group_key` field has zero real usage). **Locked, greenfield:** `group_key` column + UI-side collapsing ("8 orders require payment review"). Individual notification rows are preserved for audit; grouping is a presentation concern only, matching the existing UX standard's tab counts (`TASKS (4)`, `APPROVALS (2)`).

---

## 12. Reminders

**Locked:** source module owns the due date, always (CRM's `CustomerTask.due_at`, `crm_customer_relationship_states`, etc.). V1: source modules schedule and dispatch their own reminder notifications directly (matching CRM's ADR-046 plan). Later: once EPS-01/EPS-04 scheduling infrastructure is real, reminder *delivery scheduling* migrates to Notifications while the due-date truth never leaves the source module. No second due-date store is ever created inside Notifications.

---

## 13. Escalation

**Locked:** do not build a generic escalation engine in V1. The existing Exception Registry / "Alert Center" (`ops_exceptions`, real, working ack/escalate/reconcile workflow) already provides escalation for operational exceptions today — Notifications should deep-link into it, not duplicate it. `NotificationPolicy.escalation_rules` (glossary-defined, policy-engine-driven) is the canonical Later mechanism once the Policy Engine's `NotificationPolicyContract` (currently undefined — see §25 gap) exists. CRM's own reassignment-escalation (`supervisor_approval_required` gate) remains CRM-owned in the interim, per §21.

---

## 14. Preferences

**Locked, architecture only (Later for implementation):** three-tier precedence — `MANDATORY SYSTEM POLICY > COMPANY DEFAULT > USER PREFERENCE`. In-app delivery is always mandatory and cannot be disabled by any tier below system policy, matching `NOTIFICATION-UX-STANDARD.md` §7's existing rule ("in-app notifications are always delivered regardless of preferences"). No `user_notification_preferences` table exists today — this is a Task 3 build. See §26.5 for the full precedence and per-channel detail requested by the CTO addendum.

---

## 15. Channel Strategy

| Channel | Reality (code-audited) | Classification |
|---|---|---|
| In-App | Real, working, tested (`notifications` table, `NotificationController`, `notification-center.tsx`) | **V1 — extend** |
| Realtime (Reverb/WebSocket) | 0% installed anywhere org-wide (no `laravel/reverb`, no `config/broadcasting.php`, no `routes/channels.php`, no `laravel-echo` — confirmed independently by this repo and by Collaboration's ADR-044) | **Later** |
| Mobile Push | No device-token/push-token model anywhere in IAM or elsewhere | **Later — Notification Core + Channel Provider dependency** |
| Email | SMTP configured for prod envs, but zero notification code path ever sends mail; the one `toMail()` implementation is dead code | **Later** |
| WhatsApp | Real infra exists, but owned by `CustomerEngagement` (conversational) today; EPS-04's own ACL spec covers transactional use only | **Boundary locked now (§19); channel wiring — Later, reuse CustomerEngagement's provider** |
| SMS | Zero provider configuration anywhere | **Later — Channel Provider dependency** |
| Webhook | Spec'd in EPS-04 only; no evidenced demand | **Later, low priority — not a V1 concern** |

---

## 16. Realtime / Fallback

**Locked:** polling is the canonical V1 transport (matches the current, working 60-second poll). Realtime delivery (once Reverb is actually installed — an org-wide infrastructure decision outside this lane's authority, also gating Collaboration's own Task 3 per ADR-044) is a pure latency upgrade: persistent storage remains the single source of truth regardless of transport. Notification correctness must never depend on WebSocket availability — this is enforced by construction since V1 has no other option.

---

## 17. Templates / Localization

**Locked for V1:** notification title/body render at creation time using the acting backend request's locale context — matching the current code pattern (no template abstraction exists today). This means historical notifications will not re-localize if a user later changes language preference; this tradeoff is accepted for V1 and revisited when `NotificationTemplate` (per-locale, keyed, versioned) is built (Later, Task 3), at which point the per-recipient locale source is the **already-shipped** `users.locale` column (ADR-040, delivered 2026-08-04) — reuse it, do not invent a second locale store.

**Correction to scope:** `ADR-037-localization-guard.md` governs frontend static UI-chrome strings only (an ESLint rule pair) and does **not** address dynamic, DB-stored, per-tenant notification template bodies. Do not cite it as solving notification localization — it doesn't reach this surface.

---

## 18. Delivery / Audit

V1 ships in-app only, so "delivery" is a synchronous row insert — no retry/backoff/dead-letter is needed until a second (external) channel exists. Full `NotificationDelivery` (per-channel, per-recipient, `pending→sending→delivered→failed→bounced→expired`) is a Later build, gated on the first external channel actually shipping.

**Audit overlap, resolved:** EPS-02 (Timeline) and EPS-04 (Notifications) both independently claim to be "the audit trail" for their own entity, with no cross-reference in either spec. **Locked:** keep them parallel, not merged. Both are independent consumers of the same source Business Event once events exist; Notifications' own `NotificationDelivery` (Later) remains a channel-delivery audit, distinct in grain and purpose from Timeline's object-history audit. No new duplicate audit engine is created — reuse this existing split rather than resolving it into one system.

---

## 19. IAM / Security

**Locked:** every notification-bearing table carries `company_id` directly (today, only reachable indirectly via `notifiable→User→company_id`; Task 2 must add the column directly, matching the EPS-04 spec's own stated pattern for `notification_deliveries` — ULID identity, monthly partitioning). This decouples Notifications' tenant correctness from the pace of other modules' own `company_id` migrations: per ADR-007, **`customers` and `orders` still have no `company_id` column** (both "Phase 2 — not yet scoped" as of the last IAM ADR read) — Notifications does not need to wait on that migration because the triggering Business Event envelope (once used) already carries `company_id` directly, and V1's direct-dispatch path resolves `company_id` from the acting User/company context at dispatch time, not by joining through Customer/Order.

Destination-side re-authorization (§8) and existing per-user ownership enforcement in `NotificationController` (test-verified) are both preserved, not replaced.

---

## 20. CustomerEngagement (CEP) Boundary

**Major reconciliation finding:** no CustomerEngagement/CEP architecture document exists anywhere in `docs/`, but `backend/Modules/CustomerEngagement` is a real, mature module (`Conversation`, `Message`, `Lead`, `SlaPolicy`/`SlaViolation`, `RoutingRule`, a `ChannelProviderContract` with real `WhatsAppProvider`/`MessengerProvider`/`InstagramProvider` implementations, a unified inbox). This directly conflicts with `docs/contracts/ANTI-CORRUPTION-LAYER.md`'s frozen claim that WhatsApp is owned outright by EPS-04.

**Locked resolution (the line this ADR draws, since no prior doc did):**
- **CustomerEngagement owns** 2-way, session-based, customer-initiated **conversational** messaging (inbox, SLA, routing, assignment) across WhatsApp/Messenger/Instagram. This is customer communication, not a system notification, and stays entirely out of Notifications' scope.
- **EPS-04 Notifications owns** 1-way, system-triggered, **transactional template** messages (order confirmation, shipment dispatch, delivery confirmation, invoice ready, OTP) to customers, plus all internal/operational notifications — matching the ACL's `TemplateRegistry` intent.
- Both share the same scarce underlying resource (WhatsApp Business API credentials/rate limits). **When EPS-04's WhatsApp channel adapter is eventually built (Later), it must reuse CustomerEngagement's existing `ChannelProviderContract`/`WhatsAppProvider` and credentials — never a second, independent WhatsApp Business API integration.** This satisfies this task's own instruction (§44) not to embed a new WhatsApp transport where another authority already exists, now that the authority is confirmed to be code-real, if doc-absent.
- **Classification: CANONICAL DEVELOP RECONCILIATION REQUIRED** — recommend a follow-up architecture note formalizing this split in `docs/` (out of this task's scope to write; CTO's call on ownership).

---

## 21. Internal Collaboration Boundary

Fully resolved by `ecos-chat`'s ADR-044 (Accepted, CTO-ratified 2026-09-02) — the freshest and most directly on-point sibling precedent found in this research. **Locked, adopted as-is, no changes proposed:**
- Collaboration owns `Conversation`/`Message`/`ConversationParticipant`/`MessageMention`/`InternalTask` — entirely separate tables from CustomerEngagement's `cep_*` tables; no code cross-reference between the two.
- **Conversation-unread is structurally distinct from notification-unread**, exactly as this task anticipated: unread is a cursor (`last_read_message_id`/`last_read_at`) on `ConversationParticipant`; `MentionedNotification`/`NewMessageNotification` are separate, already dispatch to the same vanilla `notifications` table Notifications is extending.
- ADR-044 already commits: *"if the Enterprise Notification Platform is ever implemented, Collaboration's stock-Laravel notifications should migrate to it."* No action needed from this ADR beyond ensuring Task 2's shared producer contract (§24) is compatible with Collaboration's existing two classes so that migration commitment is cheap to honor later.

---

## 22. CRM Boundary

CRM (`ecos-crm`, ADR-046, **staged, not yet committed**) owns: `CustomerTask.due_at`, the derived `crm_customer_relationship_states` (`DUE_TODAY`/`OVERDUE`/`DORMANT`/`AT_RISK`), the ownership-inactivity clock (`last_qualifying_action_at`, derived only from qualifying `CustomerActivity`, never hand-set), `PortfolioAssignment`/`PortfolioAssignmentHistory`, and the derived reassignment-warning projection. All of this is CRM-owned truth; Notifications must never compute a second inactivity clock or ownership state.

**Gap flagged, not resolved unilaterally:** CRM's ADR-046 commits to the same direct-dispatch reuse pattern as Preparation/Collaboration (§4), but — unlike Collaboration's ADR-044 — its 393 lines never mention "EPS," "EPS-04," or "BusinessEvent," and it does not frame its relationship-state transitions as Business Events available to any other consumer. **Recommendation for CTO review, before CRM's ADR-046 is finalized:** add the same explicit EPS-04-migration-commitment language Collaboration used, and additionally publish `DUE_TODAY`/`OVERDUE`/`REASSIGNMENT_DUE` transitions as canonical Business Events (per `DOM-GOV-004`, "every aggregate must emit an event per state change") even while continuing direct-dispatch in the interim — this gives Notifications (and any future consumer) an event-sourced hook without requiring CRM to wait on EPS-04's completion. This ADR does not modify CRM's architecture; it only flags the gap.

---

## 23. Finance Boundary

Finance (`ecos-finance`, closure report, 2026-09-03) owns AP/AR/GL/Budget/Expense state and its approval workflows (`draft→approved→posted`, threshold-gated). **Finding:** Finance's own 381-line closure report contains zero mentions of notifications, alerts, or any hook for an external consumer — approval-required today is a **synchronous command rejection** (a thrown exception), not an observable event, so "approval required" notifications cannot be derived from the rejection itself; they would have to be derived from the antecedent submission event instead (e.g., a PO's `submitted` event, once contracted).

**Locked:** all Finance-sourced notification candidates (invoice overdue, approval required, budget threshold) are classified **SOURCE EVENT REQUIRED + BUSINESS POLICY REQUIRED** — Later, pending Finance's own decision to expose these as events or adopt the same direct-dispatch pattern other lanes used. Notifications does not build Finance's threshold/approval logic itself.

---

## 24. V1 Scope — "Vanilla-Plus"

**Locked:** V1 extends the existing, real, tested foundation. It does **not** build `Modules/Core/EnterpriseServices/NotificationPlatform` from the EPS-04 spec now. Concretely (implementation detail, Task 2, not authorized by this ADR to build):

- Extend the `notifications` table: add `company_id`, `priority`, `source_module`, `deep_link` (JSON: `entity_type`/`entity_id`/`action_key`), `dedupe_key`, `group_key`, `expires_at`, `dismissed_at`.
- Define one shared producer contract/base convention (e.g. a trait or abstract class) that Preparation's, Collaboration's, and CRM's `Notification` subclasses all conform to, so the `data` payload shape is consistent across every producer regardless of which module fired it. This is the concrete, appropriately-scoped value this task's reconciliation adds — not a parallel platform, but one consistent contract across three already-independently-converged producers.
- Extend `NotificationController`/`notification-center.tsx` for priority badges, the 8-value type taxonomy (§6), dedupe-aware creation, deep-link navigation (with destination re-auth per §8), and grouped tab counts matching the existing UX standard.
- Reuse `frontend/src/components/ds/toast-provider.tsx` for the Popup/Toast mechanism (§26.6) — do not build a second toast system.
- Migrate Preparation's 3 already-firing producers onto the shared contract as the pilot.

Full EPS-04 (Policy Engine integration, `NotificationDelivery`/`NotificationTemplate`/`UserNotificationPreference`, multi-channel adapters) remains the named target architecture, approached as an incremental extension of this foundation, not a parallel rebuild — mirroring Collaboration's own migration commitment.

---

## 25. DO-NOT-REIMPLEMENT

Notifications must not build a second:
- IAM user/permission/role/scope engine (reuse `AuthorizationGatewayInterface`, `DataScope`, `scopedTo()`) — §5, §19
- Recipient-holder enumeration (request the missing reverse lookup from IAM instead of building one locally) — §5
- Source-domain business/threshold rules (Inventory reorder points, Finance approval thresholds, CRM inactivity clock, Procurement approval thresholds) — §21–23
- CRM Activity/inactivity/ownership engine (`Crm/Engagement`, `PortfolioAssignment`) — §22
- Internal Collaboration messaging/mention/task engine (`Modules/Collaboration`) — §21
- CustomerEngagement conversational/inbox/SLA engine or a second WhatsApp Business API integration — §20
- Generic audit engine (`App\Core\Audit\AuditService` already exists and is the reused precedent per ADR-040; EPS-02 Timeline is separate and also not to be merged into) — §18
- Realtime/broadcast infrastructure (if/when Reverb is installed, it is an org-wide infra decision, not a Notifications-local build) — §16
- Document/media storage (EPS-03, if a notification ever needs to reference a file, link to it — do not create notification-specific blob storage)
- The `Modules/System/Engineering` notification system — confirmed CI/CD-pipeline-scoped only (its own `engineering_notifications` table, separate controller/UI); Reporting's own ADR-045 (Decision 8) independently confirms business notifications must use "the existing in-app notification feed... never" this system. Do not extend it for product/business use, and do not merge it into the vanilla foundation either — it stays a separate, narrower system by design.
- The Logistics "Alert Center" / Exception Registry (`ops_exceptions`) — this already provides exception ack/escalate/reconcile; Notifications deep-links into it (§13), never duplicates it.

---

## 26. CTO Addendum — Settings, Priority Defaults, Popup, Sound

### 26.1 Settings Surfaces (A)

**Locked:** two distinct, non-mixed surfaces.
- **Settings → Notifications & Alerts** — admin/company policy (mandatory floors, default channel/priority matrix, escalation timing, retention). Later (Task 4) — there is nothing to configure until `NotificationPolicy`-equivalent config exists.
- **My Profile → Notification Preferences** — personal preferences, within the bounds the admin surface allows. `NOTIFICATION-UX-STANDARD.md` §7 already mocks this exact screen (channel × type matrix, working hours/quiet hours toggle) — Later (Task 3), since `user_notification_preferences` does not exist yet.

### 26.2 Admin Notification Catalogue Settings (B)

**Locked (architecture only, Later for implementation):** every configurable item is addressed by a **typed Notification Key** (the catalogue in the companion Engineering Report, §51), never a free-form definition. Per key, the admin surface must support: Enabled/Disabled, Type (fixed, not editable — types are code-defined), Default Priority, In-App/Popup/Sound/Push/Email enabled, Reminder policy, Escalation policy, whether the user may disable/change-channel/mute-sound, retention/expiry. This is administration of pre-approved catalogue entries, never a no-code definition surface (§26.11 / task §49).

### 26.3 Priority Model (C)

Already locked at §7 — `LOW | NORMAL | HIGH | CRITICAL`, orthogonal to Type. No change from the addendum; confirms existing canonical UX standard.

### 26.4 Default Delivery Policy by Priority (D)

**Locked defaults** (admin-configurable, per §26.2):

| Priority | In-App | Popup | Sound | Mobile Push | Escalation |
|---|---|---|---|---|---|
| LOW | ✓ | — | — | — | — |
| NORMAL | ✓ | configurable | optional | — | — |
| HIGH | ✓ | default on | default on (where supported) | where enabled | policy-dependent |
| CRITICAL | ✓ | immediate | default on | where available | per policy |

In-App is the only channel that is never optional at any priority (§14).

### 26.5 User Preferences & Precedence (E)

**Locked precedence:** `MANDATORY SYSTEM POLICY > COMPANY DEFAULT > USER PREFERENCE`. A user may reduce channels/mute sound/set quiet hours within what policy allows; a user can never disable a notification type or channel the company policy marks mandatory (e.g., a Critical operational alert). Configurable surface: In-App (locked on), Popup, Sound, Mobile Push, Email, per-category mute, quiet hours, digest opt-in.

### 26.6 Popup / Toast (F)

**Locked distinction:** Persistent Notification (stored, appears in the Center, drives the unread badge) vs. Popup/Toast (transient, auto-dismissing, reuses `frontend/src/components/ds/toast-provider.tsx` — already real, working, generic UI infra; do not build a second toast system). A Popup disappearing (auto-dismiss or manual) never marks the underlying persistent notification read — those are two independent state changes. Popup content: title, concise message, type/priority, optional action/deep-link, accessible dismissal. Ordinary CRUD success feedback continues to use ephemeral toasts only and must never create a persistent Notification row (§66 / DO-NOT-NOTIFY, Engineering Report §52).

### 26.7 Realtime Arrival Sequence (G)

**Locked sequence**, transport-agnostic (works identically whether the "arrival" signal is the next 60-second poll tick today, or a future Reverb push):
1. Persist the notification row.
2. Update the unread badge/count.
3. Show Popup if delivery policy (§26.4) permits for this priority.
4. Play sound if policy/preference (§26.8) permits.
5. Notification is available in the Center (no separate step needed — it is already persisted).

A transport failure (or, today, simply waiting for the next poll) never loses the persistent record — steps 3–4 are best-effort attention signals layered on top of step 1, never a precondition for it.

### 26.8 Sound Architecture (H)

**Confirmed genuinely new** — no sound-related code exists anywhere in this codebase. **Locked, greenfield, Later (Task 3/4):** at most three sound profiles — `NORMAL | IMPORTANT | CRITICAL` — never a unique sound per notification type or per module. Two configurable layers: admin global sound policy (can disable sound org-wide or per-priority-floor) and, where policy allows, user sound preference (mute, or reduce which priorities play sound). No business module hardcodes its own sound.

### 26.9 Sound Platform Limitations (I)

**Locked:** sound is documented explicitly as a **best-effort attention signal**, never canonical. Browser autoplay policy may block audio until user interaction; mobile push sound depends on OS/device/channel permission state outside ECOS's control. The persistent in-app Notification record (§9) remains the sole canonical user-attention record regardless of whether sound (or Popup) actually rendered. No correctness path may assume sound played.

### 26.10 Sound + Quiet Hours (J)

**Locked:** when Quiet Hours are enabled — LOW/NORMAL: sound suppressed by default. HIGH: policy-configurable (company decides). CRITICAL: may bypass quiet hours **only** if company policy explicitly allows it (opt-in, never default-on bypass). This mirrors the addendum's instruction exactly and reuses the working-hours/quiet-hours toggle already mocked in `NOTIFICATION-UX-STANDARD.md` §7.

### 26.11 Admin Matrix Presentation (K)

**Locked, Later:** the admin surface presents one matrix — Notification (key) × Module × Type × Priority × Enabled × Popup × Sound × Push × Email × User-Configurable × Escalation — scoped strictly to administering the approved Notification Catalogue (Engineering Report §51). This is explicitly **not** a generic no-code rule builder (§26.2, task §49) — new notification keys are added by engineering (a code change, reviewed like any other), never authored freely by an admin.

---

## 27. Consequences

**Positive:** formalizes a decision three independent lanes had already made without coordinating, closing the risk of a fourth lane inventing a fourth variant. Draws four boundary lines (CustomerEngagement, Collaboration, CRM, Finance) that no prior document drew, using code-verified evidence rather than assumption. Keeps V1 scoped to extending real, tested, low-risk infrastructure rather than authorizing a from-scratch EPS-04 build with no reachable canonical baseline to build it against safely.

**Negative / accepted tradeoffs:** V1 explicitly defers realtime, push, email, WhatsApp wiring, templates/localization, delivery-state tracking, preferences, and admin settings — all real, approved-architecture capabilities that remain unbuilt for longer. Notification bodies rendered at creation time will not re-localize retroactively. The CRM and CustomerEngagement boundary recommendations in §20/§22 are not binding on those lanes' own architecture decisions — they are flagged for CTO review, not enforced by this ADR.

**Risk carried forward:** this ADR's own number (047) and every cross-lane boundary decision in §20–23 is only as current as the 5 sibling checkouts inspected on 2026-09-03; canonical `develop` remains unreachable from this device family (§0). Task 2 must re-verify before merge if canonical `develop` becomes reachable in the interim.

---

## 28. Tasks 2–5

| Task | Scope |
|---|---|
| **Task 2** | Notification Core Foundation: schema extension (§24), shared producer contract, `NotificationController`/bell UI extension, tenant/security hardening, dedupe + grouping, migrate Preparation's 3 producers onto the shared contract |
| **Task 3** | Recipient resolution hardening (request IAM reverse-lookup contract), reminder scheduling foundation, escalation foundation (deep-link to Exception Registry), `UserNotificationPreference` + Profile preferences screen, `NotificationTemplate` skeleton using `users.locale` |
| **Task 4** | Channel delivery infrastructure (Email via existing SMTP config; WhatsApp via reuse of CustomerEngagement's provider, §20; Mobile Push foundation — device-token model + provider decision); Admin Settings screen + sound architecture + quiet hours + digest; high-value module integrations per the Notification Catalogue's V1-adjacent Later entries as their source contracts land (Orders on-hold, Inventory thresholds, Procurement PO approval, Finance invoice overdue, CRM follow-up/reassignment pending ADR-046 commit) |
| **Task 5** | Notifications Source Closure: formal Query/Event Contract registrations (`CON-GOV-010`) for any modules that moved to event-sourced production, realtime upgrade if/when Reverb becomes org-wide available, full integration readiness verification package |

No task count increase from the task's own suggested 2–5 grouping — evidence supported the same shape.

---

## 29. Task 2 Release Recommendation

**APPROVE WITH CANONICAL RECONCILIATION PRECONDITION.**

Task 2 may begin on the current local baseline: its scope (§24) only extends already-real, already-tested, already-precedented infrastructure — it is not a blind from-scratch EPS-04 build that would be unsafe to start without a reachable canonical baseline. Precondition, to be checked before Task 2's schema/migration changes are considered final: (a) re-verify ADR-047 remains collision-free once `E:/ECOS/ecos-develop` becomes reachable; (b) confirm whether `ecos-crm`'s ADR-046 has been committed, and at what number, before finalizing the CRM integration section of the Notification Catalogue; (c) confirm `Modules/Platform/EventPlatform`'s actual implementation depth before assuming any event-sourced producer path is available for Task 3.

---

*Reconciliation basis: this repo at commit `16b0ec85df5774f03ccd6dca042528260d66c216`, plus read-only inspection of `ecos-chat` (@3f183df0), `ecos-crm` (@2e4a1fca), `ecos-finance` (@42788a10), `ecos-iam` (@2686b858), `ecos-reporting` (@b8a950fb), all 2026-09-03. See companion Engineering Report for full evidence trail.*
