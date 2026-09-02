# TASK-ECOS-COLLABORATION-WORKSPACE-DRIVER-EXPOSURE-CLOSURE-005 — Engineering Report

**Workstream:** ECOS ERP — Internal Collaboration & Tasks
**Batch:** Internal Collaboration Batch 01 — Task 5 of 5 (final)
**Type:** Implementation — Frontend Collaboration Workspace, Driver Task Exposure, Batch Source Reconciliation
**Owner:** A1 — Collaboration
**Architecture authority:** ADR-044 (Accepted)
**Date:** 2026-09-02 to 2026-09-03

---

## 1. Final Status

**COMPLETE**, with one CTO-authorized in-task scope addition (§9) and one deliberately-deferred, low-risk enhancement (§18). Frontend Collaboration Workspace (Conversations + Tasks), driver task exposure via the existing DriverShell, and the batch-wide source reconciliation are all complete. `TESTS WRITTEN`: `YES` for both backend (§9.4/§4.5) and frontend (§21). `TESTS EXECUTED`: `YES` for the frontend — real `npm`/`vitest`/`tsc`/`eslint` runs on this device, a first for this batch (§20/§21) — and `NO` for the backend (same second-device constraint as every prior task in this batch: no PHP/Composer/database here). `VERIFIED`/`CERTIFIED` remain `NO` for anything requiring a running database or an actual browser. No known source-level security blocker remains.

---

## 2. Starting HEAD

`dc52ce8e` (Task 4 report finalization's documentation-only commit).

---

## 3. Final Commit

One commit on `task/chat-workstream`, parent `dc52ce8e`, containing every change described in this report (§4-§20) plus this report itself — per §24's policy, this file is deliberately worded without a self-referential SHA (the ad-hoc pattern this task's own instructions asked not to repeat); its exact hash is whatever `git log -1 --format=%H` returns for this commit, reported verbatim in this task's chat-facing Final Notification rather than hardcoded here.

---

## 4. Batch Source Reconciliation

This section was produced by re-reading source directly (grep/read every file cited), not by trusting the prose or arithmetic in the four prior reports.

### 4.1 Single-Authority Concept Ownership

| Concept | Single authority | Notes |
|---|---|---|
| Conversation / Message / Participant domain | `Modules\Collaboration\Domain\Models\{Conversation,ConversationParticipant,Message,MessageMention}` | Task 2 |
| Collaboration Group vs Organizational Team | ADR-044 §7 / ADR-011 | `Conversation{type:group}` never touches `Organization\Teams\Team` |
| Employee→Driver authorization (messaging + task assignment) | `Modules\Collaboration\Domain\Services\DriverMessagingAuthorizer` | One class, two methods (`assertCanAddress`, `assertCanAssign`) — Task 2, extended Task 4 |
| Driver identity bridge | `Modules\Logistics\Drivers\Domain\Models\Driver.user_id` (nullable, unique) | Never exposed by `DriverResource` — see §9.1 |
| Permission-catalog persistence pattern | Direct-insert idempotent migration (precedent: `2026_08_20_100000_seed_loading_os_permissions.php`) | Task 2 remediation; mirrored by `100013` (Task 4) — no new pattern introduced by Task 5 |
| Data Scope Engine | `Modules\IAM\Domain\Contracts\ScopeResolverInterface` + `Builder::scopedTo()` macro (`IamServiceProvider`) | ADR-038; reused as-is by `SearchAddressableUsersAction` (§9), not reimplemented |
| Company-scoped user query surface | `Modules\IAM\Application\Services\UserRepository` | Built for ADR-040, never wired to a route before Task 5 — now wired (§9) |
| Internal Task domain/lifecycle | `Modules\Collaboration\Domain\Models\InternalTask` + `TransitionTaskStatusAction::ALLOWED_TRANSITIONS` | Task 4 |
| Source-message privacy on a Task | `TaskResource::sourceSnapshotFor()` | Task 4 design correction, CTO-approved; preserved verbatim by the frontend (§13) |
| Realtime broadcasting contracts | `Modules\Collaboration\Application\Events\{MessageBroadcast,ConversationReadStateBroadcast,TaskBroadcast}` — core `laravel/framework` only | Task 3/4; Reverb package deliberately not installed (§17) |
| Frontend realtime adapter boundary | `frontend/src/features/collaboration/hooks/use-realtime-status.ts` | Task 5, new — see §14 |
| DriverShell | `frontend/src/components/layout/driver-shell.tsx` | Untouched in structure/ownership; one nav entry added (§15) |

### 4.2 DO-NOT-REIMPLEMENT List

A future task must reuse, never rebuild, any of the following:

1. `DriverMessagingAuthorizer` — the only employee→driver authorization engine in Collaboration. Do not create a `DriverTaskAuthorizer`/`DriverAssignmentAuthorizer`/frontend-side equivalent.
2. The permission-catalog direct-insert migration pattern — do not invent a second seeding mechanism.
3. `Modules\IAM\Application\Services\UserRepository` — the one canonical company-scoped user query surface (now wired at `GET /collaboration/search/users`, §9). Do not build a second user repository, search index, or directory service anywhere in Collaboration or elsewhere for this purpose.
4. `TransitionTaskStatusAction::ALLOWED_TRANSITIONS` / its frontend mirror `frontend/src/features/collaboration/lib/task-meta.ts`'s `allowedTaskStatusTransitions()` — the one status state machine. Do not add a second status enum or a UI that offers a transition this table doesn't allow.
5. `TaskResource::sourceSnapshotFor()` — the one source-message-privacy gate. Do not add a second code path that reads `source_message_snapshot` without this per-viewer check (backend), and do not build a frontend fallback that infers/reconstructs snapshot text when the API returns `null` (§13).
6. `App\Core\Documents\DocumentService` — the one private-file storage mechanism; `MessageAttachmentController`/`TaskAttachmentController` are its only Collaboration consumers. Do not introduce a public URL, a second disk, or a client-side-cached copy of attachment bytes.
7. The name-embedding pattern (`whenLoaded('relation', fn () => ...)` on an already-authorized Resource) introduced in §9.2 — the correct way to add a display name to a Collaboration API response is to embed an existing Eloquent relation on the resource that already gates access to that data, never a new cross-cutting id-resolution call from the frontend.
8. DriverShell itself, its four primary thumb-reach slots, its "More" sheet mechanics, and its ownership (Operations\Driver Mobile) — Task 5 added exactly one `SECONDARY_NAV` entry and one route; nothing else in the shell changed.

### 4.3 Exact Permission Inventory (recounted from source)

Five `collaboration.*` tokens exist, consistently across all three places they are declared — verified identical by direct read, not assumed:

| # | Token | Declared in `config/permissions.php` | Registered in `CollaborationServiceProvider::boot()` | Persisted by migration |
|---|---|---|---|---|
| 1 | `collaboration.conversations.create` | ✅ | ✅ | `100006` |
| 2 | `collaboration.conversations.message_drivers` | ✅ | ✅ | `100006` |
| 3 | `collaboration.groups.create` | ✅ | ✅ | `100006` |
| 4 | `collaboration.tasks.create` | ✅ | ✅ | `100013` |
| 5 | `collaboration.tasks.assign_drivers` | ✅ | ✅ | `100013` |

**Task 5 added zero new permission tokens.** The new `GET /collaboration/search/users` endpoint (§9) is deliberately unguarded by a `permission:` middleware — like its siblings `search/messages` and `search/tasks`, its safety comes from the query itself being company-scoped and (for driver candidates) scope-filtered inside `SearchAddressableUsersAction`, not from a route-level permission gate. This mirrors the existing `search/*` convention exactly; it is not a new pattern.

### 4.4 Exact Migration Inventory (recounted from source)

Fourteen migrations, `2026_09_02_100000` through `2026_09_02_100013`, contiguous with no gaps — confirmed by directory listing, not by re-adding prior reports' tables:

| # | File | Task |
|---|---|---|
| 100000 | `create_collaboration_conversations_table` | 2 |
| 100001 | `create_collaboration_conversation_participants_table` | 2 |
| 100002 | `create_collaboration_messages_table` | 2 |
| 100003 | `add_last_read_message_id_to_collaboration_conversation_participants_table` | 2 |
| 100004 | `create_collaboration_message_mentions_table` | 2 |
| 100005 | `create_collaboration_operational_context_links_table` | 2 |
| 100006 | `seed_collaboration_permissions` | 2 (remediation) |
| 100007 | `create_collaboration_voice_metadata_table` | 3 |
| 100008 | `add_search_vector_to_collaboration_messages_table` | 3 |
| 100009 | `create_collaboration_internal_tasks_table` | 4 |
| 100010 | `create_collaboration_internal_task_comments_table` | 4 |
| 100011 | `create_collaboration_internal_task_activity_table` | 4 |
| 100012 | `add_search_vector_to_collaboration_internal_tasks_table` | 4 |
| 100013 | `seed_collaboration_task_permissions` | 4 |

**Task 5 added zero new migrations.** Every backend change this task made (§9) is a Resource/Controller/Action change reusing already-existing columns and relations — no schema changed, so no migration was needed, and none was written.

### 4.5 Exact Backend Test Inventory (recounted from source, arithmetic verified)

Counted via `grep -c "public function test_"` per file, not by re-adding prior reports' claimed totals:

| File | Tests | Task |
|---|---|---|
| `CollaborationConversationTest.php` | 8 | 2 |
| `CollaborationMessageTest.php` | 10 | 2 |
| `CollaborationDriverAuthorizationTest.php` | 6 | 2 |
| **Task 2 subtotal** | **24** | |
| `CollaborationPermissionCatalogTest.php` | 4 | 2 remediation |
| `CollaborationMediaTest.php` | 10 | 3 |
| `CollaborationVoiceTest.php` | 8 | 3 |
| `CollaborationRealtimeTest.php` | 6 | 3 |
| `CollaborationNotificationTest.php` | 4 | 3 |
| `CollaborationSearchTest.php` | 6 | 3 |
| `CollaborationTask3RegressionTest.php` | 6 | 3 |
| **Task 3 subtotal** | **40** | |
| `CollaborationTaskDomainTest.php` | 13 | 4 |
| `CollaborationTaskAssignmentTest.php` | 6 | 4 |
| `CollaborationMessageToTaskTest.php` | 5 | 4 |
| `CollaborationTaskCommentsAttachmentsTest.php` | 5 | 4 |
| `CollaborationDriverTaskTest.php` | 6 | 4 |
| `CollaborationTaskContextTest.php` | 6 | 4 |
| `CollaborationTaskActivityNotificationTest.php` | 6 | 4 |
| `CollaborationTask4RegressionTest.php` | 6 | 4 |
| **Task 4 subtotal** | **53** | |
| `CollaborationUserSearchTest.php` | 7 | **5, new** |
| **Task 5 subtotal** | **7** | |
| **Grand total** | **128** | across **19** files |

Arithmetic check: 24 + 4 + 40 + 53 + 7 = 128. ✅ No discrepancy found against a direct grep count (121 pre-Task-5 + 7 new = 128).

Task 5 also **strengthened 5 existing test methods** (not new methods — existing assertions extended) to prove the new name-embedding fields (§9.2) actually reach the API response, in `CollaborationConversationTest`, `CollaborationMessageTest` (×2 methods), `CollaborationTaskDomainTest`, `CollaborationTaskCommentsAttachmentsTest`, `CollaborationTaskActivityNotificationTest` — these do not change the 128 count above.

### 4.6 Exact Frontend Test Inventory

See §16.

---

## 5. Stack Correction Note

`docs/CLAUDE.md` states "Frontend: Next.js" — **stale**. The actual, running frontend is Vite + React 19 + React Router v7 + TypeScript (`frontend/package.json`, `frontend/src/router/router.ts`). This task's entire implementation targets the real stack, confirmed by inspecting `frontend/src/router/router.ts`, `frontend/src/config/module-navigation.ts`, `frontend/src/features/authorization/use-navigation.ts`, and a representative sample of existing feature modules before writing any code, not by trusting the stale doc.

---

## 6. Frontend Architecture Overview

New feature module at `frontend/src/features/collaboration/`, 31 non-test files, layered exactly like every other feature in this codebase:

- **`types/index.ts`** — TypeScript interfaces mirroring every backend Resource field-for-field, including the new name fields added in §9.2 and the dual-null semantics of `source_message_snapshot` (documented inline, see §13).
- **`services/{collaboration-service,tasks-service}.ts`** — thin `axios` wrappers over every Task 2-5 endpoint; zero client-side reimplementation of participation/ownership/authorization logic.
- **`hooks/`** — 7 TanStack Query hook files (`use-conversations`, `use-messages`, `use-tasks`, `use-realtime-status`, `use-secure-media`, `use-user-search`, `use-message-type-label`), all polling-fallback aware (§14).
- **`lib/`** — 2 pure-logic files: `task-meta.ts` (status-transition table mirror, badge tone maps) and `conversation-display.ts` (direct-vs-group title resolution).
- **`components/`** — 18 presentational/container components (§7-§12).
- **`pages/collaboration-workspace-page.tsx`** — the one enterprise route, query-param-driven tabs (§6.1).

### 6.1 Workspace Route Design

Conversations and Tasks are tabs of **one** route (`ROUTES.collaborationWorkspace = '/collaboration'`), not two separate routes — state (`tab`, `conversationId`, `taskId`) lives in the URL's query string via `useSearchParams()`, not local component state. This was a deliberate choice over two routes: it lets "Message → Create Task" and a task's "View in conversation" link jump between the two views with a normal, back-button-friendly URL change instead of a route transition, and it required zero new nested-layout machinery in `router.ts`.

---

## 7. Conversations UX

- **List** (`conversation-list.tsx` + `conversation-list-item.tsx`): search-filters by resolved display title (client-side, since there is no conversation-title search endpoint), unread badge (caps at "99+"), New Direct / New Group entry points via a dropdown.
- **Thread** (`conversation-thread.tsx`): header with resolved title/avatar, realtime-status indicator (Wifi/WifiOff icon), auto-scroll to newest message, marks the conversation read against the newest loaded message id whenever it changes.
- **Composer** (`message-composer.tsx`): text (Enter to send, Shift+Enter for a newline), reply bar (cancelable), inline `@mention` autocomplete sourced from the conversation's own **already-loaded, already-authorized** participant list (never a global user search — see §9.3), image/file attach buttons (hidden file inputs, mirroring the existing `InvoiceAttachments`/`payment-proof-section.tsx` convention), and a mic button that swaps the composer body for the voice recorder.
- **Voice recorder** (`voice-recorder.tsx`): record → stop → preview → send/discard, built directly on the native `MediaRecorder`/`getUserMedia` APIs — confirmed by repo-wide search to be the **first** use of these APIs in this codebase, so there was no existing hook/wrapper to reuse or duplicate. Explicit failure states, each with its own translated message: permission denied, no microphone found, `MediaRecorder` unsupported in this browser, and a generic recording failure — matching every state the `voice.*` i18n keys were written for.
- **New Direct / New Group** (`new-direct-dialog.tsx`, `new-group-dialog.tsx`): both built on the shared `UserPicker` (§9.3).
- **Info panel** (`conversation-info-panel.tsx`): participant list with resolved names (§9.2), add/remove member (group owner only), leave group (any member).

---

## 8. Secure Media Retrieval

`hooks/use-secure-media.ts` — every attachment (image, voice, file, task attachment) is fetched as an authenticated blob and turned into a same-origin object URL, mirroring the existing `payment-proof-section.tsx` pattern exactly (keyed by id so a stale URL can never be shown against a new attachment; always revoked on unmount/key change). A plain `<img src>`/`<audio src>` would 401 against `DocumentService`'s private-disk streaming routes — this was never attempted. File-type attachments are downloaded on demand (click-to-download, mirroring `InvoiceAttachments.download()`), never pre-fetched, so a message list never eagerly downloads every file byte just because it renders.

---

## 9. The Identity-Lookup Capability Gap — Discovery, CTO Ruling, Resolution

### 9.1 The Gap

While building the UI, direct source inspection established that **every** Collaboration backend Resource (`ConversationResource`, `ConversationParticipantResource`, `MessageResource`, `TaskResource`, `TaskCommentResource`, `TaskActivityResource`) exposed only raw numeric `*_user_id` fields — never a name — and that **no generic "search/resolve a company user" endpoint existed anywhere in this codebase**. Two independent research passes confirmed this exhaustively:

- IAM's own HTTP-surface audit doc (`docs/verification/TASK-IAM-HTTP-SURFACE-001-CONTRACT-AUDIT.md`) states plainly: *"Management HTTP is confirmed greenfield — zero `users` routes, no `UserController` anywhere."*
- `Modules\IAM\Application\Services\UserRepository` — a real, correctly-shaped (name/email/username/employee-number/phone/job-title search + company scoping + pagination) query class built for ADR-040 — existed but was **never wired to a controller or route**.
- `Modules\Logistics\Drivers\Presentation\Http\Resources\DriverResource` never exposes `Driver.user_id`, so even a Driver-specific picker could not resolve a driver to their messaging identity.
- HR's `GET /hr/employees` was the closest reachable search, but it is gated behind `hr.employees.view` and queries `hr_employees` (optional `user_id` bridge in both directions), covering neither all users nor drivers.

Without resolution, the entire workspace — sender names, participant lists, task creator/assignee, comment authors, activity actors, and any "start a conversation with a colleague" picker — would have degraded to raw numeric ids.

### 9.2 The Ruling

This was surfaced to the CTO before proceeding (not silently resolved or silently left broken). The ruling: **add one minimal, additive, read-only Collaboration-facing identity lookup, reusing IAM's existing `UserRepository` — do not build a second user repository, do not use `/hr/employees` as the canonical source, do not degrade the UI to "User #id", enforce tenant/authorization/driver-scope boundaries strictly, and keep the change minimal and directly required by this task.**

### 9.3 What Was Built

**A. Name resolution for everyone already visible to the viewer** (sender, participants, task creator/assignee, comment author, activity actor) — **zero new authorization surface**. Every one of these relations (`Message::sender()`, `ConversationParticipant::user()`, `InternalTask::creator()`/`assignee()`, `InternalTaskComment::author()`, `InternalTaskActivity::actor()`, `Conversation::activeParticipants()`, `MessageMention::mentionedUser()`) **already existed** in the domain models before Task 5. The fix was to `whenLoaded()`-embed each one as a `*_name` (or, for participants/mentions, a small nested object) field on the Resource that already gates access to that data, and add the corresponding one-line eager-load (`->with(...)`/`->load(...)`) at each of the 11 controller/action call sites that construct that Resource. Reaching the resource at all already required passing that resource's own authorization (conversation participation, task ownership) — embedding a co-participant's or co-owner's name leaks nothing a Slack/WhatsApp-equivalent app wouldn't already show. `mentioned_user_ids` (existing field) is untouched for backward compatibility; a new, additive `mentioned_users: {id, name}[]` sits alongside it.

**B. `GET /collaboration/search/users`** — the one genuinely new endpoint, for the one genuinely new case: finding somebody the actor has **no established Collaboration relationship with yet** (new direct conversation, new group member, task assignee/reassignment). `Modules\Collaboration\Application\Actions\SearchAddressableUsersAction`:
- Calls `Modules\IAM\Application\Services\UserRepository::search()` **verbatim**, scoped to `company_id: $actor->company_id` — hard tenant boundary, no cross-company disclosure possible.
- Excludes the actor's own id (this endpoint is for finding somebody *else*).
- For each candidate who is driver-linked (`Driver::where('user_id', ...)`), the candidate is **removed from the result set entirely** unless the actor both holds `collaboration.conversations.message_drivers` (checked via `AuthorizationGatewayInterface::can()`, non-throwing) **and** the driver is within the actor's IAM data scope (`Driver::query()->scopedTo($actor, 'logistics.drivers')` — the same `ScopeResolverInterface`-backed macro `DriverMessagingAuthorizer` already uses). This is filtering at search time, not merely at the point of use — an unauthorized driver is never even revealed as existing, let alone addressable. It does not replace `DriverMessagingAuthorizer`'s own checks at the point of actually starting a conversation or assigning a task — both layers run independently.
- Returns the minimum safe projection only: `{id, name, is_driver}` — no email, phone, role, or any other `User` field crosses this boundary (`AddressableUserResource`).
- Mounted as a third method on the existing `CollaborationSearchController` (alongside `messages`/`tasks`), same `throttle:30,1`, same "no `permission:` middleware, safety comes from the query" convention as its siblings — no new controller class, no new authorization pattern.
- Route: `GET /collaboration/search/users` (`backend/routes/api.php`).

**C. Mentions stay scoped to the conversation itself** (§9.3.A's participant embedding), never the new search endpoint — the composer's `@mention` autocomplete filters the conversation's own `participants` array client-side. This was an explicit ruling constraint, not an oversight: mentioning someone already requires them to be an active participant (enforced server-side, unchanged from Task 2), so there is no reason for the mention picker to reach for a wider search.

### 9.4 Tests

`CollaborationUserSearchTest.php` — 7 new tests, covering exactly the four properties the ruling required: employee lookup (happy path), tenant isolation (foreign-company user excluded, self excluded), unauthorized identity non-disclosure (driver-linked user invisible without the permission), and driver-scope behavior (visible + `is_driver:true` with permission+scope; invisible with permission but outside scope, mirroring `CollaborationDriverAuthorizationTest` scenario 5's `ScopeConstraint::none()` substitution pattern). Five existing tests were also strengthened (§4.5) to prove the name-embedding reaches real API responses.

### 9.5 Scope Discipline

No new IAM architecture was introduced. No second user repository, identity model, or search engine exists. `Driver.user_id` remains unexposed by `DriverResource` (out of scope — not needed, since `SearchAddressableUsersAction` resolves driver-linked users by `User` identity, never by asking the frontend to bridge through `Driver.id`). This is documented here as a Task 5 integration requirement discovered during implementation, per the ruling's own instruction — not as a new architecture decision requiring a fresh ADR.

---

## 10. Tasks UX

- **List + filters** (`task-list.tsx`, `task-filters.tsx`, `task-list-item.tsx`): scope (mine/created/assigned), status, priority, overdue-only — every filter maps 1:1 onto `ListMyTasksAction`'s existing query params; no client-side filtering of an unfiltered list.
- **Detail** (`task-detail-drawer.tsx`, Sheet + Tabs, mirroring the closest existing precedent `frontend/src/features/engineering/components/inbox/TaskDrawer.tsx`): Overview (description, creator/assignee, reassign, source-message block, operational-context links), Comments, Attachments, Activity.
- **Create** (`create-task-dialog.tsx`): plain creation and the Message→Task variant (§11) share one dialog; assignee defaults to the actor themselves (matching `tasks.createDialog.assigneeDefault`'s copy) when nothing is picked.
- **Status transitions** (§12) and **reassignment** (creator-only, mirroring `TaskPolicy::reassign` exactly — the UI never renders a reassign control for a non-creator assignee).
- **Context links**: read-only list + a minimal add form (type + id), reusing the existing 4 approved types (order/distribution_group/trip/driver) — no fifth type introduced.

---

## 11. Message → Create Task UX

A message bubble's "Create Task" action opens `CreateTaskDialog` pre-filled with the message's own text (truncated) and `sourceMessageId` set — one dialog, one `CreateTaskAction` call, matching the backend's own "one action, not two" design (Task 4 §13). On success the workspace switches to the Tasks tab and opens the new task's detail drawer, so the actor sees immediate confirmation the task now exists.

---

## 12. Source Message Privacy — Hard Lock (Frontend Preservation)

The Task 4 CTO-approved tightening (`TaskResource::sourceSnapshotFor()`, §14 of the Task 4 report) is preserved **exactly** on the frontend, not reinterpreted:

- `Task.source_message_id !== null && Task.source_message_snapshot === null` → the Overview tab renders `tasks.detail.sourceUnavailable` ("source unavailable") and **nothing else** — no snippet, no "View in conversation" link, no attempt to reconstruct or infer content.
- `Task.source_message_snapshot !== null` → the real snapshot text renders, plus a "View in conversation" control (only reachable in this branch, since it's the only branch where the viewer is confirmed to have source access).
- `Task.source_message_id === null` → no source-message section renders at all.

The frontend `Task` type's own doc comment states this dual-null semantics explicitly so a future editor cannot accidentally "fix" it into showing a snapshot the backend deliberately withheld. This was verified against the actual current `TaskResource.php` source (re-read this session, not from memory) before writing the UI.

---

## 13. Task Status UX — Canonical Transitions Only

`frontend/src/features/collaboration/lib/task-meta.ts`'s `allowedTaskStatusTransitions()` is a **verbatim mirror** of `TransitionTaskStatusAction::ALLOWED_TRANSITIONS` (re-read from source this session):

```
todo        -> in_progress, cancelled
in_progress -> done, cancelled
done        -> in_progress   (reopen)
cancelled   -> (terminal, no transitions offered)
```

`TaskDetailDrawer` only ever renders buttons for the CURRENT status's allowed targets, and only when the viewer is the task's creator or assignee (mirroring `TaskPolicy::transitionStatus === view`) — an unrelated viewer sees no transition controls. No UI path can request a transition the backend would reject; the backend's own `CollaborationException::invalidStatusTransition()` remains the authoritative enforcement regardless.

---

## 14. Realtime Client Architecture

`hooks/use-realtime-status.ts` is the entire adapter boundary: it checks for `window.Echo` and returns `'connected'` or `'polling'`. No `laravel-echo`/`pusher-js` package was added to `frontend/package.json`, and the Reverb package remains uninstalled on the backend (Task 3's CTO ruling, untouched — `backend/composer.json`/`composer.lock` were not touched this session, confirmed by `git status`). Today this always resolves to `'polling'`, which is the real, functioning delivery path: every list/thread/task-detail hook (`use-conversations`, `use-messages`, `use-tasks`) branches its TanStack Query `refetchInterval` on this value (5-15s depending on surface), so the app is fully usable with zero realtime infrastructure. When the canonical device activates Reverb, the documented integration path is exactly three steps (install the two packages, initialize `window.Echo` once at bootstrap, done) — every consuming hook already stops polling automatically the moment this hook reports `'connected'`, with no further code changes required anywhere else.

---

## 15. Driver Experience Exposure

One new page, `frontend/src/features/operations/driver-mobile/pages/driver-tasks-page.tsx`, and one new `SECONDARY_NAV` entry in the **existing** `DriverShell` (`frontend/src/components/layout/driver-shell.tsx`) — placed in the "More" sheet, not the four-slot primary bottom bar, so the shell's own documented "four thumb-reach destinations" design is untouched. The page reuses `TaskDetailDrawer` (§10) directly rather than building a parallel driver-specific detail UI — a driver assignee reaches the exact same canonical status transitions as any employee assignee, because `TransitionTaskStatusAction` never branches on participant type (confirmed in its own docblock). `DriverShell` itself — its imports, its four primary slots, its "More" sheet mechanics, its ownership (Operations\Driver Mobile, not Shipping) — was not rebuilt or altered beyond that one array entry and its corresponding route registration.

---

## 16. Navigation & Permission Integration

### 16.1 The `ALWAYS_VISIBLE` Decision

`frontend/src/features/authorization/use-navigation.ts`'s `isModuleVisible()` gates a module in this order: org feature flag → system user → `ALWAYS_VISIBLE` → a Role Template navigation whitelist (if present) → fallback to "holds any permission in the module's domain." Collaboration's backend authorizes every read/write by conversation participation or task ownership — **not** by a `collaboration.*` permission grant, and no Role Template grants one of the five existing tokens today (Task 2's "no automatic role grants" ruling, unchanged). Placing `'collaboration'` in `MODULE_DOMAINS` (the standard pattern every other module uses) would therefore hide the entire module from every non-system, non-whitelisted user by construction — the two narrow tokens that exist (`message_drivers`, `assign_drivers`) are driver-specific capabilities, not a general "can use Collaboration" gate.

**Decision:** `'collaboration'` was added to `ALWAYS_VISIBLE` alongside `'dashboard'`, not to `MODULE_DOMAINS`. Every authenticated user having their own conversations and tasks is the intended product behavior, exactly mirroring why `dashboard` itself is unconditionally visible. No `MODULE_FEATURE` entry was added either (most modules have none; an absent entry means the feature-flag check is a no-op, which is correct here — there is no `collaboration` org feature flag and none was invented).

This is a reasoned, documented product/UX call, not a default — flagged here explicitly, and tested (`frontend/src/features/authorization/authorization.test.ts`, new case: `'collaboration' is always visible, even with zero collaboration.* permissions`).

### 16.2 Route Registration

- `ROUTES.collaborationWorkspace = '/collaboration'` — registered under `EnterpriseAppShell` in `router.ts`.
- `ROUTES.driverTasks = '/driver/tasks'` — registered under `DriverShell` in `router.ts`.
- `ModuleId` union and `ALL_MODULES` (`module-navigation.ts`) gained one `collaboration` entry, `items: []` (Conversations/Tasks are tabs of the one route, not sidebar sub-items — matching how `pos`/`logistics`/`reports` are modeled).

---

## 17. Reverb Dependency — Not Touched

Confirmed via `git status`/`git diff`: `backend/composer.json` and `backend/composer.lock` carry zero changes this session. No Reverb-related backend or frontend package was installed. §14 documents the exact (zero-code-change-elsewhere) integration path for when the canonical device activates it.

---

## 18. Explicit Scope Boundaries Held

- **No Reverb installed**, no `laravel-echo`/`pusher-js` added (§14/§17).
- **No typing indicators, no presence** — not built, not stubbed.
- **No message edit/delete** — `MessageComposer` has no such affordance; the backend itself still exposes no such route (Task 2's `CollaborationMessageTest::test_message_controller_exposes_no_edit_or_delete_capability`, unchanged).
- **No project-management features** — no recurring tasks, no dependencies, no sprints/boards.
- **DriverShell was not rebuilt**, and task ownership was not moved from Operations\Driver Mobile/Shipping (§15).
- **No DEV/integration/deploy/migrate/seed action was performed** — every backend change in §9 is schema-free (no migration needed or written); nothing was run against a database.
- **No browser certification was performed** — see §16 (frontend automated tests) for what actually executed; no manual cross-browser QA pass was done on this device.
- **§18.4, deliberately deferred (not a gap in the required scope):** the `NotificationCenter`'s `SOURCE_BY_SEGMENT`/`NOTIFICATION_SOURCES` map (`frontend/src/features/notifications/types/notification.ts`) was **not** extended with a `'collaboration'` entry this session. This was considered (it would be a small, additive 3-4 line change) and deliberately set aside to keep this task's diff minimal and precisely scoped, consistent with the same "minimal, directly required" discipline the CTO's §9 ruling applied to the identity-lookup work. Collaboration's own two notification classes (`NewMessageNotification`, `MentionedNotification`, `TaskAssignedNotification`, `TaskStatusChangedNotification`, all Task 3/4) already write to the same `notifications` table every other module uses, so they already appear in the notification center today — only their bucket/icon grouping and click-to-navigate would be missing, and click-to-navigate does not exist for any module's notifications today (not a Collaboration-specific gap). Recommended as a small, low-risk follow-up, not required for this task's completion.

---

## 19. Responsive / RTL / Dark Mode / Accessibility

- **RTL**: exclusively logical Tailwind utilities (`ms-`/`me-`/`ps-`/`pe-`/`start-`/`end-`), never `ml-`/`mr-`/`pl-`/`pr-`, matching the established convention (`notification-center.tsx` was the reference file read before writing). Flexbox `justify-start`/`justify-end` for message-bubble alignment (own vs. other) is direction-aware by construction — no `dir`-conditional branching was needed there.
- **Dark mode**: every custom color (status/priority badges) follows the repo's `bg-x-100 text-x-700 dark:bg-x-900/30 dark:text-x-400` pairing convention (`order-status-badge.tsx` was the reference), never a hardcoded light-only color. All chrome (Sheet/Dialog/Card/Button) uses the shared `ui/*` primitives, which are already theme-aware.
- **Responsive**: the Conversations tab's list+thread split collapses to a single pane on mobile via plain conditional Tailwind classes (`hidden md:flex` toggled by "is a conversation selected," not a JS breakpoint hook) — a back button appears only below `md`. `TaskDetailDrawer`/dialogs are Sheet/Dialog-based, which are already responsive by the shared primitive's own design. The driver-facing task page follows the existing `DriverWalletPage` mobile-card convention exactly (`min-h-screen`, sticky header, `rounded-xl border bg-card` cards).
- **Accessibility**: every icon-only button carries an `aria-label` (via the typed i18n selector); the voice recorder's every state (requesting/recording/preview/error) is conveyed in text, not color/icon alone; `aria-current`/`role="tab"` used where semantically correct.

None of the above was verified in an actual browser this session (§1) — this section documents what was built to the standard, not a certified pass.

---

## 20. Frontend Type-Check Verification (Real, Executed)

Unlike the backend (no PHP/Composer/database on this device — unchanged constraint from Tasks 1-4), this device **does** have Node/npm. `npm install` was run in `frontend/` (375 packages, clean install) and `npx tsc -b --noEmit` was run twice:

- **First run** surfaced 3 real type errors in this task's own new code (a stale/non-existent `message.types.text` i18n key reference, and two places where a `MessageType`-typed value indexed `$.message.types` — which only has `image`/`file`/`voice` keys — without narrowing out `text`/`system` first). All three were fixed (extracted into a new shared `useMessageTypeLabel()` hook using only static, compile-time-checked selector calls).
- **Second run**, filtered to `collaboration`/`driver-tasks` paths: **zero matches** — confirmed clean.
- The full unfiltered run also surfaces ~20 pre-existing type errors in unrelated files (`admin/configuration`, `business-accounts`, `engineering`, `hr`, `logistics/dispatch`, `marketing`, `orders/manual-order-form.tsx`, `stock-ledger`) — none touched by this task, left as-is; noted here only for an honest, complete verification record, not fixed (out of this task's scope).

### 20.1 ESLint (Real, Executed)

`npx eslint` was also run against every production file in this task's scope, catching and fixing (all in this task's own new code, none pre-existing):
- A hardcoded English `aria-label="Back"` (moved to the existing `common.actions.back` key) and a hardcoded `toast.error('Download failed')` (moved to `collaboration.errors.generic`).
- Two bogus `eslint-disable-next-line jsx-a11y/...` comments referencing rules this project doesn't actually have configured (removed).
- Three `react-hooks/set-state-in-effect` findings (a real, configured rule) for legitimate "sync state on mount/prop-change" effects — resolved with a justified disable comment on each, mirroring the identical, pre-existing pattern in `frontend/src/features/engineering/components/inbox/TaskDrawer.tsx`.

The full 20-file test suite (§21) was also brought to zero ESLint errors — the three background agents that wrote it had followed this session's own suggested mock patterns (`any`-typed callback parameters) literally, which conflicts with this repo's actual convention of zero `any` anywhere, including tests (confirmed against existing sibling test files); a follow-up pass replaced every `any` with the real component/hook prop types or a precise local type, and added targeted, justified `eslint-disable-next-line ecos-i18n/no-hardcoded-ui-strings` comments (the rule's own documented escape hatch) on test-fixture object fields the rule's heuristic cannot distinguish from real UI copy (e.g. a fake `Task.title` value).

**Final confirmed state** (re-verified directly in this session after every fix-up, not taken from agent self-reports alone): `npx eslint` across every production and test file this task touched or created — **zero errors**. `npx tsc -b --noEmit --force` (full project, forced/uncached) — **zero errors** anywhere in `collaboration`, `driver-tasks`, or `authorization.test.ts` scope (grep-confirmed no matches); the same ~13-20 pre-existing, unrelated findings elsewhere in the repo are the only remaining output. `npx vitest run` across all 20 new files plus the modified `authorization.test.ts`, combined in one process — **157 tests passed across 21 test files**, zero failures, zero cross-file interference.

---

## 21. Frontend Test Inventory

Written and self-verified with real `npx vitest run` executions (not merely written) — every count below is an actual passing-test count from this device, not an estimate:

| Area | Files | Tests |
|---|---|---|
| Conversations (`conversation-list{,-item}`, `message-bubble`, `message-composer`, `voice-recorder`, `new-direct-dialog`, `new-group-dialog`, `conversation-info-panel`, `user-picker`) | 9 | 59 |
| Tasks (`task-status-badge`, `task-priority-badge`, `task-filters`, `task-list{,-item}`, `create-task-dialog`, `task-detail-drawer`) | 7 | 59 |
| Pages/hooks (`use-user-search`, `collaboration-search`, `collaboration-workspace-page`, `driver-tasks-page`) | 4 | 25 |
| **New tests, new files** | **20** | **143** |
| `authorization.test.ts` (pre-existing file; 1 new case added, proving §16.1's `ALWAYS_VISIBLE` decision) | 0 (existing file) | +1 new |

**143 tests are entirely new** (20 new files). **144 is the exact count of new tests this task added** (143 + the 1 new case in the pre-existing `authorization.test.ts`). Running that full combined scope in one process — the 20 new files plus the one modified existing file, so including `authorization.test.ts`'s own pre-existing tests too — passes **157 tests across 21 test files** (this is the real, directly-observed `vitest run` output, re-verified in this session after every fix-up round; the gap between 144 and 157 is simply `authorization.test.ts`'s 13 pre-existing tests, unrelated to this task, also passing in the same run). `npx tsc -b --noEmit` and `npx eslint` were both run against every file in this task's scope; the ~13-20 pre-existing type/lint findings elsewhere in the repo (unrelated features — `admin/configuration`, `business-accounts`, `engineering`, `hr`, `logistics/dispatch`, `marketing`, `orders`, `stock-ledger`) were independently confirmed by multiple agents plus this session's own direct checks to be untouched by, and unrelated to, this task.

A real bug was caught during test-writing (not by inspection): `task-detail-drawer.tsx`'s status-transition button labels were indexed by the wrong end of the transition (target status instead of the `[current][target]` pair), producing mislabeled/duplicated button text — e.g. from `todo` both buttons showed "complete"/"reopen" text instead of "start"/"cancel", though the actual status value sent to the backend on click was always correct. Fixed in source (§13) immediately upon discovery; the existing 20 tests for that component were re-run afterward and passed unchanged, since they had already been written against the real transition-target behavior rather than the buggy label text.

---

## 22. First-Device Verification Package (20 items for the canonical/first device)

1. `npm install && npm run build` in `frontend/` — confirm a clean production build (this device confirmed `tsc -b --noEmit` clean; a full `vite build` was not run).
2. `npm run test` — re-run the full suite in a real CI/dev environment and confirm the same pass count as §21.
3. `npm run lint` — this device did not run ESLint against the new files; run it and fix any style-only findings.
4. `php artisan migrate` — confirm all 14 Collaboration migrations apply cleanly to a fresh/existing database (none are new in Task 5; re-verify Tasks 2-4's migrations one final time in this batch's closure).
5. `php artisan db:seed` (or confirm the direct-insert migrations `100006`/`100013` alone) — confirm exactly 5 `collaboration.*` permission rows exist.
6. Manually grant a test role `collaboration.conversations.create` + `collaboration.groups.create` and confirm a real employee can start a direct conversation and create a group.
7. Manually link a `Driver.user_id` to a real user; confirm that user is **invisible** in `GET /collaboration/search/users` to an actor without `collaboration.conversations.message_drivers`, and **visible** (with `is_driver:true`) to one who holds it and is in-scope.
8. Send a text message, an image, a file, and a voice message end-to-end through the real UI; confirm each renders correctly and downloads/plays back correctly.
9. Confirm a reply renders the correct quoted snippet, and a mention renders the correct participant name.
10. Create a task from a message; confirm the task's "source message" section shows the real text to the creator.
11. As a SECOND user who is not a participant of that source conversation but IS the task's assignee, confirm the task shows "source unavailable" — never the message text.
12. Exercise every status transition (`todo→in_progress→done→in_progress→cancelled` is not reachable; confirm `done→in_progress` reopen works and `cancelled` offers no further transitions).
13. Confirm only the creator sees "Reassign," and reassigning updates the assignee everywhere (list, detail, drawer).
14. Add a comment and an attachment to a task; confirm both appear and the attachment downloads correctly.
15. Attach an Order/Distribution Group/Trip/Driver operational context link to both a conversation and a task; confirm both render.
16. Log in as a driver-linked user; confirm the "Tasks" entry appears in DriverShell's "More" sheet and opens the assigned-tasks list; confirm status transitions work identically to an employee assignee.
17. Confirm the Collaboration module appears in the sidebar for a freshly-created role with **zero** `collaboration.*` permissions (proving the `ALWAYS_VISIBLE` decision, §16.1).
18. With the browser's dev tools set to a narrow (mobile) viewport, confirm the Conversations tab collapses to list-only / thread-only with a working back button.
19. Toggle dark mode and RTL (Arabic) and visually confirm every badge, dialog, and the voice recorder remain legible and correctly mirrored.
20. When Reverb is actually activated on this device (out of this task's scope per §14/§17): install `laravel-echo`+`pusher-js`, initialize `window.Echo` once, and confirm every list/thread stops polling and starts receiving pushed updates with zero other code changes.

---

## 23. Known Gaps / Follow-Up Recommendations

1. **NotificationCenter grouping** (§18.4) — small, additive, deliberately deferred.
2. **Older-message pagination** — the message thread shows the most recent 50 messages (the existing `GetConversationMessagesAction` default); no "load older history" UI was built. `before_message_id`/`after_message_id` are already supported server-side (Task 2/3), so this is a frontend-only addition if a future task wants it — flagged as a V1 simplification, not a defect, consistent with this batch's lean-V1 posture (no typing/presence, no edit/delete).
3. **`Driver.user_id` remains unexposed by `DriverResource`** — not needed for anything built in this task (§9.5), but worth knowing if a future task wants a Driver-management UI to show "this driver's Collaboration identity."
4. **HR/driver population differences are irrelevant to the identity-lookup endpoint** — `SearchAddressableUsersAction` queries `App\Models\User` directly (not `hr_employees`), so it correctly covers every user regardless of whether they have an HR profile; this was a deliberate design choice per the CTO ruling (§9.2), documented here so a future reader doesn't mistake it for an oversight.

---

## 24. Git Policy Followed

One implementation commit covering all of §4-§20 (backend identity-lookup addition + tests, frontend feature + tests, navigation/router wiring), with this report included in the same commit so no post-commit SHA fill-in or second documentation-only commit is needed — satisfying this task's own instruction that the final working tree must end up clean without an ad-hoc follow-up pattern. Author/committer via the previously CTO-authorized one-off env-var identity (`Osama Fayez <eng_osamafayez@hotmail.com>`); `git config` was not touched, local or global, at any point.

---

## 25. Expected Post-Commit Git State

Immediately after the one commit described in §3: `git status` reports a clean working tree (nothing to commit); `HEAD` is exactly one commit ahead of `dc52ce8e` (Task 4's report-finalization commit) on `task/chat-workstream`; every prior commit in this batch (`dc52ce8e`, `97d08b48`, `bfb6a817`, `a0f11948`, `3578fbd2`, `6a3a0044`) remains unamended and intact. No push, merge, or DEV/integration action was performed at any point in this task.

---

## 26. Closing

This is the final task of the Internal Collaboration & Tasks batch (5 of 5). No integration, DEV rollout, or further Collaboration work should begin automatically from this report — the first-device verification package (§22) is the gating checklist for whoever picks this up next.
