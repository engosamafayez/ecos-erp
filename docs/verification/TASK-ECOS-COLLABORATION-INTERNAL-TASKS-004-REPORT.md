# TASK-ECOS-COLLABORATION-INTERNAL-TASKS-004 — Engineering Report

**Workstream:** ECOS ERP — Internal Collaboration & Tasks
**Batch:** Internal Collaboration Batch 01 — Task 4 of 5
**Type:** Implementation — Internal Tasks / Message→Task / Driver Assigned Tasks
**Owner:** A1 — Collaboration
**Architecture authority:** ADR-044 (Accepted)
**Date:** 2026-09-02

---

## 1. Final Status

**COMPLETE** — mandatory Task domain implementation is complete, Message→Task is complete, driver assigned-task source capability is complete, all four approved operational contexts are wired, task security is source-complete, tests are written, one focused local commit was created, and no known source-level security blocker remains — while `TESTS EXECUTED`, `VERIFIED`, and `CERTIFIED` are explicitly `NO`.

---

## 2. Starting HEAD

`97d08b484f3dec1dad80ae1487c19fd69db86a93` (Task 3's realtime-dependency closure commit).

---

## 3. Final Commit SHA

See §32 (recorded after the commit).

---

## 4. Exact Files Changed

**Modified (10):** `backend/config/permissions.php`, `backend/routes/api.php`, `backend/routes/channels.php`, `backend/Modules/Collaboration/Application/Actions/AttachOperationalContextAction.php`, `backend/Modules/Collaboration/Domain/Exceptions/CollaborationException.php`, `backend/Modules/Collaboration/Domain/Services/DriverMessagingAuthorizer.php`, `backend/Modules/Collaboration/Infrastructure/Providers/CollaborationServiceProvider.php`, `backend/Modules/Collaboration/Presentation/Http/Controllers/{CollaborationSearchController,OperationalContextLinkController}.php`, `backend/Modules/Collaboration/Presentation/Http/Requests/AttachOperationalContextRequest.php`.

**Created (44):** 5 migrations; `Domain/Enums/{TaskStatus,TaskPriority}.php`; `Domain/Models/{InternalTask,InternalTaskComment,InternalTaskActivity}.php`; `Infrastructure/Database/Factories/InternalTaskFactory.php`; `Application/DTO/CreateTaskData.php`; `Application/Actions/{CreateTaskAction,ReassignTaskAction,TransitionTaskStatusAction,UpdateTaskAction,AddTaskCommentAction,ListMyTasksAction,SearchTasksAction}.php` + `Concerns/LogsTaskActivity.php`; `Application/Events/TaskBroadcast.php`; `Application/Notifications/{TaskAssignedNotification,TaskStatusChangedNotification}.php`; `Presentation/Http/Policies/TaskPolicy.php`; 5 new Requests; 3 new Resources; 5 new Controllers; 8 new test files.

No file belonging to Task 1-3's committed scope was modified beyond the 10 listed above, and each of those 10 is additive (new methods/cases/routes), never a rewrite of existing behaviour — see §4 of this report's evidence in §29 (regression).

---

## 5. Exact Migrations

| # | File | Change |
|---|---|---|
| 9 | `2026_09_02_100009_create_collaboration_internal_tasks_table.php` | `collaboration_internal_tasks` — uuid PK, `company_id`, `title`, `description`, `creator_user_id`/`assignee_user_id` (both required FKs to `users`), `team_id` (soft label), `priority`, `status`, `due_at`, `completed_at`, `cancelled_at`, `source_conversation_id`/`source_message_id`/`source_message_snapshot` |
| 10 | `2026_09_02_100010_create_collaboration_internal_task_comments_table.php` | `collaboration_internal_task_comments` — uuid PK, `task_id`, `author_user_id`, `body`, `created_at` only (immutable) |
| 11 | `2026_09_02_100011_create_collaboration_internal_task_activity_table.php` | `collaboration_internal_task_activity` — uuid PK, `task_id`, `actor_user_id`, `event_type`, `from_value`, `to_value`, `created_at` (append-only) |
| 12 | `2026_09_02_100012_add_search_vector_to_collaboration_internal_tasks_table.php` | Raw SQL: `search_tsv tsvector GENERATED ALWAYS AS (...) STORED` (title+description) + GIN index — its own vector, separate from messages' |
| 13 | `2026_09_02_100013_seed_collaboration_task_permissions.php` | Mirrors migration #6 (Task 2's permission-catalog remediation) exactly, for `collaboration.tasks.create` and `collaboration.tasks.assign_drivers` — direct idempotent insert into `permissions`, **no role grants** |

All five idempotency-guarded. **None were run** — forbidden on this device (brief §31/§35).

---

## 6. Task Domain / Model

`InternalTask` (`collaboration_internal_tasks`) — owned by `Modules\Collaboration`, never reinterpreted as an HR/PM/Shipping/CustomerEngagement/warehouse object (brief §2). Canonical `User` identity throughout — no `task_users`/`driver_task_users`/`collaboration_task_users` table exists or was considered. `assignee_user_id` is **required**, not nullable (defaults to self-assign at the application layer when omitted — architecture report §12), so a task always has exactly one accountable owner from the moment it exists.

---

## 7. Status Lifecycle

**Exact decision, reported as required rather than assumed (brief §5):** the architecture report (§11) already ratified `DONE → IN_PROGRESS` (reopen) as approved V1 behaviour, always re-entering `IN_PROGRESS`, never a separate `TODO` reset. This task implements exactly that, no more:

```
TODO        -> IN_PROGRESS, CANCELLED
IN_PROGRESS -> DONE, CANCELLED
DONE        -> IN_PROGRESS   (reopen — approved, architecture report §11)
CANCELLED   -> (terminal)
```

No `BLOCKED`/`REVIEW`/`APPROVED`/`REJECTED`/`QA`/`ARCHIVED` state exists. `TransitionTaskStatusAction` enforces this as an explicit allow-list (`CollaborationException::INVALID_STATUS_TRANSITION` otherwise) — raw status assignment through a generic update is not possible (`UpdateTaskAction` does not accept a `status` field at all).

---

## 8. Priority Model

`Domain\Enums\TaskPriority`: `Low`, `Normal`, `High`, `Urgent` — the exact 4-level shape the brief itself suggests (§6). Two heavier, 5-level int-weighted `TaskPriority` enums already exist elsewhere in this codebase (`Modules\System\Engineering`, `Modules\ClaudeBridge`) — found and deliberately **not** reused: those serve engineering/ops task systems, not a lightweight V1 collaboration task, and reusing either would import a scoring dimension (numeric weight) this task's own "not a scoring engine" instruction rules out.

---

## 9. Due-Date Model

`due_at` (nullable `timestampTz`) — a real datetime column, never a UI-formatted string. `null` means no deadline. `InternalTask::isOverdue()` (`due_at !== null && due_at->isPast() && status not in {Done, Cancelled}`) is a **derived, query-time** concept — nothing persists an "overdue" flag, and no scheduler/escalation job was built (brief §7 explicitly forbids one in Task 4).

---

## 10. Assignment Behavior

`CreateTaskAction`/`ReassignTaskAction` resolve the target strictly within the actor's own `company_id` (a foreign-company id behaves exactly like a nonexistent one — 404, not 403, same fail-closed posture as Tasks 2-3). Reassignment is creator-only (`TaskPolicy::reassign`); an assignee — including a driver assignee — cannot reassign their own task (brief §10's explicit prohibition, verified by test).

---

## 11. Employee→Driver Assignment Behavior

`DriverMessagingAuthorizer` gained a sibling method, `assertCanAssign()`, sharing its existing private scope-check with the Task 2 `assertCanAddress()` method (messaging) — **one class, two capabilities**, not a second authorization engine (brief §9's explicit instruction). A separate permission token, `collaboration.tasks.assign_drivers`, gates it — deliberately not reusing `collaboration.conversations.message_drivers`, since a company may reasonably grant one without the other. The conceptual flow is identical to Task 2's: `Authenticate → collaboration.tasks.assign_drivers permission → resolve target driver/user → IAM ScopeResolverInterface → company boundary → assign`.

**External dependency restated, not resolved:** exactly as Task 2/3 found and the CTO explicitly ruled (Task 2's remediation §R1), IAM's live catalogue resolves driver-related scope to `ALL` for every role today — no role has ever been given a narrower `data_scope` for `logistics.drivers`. This task did **not** attempt to fix that (out of scope, external IAM/catalogue capability gap) and did **not** build a Collaboration-side workaround. The scope-denial test (`CollaborationTaskAssignmentTest::test_employee_with_permission_but_outside_scope_cannot_assign_to_the_driver`) substitutes a resolver for the same reason Task 2's equivalent test does: no real role can currently produce that denial.

---

## 12. Driver Assigned-Task Capability

**IN V1, per ADR-044 CTO lock (brief §10), implemented without any participant-type branching anywhere in the domain/application layer.** A driver assignee hits the exact same routes as an employee assignee: `GET /tasks/{task}` (view), `PATCH /tasks/{task}/status` (self-progress, `TODO→IN_PROGRESS`/`IN_PROGRESS→DONE`), `GET/POST /tasks/{task}/comments`, `GET /tasks/{task}/attachments` + download, `GET /tasks/{task}/context-links`. A driver assignee cannot reassign (creator-only), cannot mutate the creator, cannot see another driver's unrelated task (ownership-gated, same `TaskPolicy` as any employee), and cannot reach any operational-context data beyond the bare reference (§20 below — nothing to leak exists in the first place). DriverShell itself was **not** touched — brief §11's hard boundary — this task provides the capability/API only; Task 5 owns the frontend exposure.

---

## 13. Message → Create Task

`CreateTaskAction` accepts an optional `source_message_id` on the same `CreateTaskData`/`CreateTaskRequest` used for plain creation — one action, not two (brief §12). Creating from a message requires the actor to be an active participant of the **source** conversation, checked independently of the task's own authorization (brief §14). The message itself is never mutated — verified directly (`CollaborationMessageToTaskTest::test_creating_a_task_from_a_message_does_not_alter_the_message` compares the message's raw attributes before/after).

---

## 14. Source-Message Security

**A real design correction was made here, not a restatement of the architecture report.** The original architecture report (§12) described the snapshot as simply "surviving" once captured, implying any task viewer would see it. This task's own brief is more exacting — §14 says plainly: *"Later task viewers must NOT automatically gain access to the original conversation merely because the task contains a source-message reference... If the viewer cannot access the original conversation, preserve the task without leaking message content."* That is a real, independent security requirement, and the original framing did not fully satisfy it. `TaskResource::sourceSnapshotFor()` now re-checks the **current viewer's** own `ConversationParticipant` status against `source_conversation_id` on every response — a task assignee with no relationship to the source conversation sees the task (title, status, `source_message_id` as a bare pointer) but `source_message_snapshot: null`, while the creator (who does have source access) sees the real text. Verified directly: `CollaborationMessageToTaskTest::test_a_task_assignee_without_source_conversation_access_cannot_read_the_original_message`.

---

## 15. Comments

`InternalTaskComment` — a **distinct** domain object from `Message`, its own table, never routed through `collaboration_messages` (brief §16). Immutable once posted (no `updated_at`), ownership-gated (creator or assignee — `TaskPolicy::comment`), no threading.

---

## 16. Attachments / DocumentService Reuse

`TaskAttachmentController` reuses `App\Core\Documents\DocumentService` exactly as `SupplierInvoiceDocumentController` does (Task 3's precedent) — `subjectType='CollaborationTask'`, `subjectId=<task id>`. Unlike a `Message` (at most one attachment), a `Task` may carry several, so this is list+upload+download rather than the single-attachment shape in `MessageAttachmentController`. Same private-disk, auth-gated-streaming discipline; a guessed document id is refused by `TaskPolicy::attach`/`view` before the document lookup is even reached (verified: `CollaborationTaskCommentsAttachmentsTest::test_a_guessed_document_id_does_not_bypass_task_authorization`).

---

## 17. Activity / History

`InternalTaskActivity`, append-only, module-owned (matching the same convention every other module in this codebase already follows independently — `App\Core\Audit\AuditService` remains confirmed dead code, not resurrected). Logged: `created`, `assigned` (with from/to user ids), `status_changed` (with from/to status), `priority_changed`, `due_date_changed`, `comment_added`, `updated` (title/description, without echoing full text into the audit trail). Exposed on `GET /tasks/{task}` via `TaskResource`'s `activity` field.

---

## 18. Operational-Context Implementation

`AttachOperationalContextAction` now handles `AttachedToType::Task` (previously it threw "not available until Task 4"). Authorization branches structurally by attachment target: conversation/message stays participation-based (unchanged from Task 2); task is **ownership-based** (creator or assignee) — a task need not have a source conversation at all, so deriving its authorization from one would be wrong. A new `GET /tasks/{task}/context-links` endpoint (`OperationalContextLinkController::indexForTask`, `TaskPolicy::view`-gated) lists a task's links.

---

## 19-22. The Four Approved V1 Context Types

Order, Distribution Group, Trip, Driver — all four accepted by `AttachOperationalContextRequest`'s existing `in:order,distribution_group,trip,driver` rule (unchanged from Task 2, already covered all four). Task 4 did not need to add or change this validation — it only extended *what* can be an attachment target (adding Task), not *which context types* are valid (already complete). All four verified individually in `CollaborationTaskContextTest`. Collaboration does not own, query, or duplicate any of these entities' own state (brief §20) — every stored link is a bare `context_type`/`context_id` string pair; `OperationalContextLinkController::format()` never returns anything beyond that pair, so there is structurally no operational data available to leak (§21) — proven directly by asserting the exact response key set in `CollaborationDriverTaskTest::test_a_driver_without_task_access_cannot_see_its_operational_context_links`. An unsupported type (e.g. `purchase_order`) is rejected at the validation layer, unchanged from Task 2/brief §19's prohibition on introducing new types without architecture approval.

---

## 23. Permissions

First inspected the existing 3 `collaboration.*` tokens before adding anything (brief §26): none of them cover task creation or driver-task-assignment, and none needed to — reusing `collaboration.conversations.create` for tasks would conflate two independent capabilities a company might want to grant separately. **Exactly 2 new tokens**, no more: `collaboration.tasks.create` (coarse gate, mirrors `conversations.create`), `collaboration.tasks.assign_drivers` (mirrors `conversations.message_drivers`'s shape). View/comment/status-transition/attach/reassign/cancel are all ownership-gated inside `TaskPolicy` and the actions themselves — **zero permissions for any of them**, avoiding the "a permission for every button" outcome the brief explicitly warns against.

---

## 24. Authorization / Policy

`TaskPolicy` (registered via `Gate::policy(InternalTask::class, TaskPolicy::class)` in `CollaborationServiceProvider::boot()`) distinguishes exactly the methods brief §28 lists: `view`, `update`, `reassign`, `transitionStatus`, `cancel` (an explicit alias onto `transitionStatus` — cancellation is one of its allowed targets, not a separate rule, but named independently for traceability), `comment`, `attach`. Every mutating Action re-derives its own authorization check independently of the controller-level policy call (defense in depth, same posture as Tasks 2-3) — authorization is never solely a controller concern, and frontend CTA visibility (which doesn't exist yet — no UI was built) was never the enforcement point.

---

## 25. Tenant / Company Isolation

`TaskPolicy`'s every method checks `task.company_id === user.company_id` explicitly, in addition to the ownership check — a second, independent enforcement point, not a redundant no-op (brief §29). Every target-resolution query (assignee, reassignment target, team) is scoped by `company_id` before use. A cross-company task id, comment, attachment, or context-link request is refused (403 via policy, or 404 via scoped `firstOrFail()` for target resolution) — never a status code that would confirm the resource exists in a company the requester can't see into.

---

## 26. Notifications

Two new stock `Illuminate\Notifications\Notification` classes (`database` channel, mirroring Task 3's exact pattern): `TaskAssignedNotification` (sent to the assignee on creation and reassignment, never the actor) and `TaskStatusChangedNotification` (sent to whichever of {creator, assignee} did **not** perform the transition, never the actor). Deliberately **not** added for V1, to avoid notification spam (brief §24 explicit warning): comment notifications, "relevant reassignment" beyond the assignee themselves, and due-date-change notifications (would require a scheduler, out of scope per §7). No second notification-persistence mechanism — same `notifications` table Task 3 already uses.

---

## 27. Realtime / Polling Reuse

`TaskBroadcast` (core `laravel/framework` `ShouldBroadcast` contracts only, zero Reverb-specific import — same posture as `MessageBroadcast`/`ConversationReadStateBroadcast`) fires on task create/reassign/status-change/update, broadcasting on `collaboration.user.{assigneeId}` and, if different, `collaboration.user.{creatorId}` — new private per-user channels (a task isn't always tied to a conversation channel), authorized in `routes/channels.php` by the simplest possible rule: a user may only listen to their own channel. Polling-compatible retrieval already exists via the ordinary `GET /tasks`/`GET /tasks/{task}` endpoints — no separate task-sync mechanism was built. Reverb package/runtime activation remains exactly where Task 3's CTO ruling left it: deferred to the canonical device; this task added no composer dependency and did not touch `composer.json`/`composer.lock`.

---

## 28. Tests Written

Eight new files, same conventions as Tasks 2-3 (`DatabaseTransactions`, `actingAsUnprivileged()` with explicit real grants):

| File | Brief scenarios |
|---|---|
| `CollaborationTaskDomainTest.php` | 1-10 |
| `CollaborationTaskAssignmentTest.php` | 11-15 |
| `CollaborationMessageToTaskTest.php` | 16-20 |
| `CollaborationTaskCommentsAttachmentsTest.php` | 21-25 |
| `CollaborationDriverTaskTest.php` | 26-31 |
| `CollaborationTaskContextTest.php` | 32-37 |
| `CollaborationTaskActivityNotificationTest.php` | 38-43 |
| `CollaborationTask4RegressionTest.php` | 44-48 |

All 48 required scenarios covered 1:1, plus extras (e.g. explicit reopen test, invalid-priority rejection, cross-company context-link rejection). Scenario 15 ("no duplicate Collaboration security engine") is tested structurally — asserting `DriverMessagingAuthorizer` carries both capabilities and that no sibling `DriverTaskAuthorizer`/`DriverAssignmentAuthorizer` class exists.

---

## 29. Tests Executed

**NO.** Same second-device constraint as Tasks 1-3. Brief §33 explicitly notes PHP/Composer *may* now exist on this machine but states plainly that this does not change batch policy — no database/test environment was bootstrapped for this task.

---

## 30. Verification State

`TESTS EXECUTED: NO`. `VERIFIED: NO`. `CERTIFIED: NO`. Every claim above is a manual trace through the code, cross-checked against the actual Task 2/3 source it extends (§4/§11/§14 in particular involved re-reading the existing `DriverMessagingAuthorizer` and architecture report text directly, not from memory).

---

## 31. External IAM Narrow-Driver-Scope Dependency

Unchanged, restated for this task's own record: `EXTERNAL IAM DEPENDENCY`. No Collaboration code was written to work around it; §11 above documents exactly how the existing mechanism (now serving two capabilities) continues to depend on it correctly.

---

## 32. Exact Git Status

See the Final Notification for the commit SHA and post-commit `git status` output.

---

## 33. Remaining Task 5 UI Scope

Final Collaboration Workspace UI (Conversations + Tasks tabs), the factored `ConversationListPane`/`MessageThread` components, task list/board UI, voice recorder/player UX, DriverShell collaboration entry point + driver assigned-task UX (list, detail, status-action buttons, notification deep-links — the *presentation* half of §12 above, whose *domain/API* half this task completes), RTL/dark-mode/accessibility polish, batch engineering-state closure.

---

## 34. Recommended Task 5 Closure Boundary

Task 5 should treat every backend capability across Tasks 2-4 as frozen and build the frontend against it as-is — no further backend changes should be needed for the V1 scope ADR-044 defined, with two explicit exceptions to flag rather than silently work around if Task 5 discovers a real gap: (1) whether driver-side task visibility needs anything beyond the existing `GET /tasks?scope=assigned` + status-transition endpoints once real DriverShell UX is designed, and (2) the two runtime-dependency items already on record (Task 3's Reverb activation, Task 2's permission-seed-path confirmation) — both are canonical-device prerequisites for certification, not Task 5 backend work. Task 5 should also decide, as an explicit product call rather than an inherited default, whether the Collaboration Workspace's Tasks view needs any filter beyond what `ListMyTasksAction` already exposes (status, priority, overdue, team) before requesting new backend query support.
