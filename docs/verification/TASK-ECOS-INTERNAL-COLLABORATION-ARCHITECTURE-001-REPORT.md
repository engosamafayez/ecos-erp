# TASK-ECOS-INTERNAL-COLLABORATION-ARCHITECTURE-001 — Architecture Report

**Workstream:** ECOS ERP — Internal Collaboration & Tasks
**Batch:** Internal Collaboration Batch 01 — Task 1 of 5
**Type:** Architecture & Design (documentation only — no code, no migrations, no implementation)
**Owner:** A1 — Internal Collaboration
**Date:** 2026-09-02
**Revision:** v2 — 2026-09-02, updated after CTO Ratification (ADR-044: Accepted). See §24 for the full decision log.
**Workspace / Branch:** `D:\ECOS-Work\ecos-chat` / `task/chat-workstream` @ `16b0ec85`

---

## 1. Final Status

**COMPLETE** (architecture ratification). See §27 for the separate, unresolved git-commit blocker on this continuation task, which does not reopen the architecture itself.

Every mandatory architecture area (§2–§21 below) was studied against current source, not assumed. All 8 items originally flagged as requiring a CTO decision have been ratified by the CTO (§24) — none remain open. No backend module, migration, frontend code, or seed data was created. See §26–§27.

**Terminology note (applies throughout this document):** the ad-hoc group-chat entity Collaboration owns is always called a **Collaboration Group**. **Organizational Team** refers exclusively to `Organization\Teams\Team`. The two are never interchangeable — see §7.

---

## 2. Existing Capability Gate

Six areas were inspected directly against current source. Classification key: **PRESERVE** (leave exactly as-is, no changes needed or wanted), **REUSE** (depend on it as-is), **EXTEND** (usable but needs a genuine addition), **NEW** (Collaboration must build this itself), **DO-NOT-REIMPLEMENT** (a working mechanism exists elsewhere; building a parallel one would be a regression), **LEGACY/N-A** (exists but not relevant to this module).

| Area | Capability | Finding | Classification |
|---|---|---|---|
| IAM | `App\Models\User` identity | Canonical, bigint PK, `UserStatus` enum, `SoftDeletes`, `company_id` scoping (`app/Models/User.php:22-60`) | PRESERVE / REUSE |
| IAM | `AuthorizationGatewayInterface` + `PermissionRegistryInterface` | Real contracts for `can()`/`authorize()` and module permission registration (`Modules/IAM/Domain/Contracts/AuthorizationGatewayInterface.php:25-48`, `PermissionRegistryInterface.php:31-79`) | REUSE |
| IAM | `permission:<resource>.<action>` middleware | The reliably-enforced authorization pattern in this codebase (`Modules/IAM/Infrastructure/Middleware/RequirePermissionMiddleware.php:44-63`; real example `routes/api.php:384-387`) | REUSE |
| IAM | `ScopeResolverInterface` (Data Scope Engine) | `resolve(User $user, string $resource, ?string $ownerColumn=null): ScopeConstraint` — turns a user+resource into a declarative constraint applied via a `scopedTo()` query macro (`Modules/IAM/Domain/Contracts/ScopeResolverInterface.php:16-25`, ADR-038 Part 3) | REUSE (load-bearing for §14 after ratification) |
| IAM | `VisibilityResolverInterface` | Field-level visibility (`fieldState()`/`hiddenFields()`), independent of authorization (`Modules/IAM/Domain/Contracts/VisibilityResolverInterface.php:17-30`, ADR-038 Part 2) | LEGACY / N-A for V1 (record-level scope, not field-level visibility, is what driver-messaging needs) |
| IAM | Gate `Policy` classes | Exist and work when wired (`LoadingSessionPolicy` is the clean example), but inconsistently applied elsewhere (Teams' `TeamPolicy` calls a method, `hasPermissionTo()`, that doesn't exist anywhere in the codebase) | EXTEND (use the pattern, verify wiring — don't assume every existing example is trustworthy) |
| IAM | Presence / online-status | Does not exist for human users; `last_activity_at` is written once at login and never updated again (`Modules/IAM/Application/Services/UserSessionService.php:30`) | NEW (only if a future task adds presence — not required for V1; typing/presence remain deferred, §23) |
| Logistics/Drivers | `Driver.user_id` bridge | Nullable, unique, bigint FK to `users`, `nullOnDelete` (`Modules/Logistics/Drivers/Infrastructure/Database/Migrations/2026_08_20_100100_add_user_id_to_logistics_drivers.php:28-34`) | REUSE (this is the identity-bridge pattern Collaboration is built on) |
| Logistics/Drivers | Driver auth | Same Sanctum guard/login as employees, no separate driver auth stack (`Modules/IAM/Presentation/Http/Controllers/AuthController.php:26-34`) | PRESERVE |
| Logistics/Drivers | Driver messaging/inbox | Does not exist anywhere, front or back end | NEW |
| Logistics/Distribution | "Distribution Group" concept | Real and actively used: `DistributionGroupTemplate`, `DistributionGroupTemplateDriver`, `DistributionGroupTemplateZone`, `GroupProductPreparation` models plus `DailyGroupLifecycleService`, `GroupCapacityGuard`, `GroupFinalizationService`, `GroupLoadingContextService`, `GroupPreparationService`, `GroupTemplateService`, `GroupVehicleAssignmentService` (`backend/Modules/Logistics/Distribution/...`) | PRESERVE / reference only (§13) — Collaboration never owns or mutates it |
| Organization/Teams | `Team` model | Real, but zero membership relation, `leader_name` is a free-text string not an FK (`Modules/Organization/Teams/Domain/Models/Team.php:23-58`) | LEGACY / do not extend (see §7) |
| Organization/Teams | ADR-011 ownership claim | "No other module may redefine ... Team" (`docs/adr/ADR-011-V2.2-organization-os.md:15`) | PRESERVE (hard constraint — do not violate) |
| Hr/Workforce | `Department`, `Employee`, `ReportingLine` | Fully built (`Modules/Hr/Workforce/Domain/Models/*`), but a second, overlapping "employee" concept vs. `IAM\User` | LEGACY / N-A to this task (pre-existing drift, not ours to fix) |
| CustomerEngagement | `Conversation`/`Message` domain shape | Real, usable as shape inspiration only (`Modules/CustomerEngagement/Domain/Models/Conversation.php`, `Message.php`) | LEGACY / do not depend on |
| CustomerEngagement | Attachment/realtime/notification/search plumbing | None of it exists or is trustworthy (flat unvalidated columns, zero broadcasting, zero notifications, dead `customer_id`) | DO-NOT-REIMPLEMENT this way — build Collaboration's equivalents properly (see §15–§18) |
| Platform/Core | `App\Core\Documents\DocumentService` + `Document` | Genuinely reused today by Purchasing and Operations/Preparation; private disk, auth-gated streaming, no signed URLs (`app/Core/Documents/DocumentService.php`) | EXTEND |
| Platform/Core | `App\Core\Audit\AuditService` | Exists, well-formed, but dead code — imported nowhere in the entire repo | DO-NOT-REIMPLEMENT this exact mistake — either use it or don't reference it; do not add a 9th parallel audit stack pretending it's canonical |
| Platform | Enterprise Notification Platform | `docs/architecture/ENTERPRISE-NOTIFICATION-PLATFORM.md` is "Architecture Only," zero code | NEW (V1 uses stock Laravel notifications instead, see §16) |
| Platform | Laravel Reverb / broadcasting | Not installed, not configured anywhere (no `config/broadcasting.php`, no `routes/channels.php`, no `laravel/reverb` in composer) | NEW — **now a ratified V1 target, not deferred** (see §15) |
| Platform | Scout / Meilisearch | Not installed anywhere | NEW (see §18) |
| Frontend | `AppShell`, `DriverShell`, `module-navigation.ts` | Real, working, well-precedented patterns | REUSE |
| Frontend | Voice recording (`MediaRecorder`) | Does not exist anywhere in the frontend | NEW |
| Frontend | Chat-shaped list+thread UI | Implemented twice ad hoc in CEP's own inbox pages, never factored into a shared component | EXTEND (build a third, but factor it this time — see §20) |

---

## 3. Proposed Bounded-Context / Module Name

**Recommendation: `Collaboration`** (`Modules\Collaboration`), not `InternalCollaboration`.

**Rationale:** every comparable peer module in `backend/Modules/` uses a short, single-concept name (Finance, Hr, Crm, Sales, Marketing, Commerce) rather than a compound qualifier. `CustomerEngagement` already unambiguously owns the *external* case, so "Internal" is disambiguating against nothing inside this codebase's own vocabulary — it only makes every future `Modules\InternalCollaboration\...` path longer for no reader benefit.

> **CTO Ratification (2026-09-02): APPROVED as recommended.** `Modules\Collaboration` is the canonical implementation module name. The product/domain capability name remains "Internal Collaboration & Tasks."

---

## 4. Domain Ownership

`Modules\Collaboration` owns:
- Internal conversations and their membership (`conversations`, `conversation_participants`)
- Internal messages, replies, mentions, read cursors (`messages`, `message_mentions`, read-state columns on `conversation_participants`)
- Voice-message metadata (`collaboration_voice_metadata`, keyed to a `Document` row)
- The internal task domain (`internal_tasks`, comments, activity/history), **including tasks assigned to drivers** (ratified, §21)
- The message→task linkage (columns on `internal_tasks`, not a separate owning entity — see §12)
- Collaboration-specific activity/history for its own entities

`Modules\Collaboration` explicitly does **not** own: employee identity (IAM), driver identity (Logistics/Drivers), customer engagement (CustomerEngagement), the operational source entities it links to (Order, Distribution Group, Trip, Driver), canonical file storage (`App\Core\Documents\DocumentService`, extended not owned), canonical notifications (none exists to own; V1 uses stock Laravel `Notification` classes, the same way five other modules already do independently), canonical realtime infrastructure (none exists yet; Collaboration is now the first module standing up Reverb usage, see §15, but the underlying broadcasting infrastructure itself remains a platform concern if other modules adopt it later), or driver-facing presentation (DriverShell remains Shipping's, §21).

---

## 5. CEP Boundary

Enforced structurally, not by convention: `Modules\Collaboration` gets its own tables (`conversations`, `messages`, ...), entirely distinct from CEP's `cep_conversations`/`cep_messages`. No shared domain tables, no cross-module Eloquent relations between the two. This was verified to be achievable cleanly — CEP's own conversation/message code has zero outside dependents today except one raw, explicitly-commented-as-a-workaround `DB::table('cep_conversations')` read from `Modules\Crm\Engagement` (`Modules/Crm/Engagement/Infrastructure/Timeline/ConversationTimelineSource.php:18,36-38`) — not a pattern Collaboration should imitate for its own tables. Both modules may independently depend on the same shared platform primitives (`DocumentService`, Sanctum/IAM permissions) without either becoming the other's authority. If a future feature genuinely needs to bridge the two (e.g., "escalate a customer conversation to an internal task"), that bridge belongs in a third, explicit integration point — not in either module reaching into the other's tables.

> **CTO Ratification (2026-09-02): APPROVED as recommended.** CEP and Collaboration may share platform infrastructure; they must never share domain message/conversation tables.

---

## 6. Employee/Driver Participant Model

**RECOMMENDATION:** A single canonical actor type. Every participant, sender, and assignee in Collaboration is a `user_id` (bigint, `constrained('users')`) — full stop. No polymorphic "actor" reference, no separate driver-participant type.

**RATIONALE:** Sanctum authentication is unified across employees and drivers (§2; `Modules/IAM/Presentation/Http/Controllers/AuthController.php:26-34` — one login endpoint, one token type, no `DriverAuth*` controller exists anywhere). Anyone who can call the API already has a `users.id`. `Driver.user_id` (nullable, unique) is the existing, correct bridge — a driver *is* reachable as a `User` exactly when that column is set. Building a second actor-type concept on top of this would duplicate identity modeling that §1.7/§1.3 of the task brief explicitly forbids.

**Consequence stated as an explicit invariant, not a gap:** a `Driver` row with `user_id = null` (today's common case for master-data-only drivers with no app login) cannot participate in Collaboration. This is correct, not a bug — there is no identity to message. Logistics owns closing that gap by linking a `User` when a driver needs app access; Collaboration does not work around it.

**Alternatives considered and rejected:**
- *Polymorphic actor reference* (`actor_type` + `actor_id` covering `User`, `Driver`, future bot/system accounts): rejected for V1 as unnecessary complexity — nothing today needs a non-`User` participant, and CEP's own attempt at a loosely-typed actor reference (`sender_type` string + unenforced `sender_id`) is exactly the kind of soft-typed mess this report recommends *not* repeating (§2, CEP row).
- *Duplicate the driver's name/phone directly onto participant rows* (as `driver_assignments.driver_name_snapshot` does elsewhere in this codebase): adopted partially — a cosmetic `display_name_snapshot` for fast list rendering — but only as a rendering optimization, never as the identity-bearing field.

**Deleted/deactivated users:** `User` uses `SoftDeletes` and a `UserStatus` enum (`draft/invited/.../inactive/suspended/locked/archived/deleted`) — hard deletes are not the normal lifecycle. Recommend `conversation_participants.user_id` and `messages.sender_user_id` use `restrictOnDelete()` (not `nullOnDelete()`), so a hard delete is blocked rather than silently orphaning history, and the UI renders a "(deactivated)" label whenever `user.status` is not `active` rather than the FK ever going null.

> **CTO Ratification (2026-09-02): APPROVED as recommended.** No duplicate identity stores of any kind (`chat_users`, `collaboration_users`, `driver_chat_users`, or a second employee table) — driver participation resolves exclusively through the existing `Driver.user_id` → `User` linkage.

---

## 7. Team/Group Authority Recommendation

**RECOMMENDATION:** Option B — collaboration-only ad-hoc groups. Do **not** reuse `Organization\Teams\Team` as the group-membership mechanism.

**RATIONALE:** `docs/adr/ADR-011-V2.2-organization-os.md:15` forbids any module from redefining "Team." The actual `Team` model (`Modules/Organization/Teams/Domain/Models/Team.php`) has no membership relation whatsoever today — no pivot table, no `members()` method — and its "leader" is a plain string, not a real relationship. Building group-chat membership *onto* `Team` would mean either (a) violating ADR-011 by adding domain logic Organization OS is supposed to own exclusively, or (b) waiting on an unrelated Organization/Teams initiative to build real membership first. Neither is acceptable for this task's scope.

Instead: a **Collaboration Group** (`Conversation` of `type = group`) is self-sufficient — its `conversation_participants` rows *are* its membership, added ad hoc by whoever creates the group, exactly like every mainstream chat product's "new group" flow. A Collaboration Group (and `InternalTask`, §11) may carry an optional, read-only `team_id` pointing at `Organization\Teams\Team`, purely as a label/filter for reporting — the same soft-reference shape `Crm\Service\Ticket.team_id` and CEP's `cep_conversations.assigned_team_id` already use elsewhere in this codebase (`Modules/Crm/Service/Domain/Models/Ticket.php:32`; CEP migration `2026_07_09_800000_create_cep_conversations_table.php:42`) — never as the source of membership.

**Alternatives considered:**
- *Option A — fully reuse canonical Team*: rejected now (see rationale); becomes viable later if Organization/Teams grows real membership.
- *Option C — hybrid (canonical teams get a synced "team conversation," ad-hoc groups also allowed)*: the better long-term answer, but it depends on Option A's precondition (real Team membership) existing first. Recommend revisiting post-V1.

> **CTO Ratification (2026-09-02): APPROVED as recommended (Option B), plus a mandatory naming rule.** The ad-hoc entity must always be called a **Collaboration Group** — in schema, code, documentation, and UI — and must never be presented as, or confused with, an **Organizational Team** (`Organization\Teams\Team`). A Collaboration Group is not organizational master data. This report and ADR-044 use "Collaboration Group" consistently from this point forward; any future Task 2–5 documentation, migration names, and UI copy must do the same.

---

## 8. Conversation Model

| Concern | V1 Decision |
|---|---|
| Conversation identity | `conversations.id` (uuid, matches CEP/Organization convention of uuid PKs for domain aggregates) |
| Conversation type | Enum: `direct`, `group` (a `group`-type conversation is a **Collaboration Group**, §7) |
| Membership | `conversation_participants` (conversation_id, user_id, role, joined_at, left_at nullable) |
| Membership lifecycle | Soft leave via `left_at`; re-adding a user re-activates (new row not required) |
| Creator | `conversations.created_by_user_id` |
| Authorization | Participation-gated: a user may read/write a conversation iff they hold an active (`left_at IS NULL`) `conversation_participants` row for it — this is a data-ownership check, not a permission-registry check (mirrors `LoadingSessionPolicy`'s `$user->company_id === $session->company_id` shape: `Modules/Operations/Loading/Policies/LoadingSessionPolicy.php:15-46`). Starting a **direct conversation with a driver** additionally requires the scope-gated flow in §14. |
| Message ordering | `messages.created_at` + `id` (uuid v7/ULID-shaped for natural chronological sort, avoiding a fragile pure-timestamp tie-break) |
| Pagination | Cursor-based (keyset on `created_at, id`), not offset — matches typical chat-scroll UX and avoids large-offset performance cliffs |
| Unread/read strategy | **ReadCursor, not per-message MessageRead rows.** `conversation_participants.last_read_message_id` + `last_read_at`. Rejected the "MessageRead row per message per user" shape explicitly listed in the brief's illustrative entity list — it produces N participants × M messages rows for no V1 benefit; a cursor gives correct unread counts (`messages after last_read_message_id`) at a fraction of the storage/write cost. With near-realtime delivery now ratified (§15), the cursor also becomes the natural payload for a "read" broadcast event. |
| History/audit | Messages are immutable once sent (see §9) — the message table itself is the history; no separate audit table needed for conversations |
| Owner/admin model | Collaboration Group creator is implicit admin (can add/remove participants); no separate "co-admin" role in V1 |
| Mute / Archive | **Deferred** — not required by the brief, adds a per-participant preference dimension with no V1 consumer |
| Retention duration | **Deferred** — no retention/deletion policy in V1; messages persist indefinitely, consistent with how every other domain table in this codebase behaves (soft-delete at most) |
| Direct-message uniqueness | Enforced at the application layer: at most one active `direct` conversation per unordered `{user_a, user_b}` pair — reuse it rather than spawning duplicates, matching universal chat-app behavior |
| Message editing/deletion | **Deferred**, see §9 |

No change from the original proposal — not among the 8 ratified items, approved implicitly as part of the overall architecture acceptance.

---

## 9. Message Model

| Concern | V1 Decision |
|---|---|
| Immutable fields | `conversation_id`, `sender_user_id`, `type`, `body`/attachment reference, `reply_to_message_id`, `created_at` — never change after send |
| Mutable fields | None in V1 (see editing/deletion below) |
| Sender authority | Must hold an active `conversation_participants` row for the target conversation at send time |
| Types | `text`, `image`, `file`, `voice`, `system` (system = e.g. "X added Y to the group," rendered inline, not sent by a user) |
| Reply linkage | `reply_to_message_id` (nullable self-FK) |
| Mentions | `message_mentions` (message_id, mentioned_user_id) — separate table, not a body-text parse-on-read, so notification fan-out and "my mentions" queries are simple indexed lookups |
| Attachment linkage | Not a Collaboration-owned column — see §17, resolved via `App\Core\Documents\Document` keyed by `subject_type='collaboration_message'`, `subject_id=message.id` |
| Read state | Lives on `conversation_participants` (§8), not on `Message` itself |
| Search | See §18 |

**Message editing/deletion — studied, recommended deferral.** The brief flags this as not pre-approved. Recommendation: **defer both** for V1. Rationale: neither is required by any approved capability, both introduce real complexity (edit history, "deleted for me" vs "deleted for everyone," notification/re-render implications), and CEP — the one existing local precedent for a message-shaped table — implements neither either. If added later, recommend soft-delete (`deleted_at` + body redaction) over hard delete, to preserve `reply_to_message_id` integrity for messages that reply to something later removed.

> **CTO Ratification (2026-09-02): APPROVED as recommended.** Message editing and deletion are confirmed deferred from V1. Tasks 2–5 must not be designed around them.

---

## 10. Voice-Message Architecture

Lifecycle: Record → Stop → Preview (client-side, no server round-trip needed for preview) → Upload → Send → Secure Store → Playback.

| Concern | Recommendation |
|---|---|
| Canonical storage authority | `App\Core\Documents\DocumentService` (§2, §17) — reused, not rebuilt |
| Upload architecture | Client records to a blob (webm/opus via `MediaRecorder`, see §20), uploads via a Collaboration endpoint that calls `DocumentService::store()` with `subject_type='collaboration_message'` once the message row exists (or a short-lived "pending" message row created at upload start, finalized on send — implementation detail for Task 3, not decided here) |
| Secure access/playback | Collaboration-owned streaming controller action checks the requester holds an active `conversation_participants` row for the message's conversation, then streams via `Storage::disk('local')` exactly like `SupplierInvoiceDocumentController::download()` (`Modules/Purchasing/SupplierInvoices/Presentation/Http/Controllers/SupplierInvoiceDocumentController.php:76-85,102-110`) — **never** a public-disk URL. This directly satisfies the brief's "guessing a media URL must not itself grant access" requirement, because the private `local` disk plus an authorization-checked streaming action is already how this exact requirement is met elsewhere in this codebase. |
| Media metadata | New table `collaboration_voice_metadata` (one row per voice `Document`: `document_id`, `duration_seconds`, `format`) — a small module-owned side table rather than widening the shared `documents` table for one consumer |
| Duration storage | `duration_seconds` (int), computed client-side at record-stop and re-validated server-side if cheap to do (exact validation approach is an implementation detail for Task 3) |
| Format strategy | Recommend a single fixed format for V1 (e.g. `audio/webm;codecs=opus` — broadly supported, small file size) rather than accepting arbitrary formats; format is stored for correctness/debugging, not to support a matrix of playback transcoding |
| Failed/abandoned upload cleanup | A scheduled cleanup job removing orphaned `Document` rows (upload started, message never sent) older than e.g. 24h — standard pattern, not novel to this module |
| Authorization checks | Same participation check as playback, applied on upload too (must be an active participant of the target conversation) |
| Mobile/browser recording compatibility | `MediaRecorder` support is broad in modern mobile/desktop browsers; exact codec fallback matrix is a Task 3/frontend implementation detail |
| Transcoding | **Not required for V1** — fixed single format avoids the need |
| Configuration for limits (max duration, max file size, allowed formats) | Belongs in Collaboration's own module config (`config/collaboration.php`, created in Task 2/3), not hardcoded in application code and not implemented in this task |
| Playback speed (1x/1.5x/2x) | Explicitly non-blocking per the brief; a client-only feature (native `<audio>` `playbackRate`), no backend implication — safe to include in Task 3/5 if cheap |

Not among the 8 ratified decision points — approved as part of the overall architecture acceptance; preserved unchanged (§2 of the CTO continuation brief).

---

## 11. Internal Task Architecture

| Concern | V1 Decision |
|---|---|
| Assignment: user vs. Collaboration Group | Single assignee (`assignee_user_id`, nullable) for actionable ownership; optional `team_id` (soft reference to `Organization\Teams\Team`, §7) as a label/filter only — not a fan-out mechanism, since `Team` has no members to fan out to today. **May now also be a driver**, resolved through `assignee_user_id` exactly like an employee assignee (§6) — no separate driver-assignment column, ratified §21. |
| Single vs. multiple assignees | **Single**, for V1 — matches the brief's "lightweight, not Jira" framing. Multiple-assignee is a clean additive change later (a join table) if ever needed. |
| Task ownership | Creator (`creator_user_id`) is permanent and immutable; assignee can change over time (see activity log) |
| Status-transition authority | Creator or current assignee (employee **or driver**) may transition status, subject to which states a driver-facing surface exposes (§21) |
| Lifecycle | `TODO → IN_PROGRESS → DONE`, plus `CANCELLED` from any non-`DONE` state |
| Due-date notification | A due-date-approaching and a due-date-passed notification, via the same stock-Laravel notification mechanism as new messages (§16) — scheduled check, not realtime |
| Completion/reopen | `DONE → IN_PROGRESS` (reopen) allowed; `DONE → TODO` not modeled separately (reopen always re-enters `IN_PROGRESS`) |
| Cancellation | Allowed from `TODO`/`IN_PROGRESS`; recommend requiring a short reason captured in the activity log, not a dedicated column |
| Comments | `internal_task_comments` (task_id, author_user_id, body, created_at) — plain, immutable once posted (same editing/deletion deferral as messages, §9, for consistency). A driver assignee may comment where authorized (§21). |
| Attachments | Reuse `Document` (`subject_type='collaboration_task'`), same as messages — no bespoke `InternalTaskAttachment` table |
| Activity/history | `internal_task_activity` (task_id, actor_user_id, event_type, from_value, to_value, created_at) — module-owned, consistent with how every other module in this codebase already logs its own activity independently (§2) |

---

## 12. Message → Create Task Contract

**Design:** no separate `TaskSourceMessageLink` entity. Instead, two nullable columns directly on `internal_tasks`: `source_conversation_id` (FK, nullable) and `source_message_id` (FK, nullable), plus an immutable `source_message_snapshot` (text, captured verbatim at task-creation time).

**Rationale:** the relationship is 1:1 from the task's side (a task has at most one origin message) and never queried from the message's side in reverse in any required capability — a join table would be pure overhead. The `source_message_snapshot` directly answers the brief's own question ("whether a small immutable source-message snapshot is useful" — yes) and its follow-up ("historical behavior if source message becomes unavailable" — the snapshot survives regardless of what later happens to the live message, since messages are never deleted in V1 anyway per §9, but the snapshot is cheap insurance against future edit/delete features).

**Permission checks:** creating a task from a message requires (a) the creator to be an active participant of the source conversation, and (b) the general `collaboration.tasks.create` permission (§14) — the same as creating any task, no special elevated permission for the message-originated path.

**Navigation back to source:** the task detail view shows the snapshot plus a "view in conversation" deep link using `source_conversation_id`/`source_message_id` — if the user still has access (still a participant), it jumps straight to that message; if not (e.g. removed from the conversation since), the snapshot alone is still shown, no error state.

**Assignee selection default:** defaults to the task creator (self-assign), matching the common "I'll turn this into a task for myself" flow; the creator can immediately reassign before saving — including to a driver, per §21.

**Task↔conversation/comment relationship:** a task's comments (`internal_task_comments`) are a distinct thread from the source conversation — deliberately not merged, per the brief's explicit instruction not to duplicate the full conversation as a second source of truth. The task carries a pointer back, not a copy.

Not among the 8 ratified decision points — approved as part of the overall architecture acceptance; preserved unchanged.

---

## 13. Operational Context Strategy

**V1 context types (ratified): Order, Distribution Group, Trip, Driver.**

> **CTO Ratification (2026-09-02): AMENDED.** The original recommendation (Order, Trip, Driver) is expanded to explicitly include **Distribution Group**. Verified as a real, actively-developed concept in `Modules\Logistics\Distribution` — `DistributionGroupTemplate`, `DistributionGroupTemplateDriver`, `DistributionGroupTemplateZone`, `GroupProductPreparation` models, and `DailyGroupLifecycleService`, `GroupCapacityGuard`, `GroupFinalizationService`, `GroupLoadingContextService`, `GroupPreparationService`, `GroupTemplateService`, `GroupVehicleAssignmentService` services (`backend/Modules/Logistics/Distribution/...`) — the same domain area the repository's own recent commit history (`fix(distribution): converge loading stack onto canonical workspace`, `feat(distribution): expose canonical loading workspace`) shows is under active development. Collaboration references this concept read-only, exactly as it references Order/Trip/Driver — it does not gain any special mechanism of its own. Customer, Supplier, Purchase Order, Inventory issue, and Finance reference remain deferred, as originally recommended.

**Representation:** a generic `operational_context_links` table using a string type discriminator + id (`context_type`, `context_id`), attached to one of `conversation`, `message`, or `task` via a second type+id pair (`attached_to_type`, `attached_to_id`). This deliberately follows the *existing* local convention (`App\Core\Documents\Document`'s `subject_type`/`subject_id` — a string discriminator, not a true Eloquent `morphTo`) rather than introducing the first genuine polymorphic relation in this codebase (verified zero `morphTo` usage anywhere in `backend/Modules` or `backend/app`) — consistency with how this codebase already does "generic reference to anything" beats introducing a new pattern for one module. **The `context_type` value set (now Order/Distribution Group/Trip/Driver) is config-driven, not hardcoded into the schema or query logic** — adding a fifth type later (e.g. Customer) is a config/registration change, not a structural one, per the brief's explicit "must remain extensible" requirement.

**Authorization before exposing linked context:** the link only stores which operational entity is referenced; rendering its details still goes through that entity's own authorization (e.g., showing an Order's or Distribution Group's details still requires that entity's own view permission) — Collaboration never becomes a side-channel to view data the user couldn't otherwise see.

**Lifecycle when referenced entity becomes unavailable:** the link row persists (it's just a reference); the UI renders "Order #1234 (no longer available)" rather than erroring, mirroring how the task's `source_message_snapshot` degrades gracefully (§12).

**What can carry context:** all three (conversation, message, task) may carry a link — a conversation-level link covers "this whole thread is about Trip #99," while a message- or task-level link covers "this specific message/task is about Order #456 / Distribution Group #12" within an otherwise general conversation.

---

## 14. Permission Recommendations

Registered via IAM's `PermissionRegistryInterface::register('collaboration', [...])` (§2), enforced via the `permission:collaboration.<resource>.<action>` middleware — the reliably-enforced pattern identified in §2, not the inconsistently-wired Gate-policy pattern.

| Concern | Requirement (approved) vs. Policy (recommended/ratified) |
|---|---|
| Read/send within a conversation | **Not a registered permission at all** — gated purely by active `conversation_participants` membership (a data check, like `LoadingSessionPolicy`'s company scoping), consistent with how chat apps universally work. |
| Start employee↔employee conversation | `collaboration.conversations.create` — broad, any authenticated employee |
| Start employee↔driver conversation | **Ratified — amended from the original recommendation.** A permission alone is *not* sufficient. See the conceptual flow below. |
| Group creation | `collaboration.groups.create` (creates a **Collaboration Group**, §7) |
| Member management (add/remove) | Collaboration Group creator by default (§8); no separate permission needed beyond conversation-admin status |
| Task create / assign / status-change | `collaboration.tasks.create`; assign/status-change gated by creator-or-assignee (§11), not a separate permission — applies identically whether the assignee is an employee or a driver |
| View team tasks | **Deferred** — depends on `Organization\Teams\Team` having real membership (§7); until then, "my tasks" (created-by-me or assigned-to-me) is the only task list, which needs no team-aware permission at all |
| Upload/download attachments, voice access | Gated by conversation/task participation (via `DocumentService` streaming check, §10/§17) — not a separate registered permission |
| Mentions | No permission — any participant can mention any other current participant |

### Employee → Driver messaging: ratified conceptual authorization flow

> **CTO Ratification (2026-09-02): AMENDED.** The original recommendation — a single broad `collaboration.conversations.message_drivers` permission, full stop — is **not sufficient**. The ratified rule requires **both** a permission gate **and** an authorized operational/data scope over the specific target driver, using IAM's existing Data Scope Engine rather than a Collaboration-specific rule.

Conceptual flow (documented conceptually, per the CTO brief — not implemented in this task):

```
Authenticate (Sanctum, existing)
    → IAM permission check: collaboration.conversations.message_drivers
        (via the existing `permission:` middleware / AuthorizationGatewayInterface)
    → Resolve target driver (Driver.user_id → the addressed User)
    → Canonical Data Scope check:
        ScopeResolverInterface::resolve($actingUser, '<drivers-resource>')
        → ScopeConstraint, applied the same way every other module already
          applies it (`scopedTo()` query macro) — ALL scope for a privileged
          role naturally permits any driver; a narrower resolved scope
          naturally excludes drivers outside it
    → Conversation authorization (active `conversation_participants` row,
      §8) for every subsequent message in that conversation
```

This reuses `Modules/IAM/Domain/Contracts/ScopeResolverInterface.php:16-25` (the "Data Scope Engine," ADR-038 Part 3) exactly as designed — "business modules never filter data by hand; they apply the constraint via the `scopedTo()` query macro." A user whose resolved scope is organization-wide is unaffected in practice; the gate only narrows access for roles whose canonical scope is narrower, which is precisely the CTO's stated intent ("if a privileged role legitimately has organization-wide driver scope, canonical authorization/data scope may allow it").

**Open verification for Task 3, stated honestly rather than assumed:** this report did not find confirmed evidence that a driver-specific resource string (e.g. `logistics.drivers`) is already registered with `ScopeResolverInterface` anywhere in the codebase — the interface and its pattern are real and proven (ADR-038), but whether a driver-scope rule already exists for Collaboration to simply call is not yet confirmed. If it does not exist yet, registering it is IAM's/Logistics's concern via the existing ADR-038 pattern — **Collaboration must not build its own parallel driver-visibility engine** to work around a missing registration (§22).

**"Every employee sees every driver" question:** resolved by the above — it depends entirely on the acting user's resolved scope, not on a fixed yes/no. No employee sees more drivers than their canonical data scope already permits elsewhere in the platform.

---

## 15. Realtime Architecture

**Finding:** nothing exists to reuse. No `laravel/reverb`, no `config/broadcasting.php`, no `routes/channels.php` anywhere in the repository; `BROADCAST_CONNECTION=log` in `.env.example`. `docs/CLAUDE.md` names Reverb as intended stack, but zero wiring exists (§2).

> **CTO Ratification (2026-09-02): AMENDED — supersedes the original "polling-only V1" recommendation.** Near-realtime message/unread delivery is the approved V1 product target. **Laravel Reverb / canonical Laravel Broadcasting is the preferred technical direction** where technically safe and appropriate. Polling is retained as **fallback / resilience mode**, not the primary target experience. Typing indicators and presence **remain deferred** regardless of this change — the brief is explicit that this amendment is about delivery latency, not about adding presence-shaped features.

**Task 3 Existing Capability / Runtime Gate — mandatory, must run before any Reverb-dependent code is written:**

| Check | What it must confirm |
|---|---|
| Package/runtime availability | Whether `laravel/reverb` can actually be installed in this environment/deployment (composer, PHP/Laravel version compatibility) — confirmed **not currently installed** by this report; Task 3 must not assume it can simply be added without checking hosting/ops constraints |
| Deployment topology | Whether the current infrastructure can run a persistent Reverb server process (vs. the stateless PHP-FPM/queue-worker model likely in place) and how it's exposed (ports, reverse proxy, TLS) |
| Auth for private channels | How `routes/channels.php` authorization would integrate with existing Sanctum sessions — none exists today, this is new wiring |
| Company/tenant isolation | Channel naming must be scoped per company/tenant (mirroring `company_id` scoping used everywhere else, e.g. `Driver::booted()`'s global scope) so cross-tenant leakage cannot occur over broadcast channels |
| Conversation-level authorization | A private channel per conversation, authorized against the same `conversation_participants` check as REST access (§8) — never a broader "all messages" channel |
| DriverShell compatibility | Whether DriverShell's runtime (mobile browsers, potentially flaky connections) can sustain a websocket connection acceptably, and what its reconnect/backoff behavior should be |
| Reconnect behavior | What happens to unread/message state during a dropped connection — the ReadCursor model (§8) already makes this safe to reconcile on reconnect (query "messages since last_read_message_id") |
| Polling-fallback behavior | The exact fallback trigger (e.g. Reverb connection failure) and cadence, so the product experience degrades gracefully rather than silently going dark |

**If the gate fails or finds material risk:** per ADR-044's Future Considerations, this must be escalated back to the CTO as a scope/decision point before permanently reverting to polling-only — it would be reversing a ratified product requirement, not a routine implementation choice.

**Task events (assignment/update):** same realtime-with-polling-fallback model extends naturally to task assignment/status-change events once Task 3's channel infrastructure exists, benefiting Task 4/5's driver-assigned-task notifications (§21) too.

---

## 16. Notification Architecture

**Finding:** the "real" answer, `docs/architecture/ENTERPRISE-NOTIFICATION-PLATFORM.md`, is architecture-only with zero implementation (§2). Every existing module that sends notifications rolls its own stock-Laravel `Notification` subclass (e.g. `Modules\Operations\Preparation\Application\Notifications\ExceptionRaisedNotification`, database channel) — there is no canonical service to call into.

**V1 recommendation (unchanged, not among the 8 ratified items):** follow that exact existing precedent — plain Laravel `Notification` classes (`NewMessageNotification`, `MentionedNotification`, `TaskAssignedNotification`, `TaskDueSoonNotification`), `Notifiable` already present on `User`, delivered via the `database` channel into the existing vanilla `notifications` table. Once Task 3's realtime channel infrastructure exists (§15), the same events can additionally push a live client-side toast/badge update — the stored `notifications` row remains the durable record either way, so this is additive, not a redesign.

**Events recommended for V1:** new message in a conversation you're not currently viewing, @mention, task assigned to you (**employee or driver**, §21), task status changed on a task you created, due-date approaching/passed. **Deferred:** granular per-conversation notification preferences (mute a specific conversation's notifications) — no V1 consumer, adds a settings surface not requested.

---

## 17. Storage/Media Architecture

Reuse `App\Core\Documents\DocumentService` + `Document` (table `documents`), the one genuinely-reused shared mechanism found in the entire platform-infrastructure sweep (§2). Concretely:

- Images/files attached to a message or task: stored via `DocumentService`, `subject_type` = `collaboration_message` / `collaboration_task`, `subject_id` = the owning row's id — the same pattern `SupplierInvoiceDocumentController` and `PreparationWaveController::documents()` already use for unrelated modules (`Modules/Purchasing/SupplierInvoices/Presentation/Http/Controllers/SupplierInvoiceDocumentController.php:21-26`; `Modules/Operations/Preparation/Presentation/Http/Controllers/PreparationWaveController.php:737,743`).
- Voice messages: same mechanism, plus the small `collaboration_voice_metadata` side table (§10).
- Disk: the existing private `local` disk (`backend/config/filesystems.php:18,35-41`) — not the `public` disk, and not the currently-unused `s3` disk (though `DocumentService` could move to `s3` later with no change to Collaboration's code, since it depends on the service, not the disk directly).
- Access: a Collaboration-owned controller action authorizes (conversation/task participation) then streams — never a bare `Storage::disk('public')->url()` link, which is the exact anti-pattern found elsewhere in this codebase (`app/Http/Controllers/MediaController.php:44,48`) and explicitly what the brief warns against for voice messages.

**Not adopted:** CEP's flat-column media pattern (`media_url`/`media_type`/`media_size` strings with no storage backing at all) — it doesn't even store files, just external URLs, and offers nothing to reuse (§2).

Not among the 8 ratified decision points — approved as part of the overall architecture acceptance; preserved unchanged.

---

## 18. Search Architecture

**Finding:** no search infrastructure exists anywhere in the backend — no Scout, no Meilisearch, despite `docs/CLAUDE.md` naming Meilisearch as intended stack (§2). The one local precedent, CEP's `ConversationService::search()`, is a plain SQL `LIKE` over metadata columns only — it does not search message bodies at all.

**V1 recommendation:** Postgres native search — a `tsvector` generated column (or expression index) over `messages.body`, queried via `to_tsquery`/`websearch_to_tsquery`, scoped first by the requesting user's conversation participation (never search across conversations the user isn't in).

> **CTO Ratification (2026-09-02): APPROVED as recommended**, with an explicit hard requirement (elevated from "design choice" to "requirement"): **search results must remain authorization-scoped** to the requesting user's conversation participation at all times. No Meilisearch/external search infrastructure for V1 unless future evidence proves it necessary.

**Searchable data:** message body text; conversation title (for group conversations); attachment filename (metadata only, not content — no OCR/transcription in V1).

**Pagination:** same cursor-based approach as §8.

---

## 19. Conceptual Data Model

No migrations are included — this is the conceptual shape only, for CTO review before any schema is written.

| Entity | Owner | Key Relationships | Critical Invariants | Deletion/Retention | Authorization Boundary |
|---|---|---|---|---|---|
| `Conversation` | Collaboration | has many `ConversationParticipant`, `Message`; optional soft ref to `Team` | `type` ∈ {direct, group}; a `group`-type row is a **Collaboration Group** (§7); at most one active `direct` conversation per user pair | No deletion in V1; persists indefinitely | Participation-gated; driver-directed `direct` conversations additionally gated by §14's scope flow |
| `ConversationParticipant` | Collaboration | belongs to `Conversation`; belongs to `User` (enforced FK) | One active row per (conversation, user); `restrictOnDelete` on `user_id` | `left_at` soft-leave, row retained for history | Same as Conversation |
| `Message` | Collaboration | belongs to `Conversation`; belongs to `User` (sender); optional self-FK `reply_to_message_id` | Immutable once created (no edit/delete in V1) | Persists indefinitely (V1) | Sender must be active participant at send time |
| `MessageMention` | Collaboration | belongs to `Message`; belongs to `User` | Mentioned user must have been an active participant at send time | Persists with the message | Inherits Message's boundary |
| `collaboration_voice_metadata` | Collaboration | 1:1 with a `Document` (via `App\Core\Documents\Document`) | One row per voice-type Document | Persists with the Document | Inherits Message's boundary via the owning Message |
| `InternalTask` | Collaboration | belongs to `User` (creator, assignee — **employee or driver**); optional soft ref to `Team`; optional soft ref to source `Conversation`/`Message`; optional `operational_context_links` | `status` ∈ {TODO, IN_PROGRESS, DONE, CANCELLED}; single assignee in V1 | Not deleted; `CANCELLED` is terminal-but-visible | Creator, assignee (employee or driver, via §21's driver-facing surface), or holder of a future "view all tasks" capability (deferred, §14) |
| `InternalTaskComment` | Collaboration | belongs to `InternalTask`; belongs to `User` (author) | Immutable once posted (same as Message, §9) | Persists indefinitely | Same as InternalTask |
| `InternalTaskActivity` | Collaboration | belongs to `InternalTask`; belongs to `User` (actor) | Append-only | Persists indefinitely (it *is* the audit trail) | Same as InternalTask |
| `operational_context_links` | Collaboration | polymorphic-by-convention (`context_type`/`context_id`) to an external entity; polymorphic-by-convention (`attached_to_type`/`attached_to_id`) to Conversation/Message/Task | V1 `context_type` ∈ {Order, Distribution Group, Trip, Driver} only, config-driven set | Link persists even if referenced entity becomes unavailable (renders gracefully, §13) | Viewing linked entity detail requires that entity's own authorization, independent of Collaboration |

Explicitly **not** modeled as separate entities, with reasons already given inline above: `MessageAttachment` (→ reuse `Document`, §17), `MessageRead`/per-message read receipts (→ `ReadCursor` columns on `ConversationParticipant`, §8), `TaskSourceMessageLink` (→ columns on `InternalTask`, §12), `InternalTaskAttachment` (→ reuse `Document`, §17).

---

## 20. UI/UX Information Architecture

**Correction to the brief's assumed stack:** the frontend is **not** Next.js (contra `docs/CLAUDE.md`) — it is Vite + React 19 + React Router v7 (`frontend/package.json:7-8,46`). This section describes the actual stack, preserved as-is per the CTO's instruction not to silently rewrite unrelated global documentation in this continuation.

**Recommended pattern: one Collaboration workspace with Conversations / Tasks tabs.** Justification: this exact pattern is already proven in this codebase — `DistributionWorkspacePage` (`frontend/src/features/logistics/distribution-workspace/pages/distribution-workspace-page.tsx:19,617-633`) uses shadcn `Tabs`/`TabsList`/`TabsContent` for a multi-section workspace with a `PageDrawer` for record detail, and `NAVIGATION-ARCHITECTURE.md`'s module-nav convention supports registering it as a first-class entry.

**Nav placement:** register `Collaboration` as its own top-level entry in `module-navigation.ts` (own rail icon) rather than nesting it under an existing module — it's a cross-cutting, every-employee feature.

**Conversation UI:** a sidebar-list + thread-panel layout, following the shape already implemented independently twice (`UnifiedInboxPage`, `omnichannel-inbox-page.tsx`) — Collaboration should be the third implementation that finally extracts a shared `ConversationListPane`/`MessageThread` pair (Task 5 efficiency item, not a blocker).

**Task UI:** a simple list/board view (status columns or a filterable table) consistent with DP-006's density rules and DP-007's table-first/drawer-for-detail convention, using the existing `PageDrawer` component for task detail. **Now must also support an assignee column/filter that can resolve to either an employee or a driver** (§21), and a "source: conversation" indicator for message-originated tasks (§12).

**Dark mode / RTL / accessibility:** neither `ECOS-DESIGN-PRINCIPLES.md` nor `NAVIGATION-ARCHITECTURE.md` documents these at all. They *are* implemented at the CSS level regardless — `frontend/src/index.css:4` (`@custom-variant dark`) and a dedicated RTL block (`index.css:144-249`) — so Collaboration's UI should simply follow existing Tailwind `dark:` variants and established RTL conventions; there is no repo-specific accessibility standard to cite or violate.

**Voice recording UI:** must be built from scratch — zero existing `MediaRecorder`/audio-recording code anywhere in the frontend (§2). No audio library is currently installed.

---

## 21. DriverShell Integration Boundary

DriverShell (`frontend/src/components/layout/driver-shell.tsx:93-179`) is architecturally a deliberate sibling of the main `AppShell`, not a child of it, and imports nothing from it — this separation is preserved, not touched. Collaboration adds exactly one new entry to DriverShell's existing `SECONDARY_NAV` ("More" sheet) array, following the same registration shape as the existing Trip Expenses entry (`driver-shell.tsx:81-86`; route constant pattern at `frontend/src/router/routes.ts:243`; route registration at `frontend/src/router/router.ts:297`).

> **CTO Ratification (2026-09-02): AMENDED — supersedes the original "not required for V1" recommendation.** A driver may interact with tasks assigned to them in V1. The driver-facing V1 task capability must include, at minimum: see assigned tasks; open task details; receive relevant assignment/update notifications; comment where authorized; progress the assigned task through allowed operational states (`TODO → IN_PROGRESS → DONE`, `CANCELLED` where applicable — same baseline states as §11, no driver-specific state machine).

**Ownership split, as ratified:**
- **`Modules\Collaboration` owns** the task, assignment, comment, and activity domain and its API — identical backend logic regardless of whether the assignee is an employee or a driver (§6, §11 — a driver assignee is simply a `user_id`, no special-casing in the domain layer).
- **Shipping/DriverShell owns** the driver-facing presentation: entry point (a new nav item, likely alongside or replacing the Messages entry depending on Task 5's UX call), assigned-task list, task detail screen, the allowed status-action buttons for that screen, and notification deep-links. Collaboration does not rebuild DriverShell, does not introduce a general project-management workspace into the driver app, and does not become the Driver Experience owner — it exposes the API surface DriverShell's own screens consume, exactly the same relationship Collaboration already has with the office-side workspace (§20), just presented through a different shell.

The driver-facing Collaboration surface (conversations + assigned tasks) is a reduced view relative to the office workspace — no Collaboration Group creation/management UI, no task creation/board UI, no "all tasks" view — consistent with "do not turn DriverShell into a general project-management workspace."

Because DriverShell currently has no header and never imports `NotificationCenter` (`driver-shell.tsx:28`, confirmed no such import exists), an unread-count/task-update indicator must be a badge on the new nav entry/entries, not a reuse of the enterprise `NotificationCenter` component — that component is architecturally scoped to `AppTopbar`, which DriverShell deliberately does not have.

No DriverShell component is modified, rebuilt, or bypassed beyond the standard one-nav-entry registration pattern. No Driver domain logic (trips, loading, delivery, wallet) is touched.

---

## 22. DO-NOT-REIMPLEMENT List

- **Employee/user identity** — `App\Models\User`. Do not create `collaboration_users` or any equivalent.
- **Driver identity** — `Modules\Logistics\Drivers\Domain\Models\Driver` + its `user_id` bridge. Do not create `driver_chat_users` or a parallel driver-identity table.
- **"Team" as a concept/name** — owned exclusively by Organization OS per ADR-011. Do not define a Collaboration-owned `Team` model, even internally-named differently if it functions as an org-chart team. Use **Collaboration Group** (§7) for the ad-hoc entity, always.
- **File/media storage** — `App\Core\Documents\DocumentService`. Do not write a new `Storage::` integration for attachments or voice notes.
- **Authorization mechanism** — IAM's `permission:<resource>.<action>` middleware + `PermissionRegistryInterface`. Do not build a Collaboration-specific role/permission system.
- **Data scope / driver visibility** — IAM's `ScopeResolverInterface` (Data Scope Engine, ADR-038 Part 3, §14). Do not build a Collaboration-specific "who can see which drivers" engine; if the needed resource scope isn't registered yet, that registration belongs to IAM/Logistics via the existing pattern, not a Collaboration-owned substitute.
- **Sanctum authentication** — do not build a separate driver login flow; none exists today and none is needed.
- **DriverShell** — Shipping's presentation layer. Collaboration adds nav entries and consumes/exposes API; it does not rebuild or restructure DriverShell itself.

---

## 23. Deferred V1 Capabilities

| Capability | Recommendation | Rationale |
|---|---|---|
| Message editing | Defer | Ratified §9 |
| Message deletion | Defer | Ratified §9 |
| Retention/auto-deletion policy | Defer | No V1 consumer; matches how every other domain table in this codebase behaves |
| Mute conversation | Defer | No V1 consumer |
| Archive conversation | Defer | No V1 consumer |
| Collaboration Group co-admin roles | Defer | Single-admin (creator) sufficient for V1 group sizes |
| Multiple task assignees | Defer | Brief explicitly frames tasks as lightweight, not PM-grade |
| Team-scoped task visibility | Defer | Blocked on Organization/Teams gaining real membership (§7) |
| Typing indicators / presence | **Defer — explicitly reaffirmed by the CTO** even alongside the realtime amendment (§15) | No presence concept exists to build on (§2); not part of the realtime latency amendment |
| Playback speed control (1x/1.5x/2x) | Include opportunistically | Explicitly non-blocking per brief, cheap client-only feature |
| Scout/Meilisearch adoption | Defer | Ratified §18 — Postgres full-text sufficient at current scale |
| Voice transcoding / multi-format support | Defer | Single fixed format sufficient for V1 (§10) |
| "Create Collaboration Group from Team" | Defer | Blocked on `Organization\Teams\Team` gaining real membership (§7) |

Removed from this list since the last revision (now **in scope for V1**, per ratification): real-time push delivery (§15) and driver-side task visibility/interaction (§21).

---

## 24. CTO Decisions — Ratified 2026-09-02

All 8 items originally flagged as requiring a CTO decision are now resolved. None remain open.

| # | Decision | Original Recommendation | **Final Ratified Decision** |
|---|---|---|---|
| 1 | Module name | `Collaboration` | **Approved as recommended** (§3) |
| 2 | Team/group authority | Ad-hoc groups, no Team reuse | **Approved as recommended**, plus mandatory "Collaboration Group" vs. "Organizational Team" naming split (§7) |
| 3 | Message edit/delete | Defer both | **Approved as recommended** (§9) |
| 4 | V1 operational-context types | Order, Trip, Driver | **Amended:** Order, **Distribution Group**, Trip, Driver (§13) |
| 5 | Employee→driver messaging scope | Broad permission only | **Amended:** permission **and** IAM Data Scope (`ScopeResolverInterface`) required (§14) |
| 6 | Realtime | Polling-only V1 | **Amended:** near-realtime via Reverb/Broadcasting is the primary V1 target; polling is fallback only; Task 3 must run a capability/runtime gate first (§15) |
| 7 | Search | Postgres full-text | **Approved as recommended**, with authorization-scoping now a hard requirement (§18) |
| 8 | Driver-side task visibility | Not required for V1 | **Amended:** in scope for V1 — assigned-task visibility and allowed interaction, with Collaboration/Shipping ownership split (§21) |

---

## 25. Proposed Tasks 2–5 (Updated Per Ratification)

Aligned to the CTO's expected shape, refined with this report's evidence.

**Task 2 — Core Collaboration Foundation.** `Modules\Collaboration` scaffolding; `Conversation`, `ConversationParticipant`, `Message`, `MessageMention` migrations and models; Collaboration Groups (ad-hoc, §7); permission registration (`collaboration.*`); authorization foundations including the employee→driver scope-gated flow's integration points (§14, actual `ScopeResolverInterface` registration verification/work may land here or flow to IAM depending on what Task 2 discovers); operational-context reference foundation (generic, config-driven `context_type` set starting at Order/Distribution Group/Trip/Driver, §13); read/unread (ReadCursor) foundation; REST endpoints for direct/Collaboration-Group conversations, text/image/file messages (via `DocumentService`), replies, mentions. No voice, no realtime, no tasks, no broad UI. Focused domain tests.

**Task 3 — Media + Voice + Realtime + Notifications + Search.** Voice record/upload/playback (`collaboration_voice_metadata`, secure streaming controller, §10); the mandatory Existing Capability/Runtime Gate for Reverb (§15) followed by near-realtime delivery implementation with polling fallback; unread/read realtime updates; stock-Laravel notification classes for message/mention/task events (§16); PostgreSQL full-text search (§18). Typing/presence explicitly not implemented. Focused tests.

**Task 4 — Internal Tasks + Message→Task.** `InternalTask`, comments, activity/history (§11); message→task contract (§12); Order/Distribution Group/Trip/Driver operational-context integration (§13); due-date notifications (using Task 3's plumbing); **driver-assigned task domain capability** — the backend/API side of §21's ratified decision (assignment, status transitions, comments, activity — identical logic for employee or driver assignees). Focused tests.

**Task 5 — Collaboration Workspace + DriverShell Exposure + Batch Closure.** Employee Collaboration Workspace (Conversations/Tasks tabs, §20), factored shared `ConversationListPane`/`MessageThread` components, message composer, voice UX, responsive/mobile, RTL, dark mode, accessibility best-effort; DriverShell collaboration entry point + **driver assigned-task UX** (the presentation side of §21 — list, detail, allowed status actions, notification deep-links, owned by Shipping/DriverShell conventions); batch engineering-state closure and integration-gate preparation.

This remains the four-task shape from the original proposal — no genuine blocking reason was found to split further, and the ratified amendments (Distribution Group, driver-scope auth, realtime, driver tasks) all fit within the existing four without materially changing their boundaries.

---

## 26. Exact Files Created/Changed

| File | Change |
|---|---|
| `docs/adr/ADR-044-internal-collaboration-bounded-context.md` | Updated — Status changed Proposed → **Accepted**; added "CTO Ratification — 2026-09-02" section; updated Consequences/Future Considerations. Original Context/Decision sections preserved verbatim (no rewriting of history). |
| `docs/verification/TASK-ECOS-INTERNAL-COLLABORATION-ARCHITECTURE-001-REPORT.md` | Updated (this file) — all 8 CTO decisions resolved in place; §7, §13, §14, §15, §21 amended; §23/§24/§25 updated accordingly |

No backend code, frontend code, migrations, seeders, or configuration files were created or modified in this continuation, consistent with §13 of the CTO brief. No `backend/Modules/Collaboration` directory was created — module scaffolding remains Task 2's responsibility.

---

## 27. Final Git Status

**Workspace:** `D:\ECOS-Work\ecos-chat`
**Branch:** `task/chat-workstream`
**HEAD:** `16b0ec85df5774f03ccd6dca042528260d66c216` (unchanged since task start — identical to `develop`/`origin/develop`)
**Status:** two untracked files, nothing staged:
```
?? docs/adr/ADR-044-internal-collaboration-bounded-context.md
?? docs/verification/TASK-ECOS-INTERNAL-COLLABORATION-ARCHITECTURE-001-REPORT.md
```

**Commit — BLOCKED.** Section 14 of the CTO continuation brief requires one focused local commit containing exactly these two files, with an explicit instruction: *"If local git identity is unavailable: do not invent one. Report PARTIAL and the exact blocker."* Verified directly: no `user.name`/`user.email` is configured at the local (repo) level, and no global configuration exists at all — `git config --global --list` fails with `fatal: unable to read config file 'C:/Users/m/.gitconfig': No such file or directory`. A `git commit` in this environment would fail outright (Git refuses to author a commit with no identity). Per both this brief's explicit instruction and this assistant's standing rule to never modify git configuration, no identity was set and no commit was attempted.

**Resolution options (for the user/CTO, not applied):** either configure a commit identity — locally (`git config user.name "..."` / `user.email "..."` run in this repo) or globally — and ask for the commit to be retried, or explicitly instruct otherwise. No git action beyond read-only inspection (`status`, `config --list`) was performed.
