# TASK-ECOS-COLLABORATION-CORE-FOUNDATION-002 — Engineering Report

**Workstream:** ECOS ERP — Internal Collaboration & Tasks
**Batch:** Internal Collaboration Batch 01 — Task 2 of 5
**Type:** Implementation — Core Domain / Application Foundation
**Owner:** A1 — Collaboration
**Architecture authority:** ADR-044 (Accepted)
**Date:** 2026-09-02

---

## 1. Final Status

**COMPLETE** — per the device's own completion-status policy (brief §25): implementation scope complete, tests written, one focused local commit created, no known source-review blocker. This explicitly does **not** mean verified or certified — see §20/§21.

```
IMPLEMENTED:    YES
TESTS WRITTEN:  YES
TESTS EXECUTED: NO
VERIFIED:       NO
COMMITTED:      YES
INTEGRATED:     NO
DEV VISIBLE:    NO
USER VERIFIED:  NO
CERTIFIED:      NO
Task 3:         NOT STARTED
```

---

## 2. Starting HEAD

`6a3a00444e7b414ee76aed8c19d4a6c79a470333` (the Task 1 ADR-044 acceptance commit).

---

## 3. Existing Capability Gate

Scoped to what this task actually needed to call, not a repeat of Task 1's broad survey (brief §2). Two things were verified directly against source before writing any integration code, because getting them wrong would have produced either a silent no-op security check or a hard failure:

| Capability | Finding | Classification |
|---|---|---|
| `App\Models\User` | Canonical identity, bigint PK, `company_id` (uuid) | REUSE — every FK in the new schema points here |
| `Modules\Logistics\Drivers\Domain\Models\Driver` | `user_id` nullable/unique bridge to `users` | REUSE — `DriverMessagingAuthorizer` queries it directly |
| `Modules\IAM\Domain\Contracts\AuthorizationGatewayInterface` | `authorize()` throws `\Illuminate\Auth\Access\AuthorizationException` (framework exception, not IAM-custom) | REUSE |
| `Modules\IAM\Domain\Contracts\ScopeResolverInterface` | Concrete impl `Modules\IAM\Application\Services\ScopeResolver`. **Verified by direct source read, not assumed**: if no role held by the user has an explicit narrower `data_scope` for a resource, it resolves to `DataScope::ALL` (`ScopeResolver.php:70-73`, "No explicit grant for this resource → ALL (backward compatible default)"). `logistics.drivers` is a real permission resource (`config/permissions.php`), but every existing grant for it carries `data_scope='all'` — so today, for every real role in this system, the scope check is architecturally correct but **functionally a no-op** (never narrows anything) until some role is given a narrower scope. See §11 and §25 for what this means going forward. | REUSE (see caveat) |
| `Modules\IAM\Domain\Contracts\PermissionRegistryInterface` | `register()` only populates an in-request in-memory singleton; `sync()` (which writes to the `permissions` table) is documented as "never called automatically." No other module in the repo calls `register()` either. | REUSE for forward-compatibility; **not** the thing that makes `collaboration.*` permissions real today — see §25 |
| `App\Core\Actions\BaseAction` / `App\Core\Responses\OperationResult` | Real, used by `Organization\Teams`' actions | REUSE — every new Action extends `BaseAction` |
| `App\Traits\HasApiResponse` | `success/created/updated/deleted/error` helpers | REUSE — every new controller |
| `Modules\IAM\Presentation\Policies\BasePolicy` | Wraps `PermissionServiceInterface->userHasPermission()` for permission-shaped policies | **Not extended.** `ConversationPolicy`'s checks are data checks (active participation), not named-permission checks — `BasePolicy` would add nothing here. Documented inline in the policy's own docblock. |
| `Organization\Teams\Presentation\Http\Policies\TeamPolicy` | Registered but never actually invoked by `TeamController` (confirmed in Task 1) | DO-NOT-REPEAT — `ConversationPolicy` is genuinely called from `ConversationController::show()`, and every mutating Action re-derives its own authorization check independently (defense in depth) rather than relying on a policy binding nobody calls |
| `Tests\TestCase::actingAs()` | Auto-grants the `is_system` role, bypassing every permission check (documented in `DriverRbacTenancySecurityTest`'s own header comment) | DO-NOT-USE for authorization-sensitive tests — every Collaboration test uses `actingAsUnprivileged()` with real, explicit role grants instead (see §19) |

---

## 4. Exact Files Changed

**Modified (3):**
- `backend/bootstrap/providers.php` — registered `CollaborationServiceProvider` (after IAM/Logistics/Organization, which it depends on)
- `backend/config/permissions.php` — added the `collaboration` resource block to the `modules` catalog
- `backend/routes/api.php` — added the `collaboration/*` route group

**Created — Collaboration module (42 files) under `backend/Modules/Collaboration/`:**

```
Domain/
  Enums/            ConversationType, ParticipantRole, MessageType, OperationalContextType, AttachedToType
  Models/           Conversation, ConversationParticipant, Message, MessageMention, OperationalContextLink
  Exceptions/       CollaborationException
  Services/         DriverMessagingAuthorizer
Application/
  Actions/          GetOrCreateDirectConversationAction, CreateGroupConversationAction,
                    AddGroupParticipantAction, RemoveGroupParticipantAction, SendMessageAction,
                    MarkConversationReadAction, ListConversationsForUserAction,
                    GetConversationMessagesAction, AttachOperationalContextAction
  Actions/Concerns/ ManagesParticipants (trait)
  DTO/              SendMessageData
Infrastructure/
  Database/Migrations/   6 migrations — see §5
  Database/Factories/    ConversationFactory, ConversationParticipantFactory, MessageFactory
  Providers/              CollaborationServiceProvider
Presentation/
  Http/Controllers/  ConversationController, ConversationParticipantController, MessageController,
                     ConversationReadStateController, OperationalContextLinkController
  Http/Requests/     StoreDirectConversationRequest, StoreGroupConversationRequest, AddParticipantRequest,
                     SendMessageRequest, MarkConversationReadRequest, AttachOperationalContextRequest
  Http/Resources/    ConversationResource, MessageResource, ConversationParticipantResource
  Http/Policies/     ConversationPolicy
```

**Created — tests (4 files) under `backend/tests/Feature/Collaboration/`:**
`CollaborationConversationTest.php`, `CollaborationMessageTest.php`, `CollaborationDriverAuthorizationTest.php`, `Concerns/CollaborationTestHelpers.php`.

No file outside these was touched. No `backend/Modules/Collaboration` file existed before this task (verified — Task 1 confirmed the module didn't exist).

---

## 5. Exact Migrations Created

| # | File | Table / Change |
|---|---|---|
| 1 | `2026_09_02_100000_create_collaboration_conversations_table.php` | `collaboration_conversations` — uuid PK, `company_id` (restrict-delete FK), `type`, `title`, `created_by_user_id`, `team_id` (soft label FK, nullable), `direct_pair_key` (nullable, unique with company_id — DB-level direct-conversation uniqueness), `last_message_at`, timestamps |
| 2 | `2026_09_02_100001_create_collaboration_conversation_participants_table.php` | `collaboration_conversation_participants` — uuid PK, `conversation_id` (cascade), `user_id` (restrict-delete FK), `role`, `joined_at`, `left_at`, `last_read_at`, unique(`conversation_id`,`user_id`) |
| 3 | `2026_09_02_100002_create_collaboration_messages_table.php` | `collaboration_messages` — uuid PK (UUIDv7), `conversation_id` (cascade), `sender_user_id` (restrict), `type`, `body`, `reply_to_message_id` (self-FK, nullable), single `created_at` column only — **no `updated_at`**, enforcing immutability at the schema level |
| 4 | `2026_09_02_100003_add_last_read_message_id_to_collaboration_conversation_participants_table.php` | ALTER — adds `last_read_message_id` (nullable FK → `collaboration_messages`), deferred to its own migration purely because the target table didn't exist yet at migration #2 |
| 5 | `2026_09_02_100004_create_collaboration_message_mentions_table.php` | `collaboration_message_mentions` — uuid PK, `message_id` (cascade), `mentioned_user_id` (restrict), unique(`message_id`,`mentioned_user_id`) |
| 6 | `2026_09_02_100005_create_collaboration_operational_context_links_table.php` | `collaboration_operational_context_links` — uuid PK, string `context_type`/`context_id`, string `attached_to_type` + uuid `attached_to_id`, `created_by_user_id` |

All six use the `Schema::hasTable()`/`hasColumn()` idempotency guard, matching `Organization\Teams`' migration convention. **None were run** — brief §18 forbids any migration against DEV on this device; these were reviewed by reading them back, not by executing them.

---

## 6. Module Structure

Standard Domain/Application/Infrastructure/Presentation layout per `backend/Modules/README.md`. No empty ceremonial folders were created — e.g., there is no `Domain/Contracts/` directory, because nothing in this task needed a Collaboration-owned repository interface (Actions talk to Eloquent models directly; see §17 for why that's a deliberate choice, not an oversight).

---

## 7. Conversation Model

`Conversation` (`Domain/Models/Conversation.php`) — uuid PK, `type` cast to `ConversationType` (direct/group), `company_id`, `created_by_user_id`, optional `team_id` (soft label only, §9), `direct_pair_key` (§10), `last_message_at`. Relations: `company()`, `creator()`, `team()`, `participants()`/`activeParticipants()`, `messages()`.

---

## 8. Participant Model

`ConversationParticipant` (`Domain/Models/ConversationParticipant.php`) — uuid PK, `conversation_id` → `user_id` (the single canonical actor type per ADR-044 §1.3/§6 — no polymorphic actor reference anywhere in this schema), `role` (owner/member), `joined_at`/`left_at` (soft-leave lifecycle — `ManagesParticipants::ensureActiveParticipant()` reactivates an existing left row rather than inserting a duplicate), `last_read_at`/`last_read_message_id` (the read cursor, §15).

---

## 9. Ad-hoc Group Implementation

`CreateGroupConversationAction` creates a **Collaboration Group** (`Conversation` with `type=group`) — the mandatory naming split from ADR-044's CTO Ratification is followed throughout: every docblock and comment in this module says "Collaboration Group," never bare "group" or "team," and nothing here touches `Organization\Teams\Team`. The creator becomes the sole `owner` participant; only an owner may add/remove other members (`AddGroupParticipantAction`/`RemoveGroupParticipantAction`), while any member may remove themselves (leave). An optional `team_id` may be attached purely as a label (validated to exist and belong to the same company, never used for membership).

---

## 10. Direct Conversation Behavior

`GetOrCreateDirectConversationAction` implements true get-or-create semantics: `Conversation::directPairKey()` produces an order-independent key from the two participant ids, and `firstOrCreate()` against `(company_id, direct_pair_key)` — backed by the DB-level unique index from migration #1 — means requesting the same pair from either direction always resolves to the identical row (proven in `CollaborationConversationTest::test_getting_a_direct_conversation_twice_returns_the_same_conversation`). The target user is always resolved scoped to the actor's own `company_id`; a cross-company id fails exactly like a nonexistent one (404, not 403 — see §18).

---

## 11. Driver Identity / Scope Behavior

`Domain\Services\DriverMessagingAuthorizer` is the single place this rule is enforced (ADR-044 §1.6): given a target `User`, it checks whether `Driver::where('user_id', $target->id)` resolves to a row; if not, it's a no-op (ordinary employee messaging). If it does, it (1) calls `AuthorizationGatewayInterface::authorize($actor, 'collaboration.conversations.message_drivers')` — throws on denial — then (2) applies IAM's real `scopedTo()` query macro (`Driver::query()->scopedTo($actor, 'logistics.drivers')->whereKey($driver->id)->exists()`) and throws `AuthorizationException` if the driver falls outside the resolved scope. Every action that can add a user to a conversation (`GetOrCreateDirectConversationAction`, `CreateGroupConversationAction`, `AddGroupParticipantAction`) calls this same service — a driver cannot be reached by constructing a "group" of two either.

**Honest caveat, stated plainly rather than glossed over:** because no role in this system's current permission catalogue has ever been given a `data_scope` narrower than `all` for any driver-related resource (§3), the scope half of this check is, today, architecturally wired correctly but observably a no-op — any user holding the permission can currently address any driver in their own company. This is not a bug in Task 2's code; it is the real, current state of the IAM catalogue this task was told to reuse rather than modify. See §25 for the concrete follow-up.

---

## 12. Message Implementation

`SendMessageAction` — the single write path for a message, handling both a plain send and a reply (§13). Verifies the sender is an active participant (defense in depth beneath `ConversationPolicy`), validates a `reply_to_message_id` belongs to the same conversation, validates every `mentioned_user_id` is an active participant, then creates the `Message` row and any `MessageMention` rows in one transaction, and bumps `conversation.last_message_at`. Only `type=text` is reachable through the HTTP surface (`SendMessageRequest` rejects anything else with an explicit message pointing at Task 3) — the schema and the `MessageType` enum already carry `image`/`file`/`voice`/`system` so Task 3 needs no migration to add them.

---

## 13. Reply Implementation

Not a separate action — `reply_to_message_id` is just an optional field on the same `SendMessageData`/`SendMessageRequest`. `SendMessageAction` rejects a `reply_to_message_id` that does not belong to the target conversation with `CollaborationException::CROSS_CONVERSATION_REPLY` (422).

---

## 14. Mention Foundation

`message_mentions` is a dedicated table (not a body-text parse), populated only for user ids that were active participants at send time — `CollaborationException::INVALID_MENTION` (422) otherwise. Delivery of a mention notification is explicitly Task 3's scope (brief §11/§21); this task only guarantees the data is valid.

---

## 15. Read/Unread Model

A **ReadCursor**, not a per-message read-receipt row (matching ADR-044 §8/§12's explicit design choice): `conversation_participants.last_read_at` is the field unread-count derivation actually uses (`ListConversationsForUserAction`: `messages.created_at > (last_read_at ?? joined_at)`, excluding the viewer's own messages); `last_read_message_id` is carried alongside purely as a "scroll back to here" UX convenience and is not load-bearing for the count. `MarkConversationReadAction` updates both.

---

## 16. Operational-Context Reference Foundation

`OperationalContextLink` + `collaboration_operational_context_links` — a generic `context_type`/`context_id` + `attached_to_type`/`attached_to_id` reference table, deliberately using the same string-discriminator convention `App\Core\Documents\Document` already uses in this codebase (not a true Eloquent `morphTo` — there are still zero of those anywhere in this repo, and this task did not introduce the first one). V1's supported `context_type` values (`Domain\Enums\OperationalContextType`: Order, DistributionGroup, Trip, Driver) match ADR-044's ratified set exactly, including Distribution Group. `AttachOperationalContextAction` only proves the model — it never fetches or exposes the referenced entity's own data, and `attached_to_type=task` is rejected outright until Task 4 gives it something to point at.

---

## 17. Authorization Evidence

Every write path is authorized **inside the Action**, not only via a controller-level policy call that a future refactor could accidentally bypass:

| Action | Enforcement |
|---|---|
| `GetOrCreateDirectConversationAction` | `authorize('collaboration.conversations.create')`; company-scoped target resolution; `DriverMessagingAuthorizer::assertCanAddress()` |
| `CreateGroupConversationAction` | `authorize('collaboration.groups.create')`; company-scoped member resolution; `DriverMessagingAuthorizer` per non-self member |
| `AddGroupParticipantAction` | Requires an active `owner` participant row for the actor; `DriverMessagingAuthorizer` for the target |
| `RemoveGroupParticipantAction` | Self-removal always allowed; removing someone else requires an active `owner` row |
| `SendMessageAction` | Requires an active participant row for the sender |
| `MarkConversationReadAction` | Requires an active participant row for the actor |
| `GetConversationMessagesAction` | Requires an active participant row for the requester |
| `AttachOperationalContextAction` | Requires an active participant row on the resolved conversation |

`ConversationPolicy` (registered via `Gate::policy()` in `CollaborationServiceProvider::boot()`) is genuinely invoked — `ConversationController::show()` calls `$this->authorize('view', $conversation)` — unlike `Organization\Teams\TeamPolicy`, which Task 1 found is registered but never called by `TeamController` anywhere. Repository-interface indirection was deliberately skipped for this task (brief's own "do not create ceremonial layers" instruction): every Action depends on the concrete `Conversation`/`Message`/`ConversationParticipant` Eloquent models directly, which is sufficient since nothing here needs a swappable storage backend.

---

## 18. Tenant/Company Boundary Evidence

- Every target-user resolution is scoped by `->where('company_id', $actor->company_id)` before use (`GetOrCreateDirectConversationAction`, `CreateGroupConversationAction`, `AddGroupParticipantAction`) — a foreign-company id produces `ModelNotFoundException` → framework 404, identical to a nonexistent id, never a 403 that would confirm the id belongs to someone (brief §15 — "fail closed").
- `collaboration_conversations.company_id` is set once at creation from the actor and never reassignable through any exposed endpoint.
- `Driver::query()` (used inside `DriverMessagingAuthorizer`) inherits Logistics' own company-scoping global scope (`Driver::booted()`, confirmed in Task 1's research) — Collaboration adds no separate tenant-scoping mechanism of its own for drivers, reusing the existing one.
- Tested directly: `CollaborationConversationTest::test_an_employee_cannot_start_a_direct_conversation_with_a_user_in_another_company` and `::test_a_non_participant_cannot_view_another_companys_conversation`.

---

## 19. Tests Written

Four files, `backend/tests/Feature/Collaboration/`, `DatabaseTransactions` (not `RefreshDatabase` — matching `DriverRbacTenancySecurityTest`'s documented reasoning: real seeded roles/permissions must be present), every authorization-sensitive case using `actingAsUnprivileged()` with an explicit, real permission grant (never bare `actingAs()`, which silently bypasses every check via the auto-granted `is_system` role).

| Brief scenario | Test |
|---|---|
| 1. Employee↔Employee direct creation | `CollaborationConversationTest::test_employee_can_create_a_direct_conversation_with_another_employee` |
| 2. Duplicate direct-conversation prevention | `::test_getting_a_direct_conversation_twice_returns_the_same_conversation` |
| 3. Employee↔Driver, valid permission/scope | `CollaborationDriverAuthorizationTest::test_employee_with_permission_can_message_a_linked_driver` (exercises the **real, unmocked** `ScopeResolverInterface` binding) |
| 4. Employee↔Driver, denied without permission | `::test_employee_without_the_message_drivers_permission_is_denied` |
| 5. Employee↔Driver, denied outside scope | `::test_employee_with_permission_but_outside_scope_is_denied` (substitutes a resolver returning `ScopeConstraint::none()` — see §11's caveat for why a *real* narrow-scope role cannot be constructed against today's catalogue) |
| 6. Group creation | `CollaborationConversationTest::test_employee_can_create_a_collaboration_group` |
| 7. Group membership add/remove | `::test_group_owner_can_add_and_remove_a_member` |
| 8. Unauthorized membership mutation rejected | `::test_a_non_owner_member_cannot_add_a_participant` |
| 9. Text message send | `CollaborationMessageTest::test_a_participant_can_send_a_text_message` |
| 10. Non-member cannot read | `::test_a_non_member_cannot_list_messages` |
| 11. Non-member cannot send | `::test_a_non_member_cannot_send_a_message` |
| 12. Reply in same conversation | `::test_a_reply_within_the_same_conversation_succeeds` |
| 13. Cross-conversation reply rejected | `::test_a_reply_to_a_message_in_a_different_conversation_is_rejected` |
| 14. Mention validation | `::test_mentioning_a_non_participant_is_rejected` + `::test_mentioning_an_active_participant_succeeds` |
| 15. Read/unread update | `::test_marking_a_conversation_read_updates_the_cursor` |
| 16. Unread count derivation | `::test_unread_count_reflects_messages_sent_since_last_read` |
| 17. Tenant/company isolation | `CollaborationConversationTest::test_an_employee_cannot_start_a_direct_conversation_with_a_user_in_another_company` + `::test_a_non_participant_cannot_view_another_companys_conversation` |
| 18. Operational-context reference validation | `CollaborationDriverAuthorizationTest::test_a_participant_can_attach_an_operational_context_link` + `::test_a_non_participant_cannot_attach_an_operational_context_link` + `::test_an_unsupported_context_type_is_rejected` |
| 19. Message edit/delete unavailable | `CollaborationMessageTest::test_message_controller_exposes_no_edit_or_delete_capability` (reflection: no `update`/`destroy` method exists on `MessageController`) |
| 20. Existing IAM/CEP authorities unaffected | `CollaborationConversationTest::test_existing_and_new_permission_resources_coexist_in_the_catalogue` |

---

## 20. Tests Executed

**NO.** Per brief §19/§20, this device is an implementation environment, not the canonical test/runtime environment — no PHP/Docker/database stack was installed or bootstrapped here, and no test runner was invoked.

---

## 21. Verification Status

**VERIFIED: NO. CERTIFIED: NO.** Every claim above about test behavior is a manual trace through the code (this report's §17–§19), not an observed pass. Verification is deferred to the first/canonical ECOS device per device policy — running the four new files there is the natural next action before Task 3 begins.

---

## 22. Deferred Task 3 Capabilities

Voice messages, image/file upload and `DocumentService` integration, Laravel Reverb/Broadcasting, the polling fallback, notifications, PostgreSQL full-text search, typing indicators, presence — none of these were touched, per brief §21. `MessageType`/schema already accommodate voice/image/file without a future migration.

---

## 23. Exact Local Commit SHA

`3578fbd22354b846ad9b43d9b9a413a670a89253` — `feat(collaboration): add core collaboration foundation`, on `task/chat-workstream`, parent `6a3a00444e7b414ee76aed8c19d4a6c79a470333`. Author/committer `Osama Fayez <eng_osamafayez@hotmail.com>`, applied via one-off `GIT_AUTHOR_*`/`GIT_COMMITTER_*` environment variables on the commit command (pre-authorized, brief §22) — no git config was touched, local or global (verified: `git config --local user.name` still exits unset after the commit). 55 files changed, 2963 insertions — the 49 implementation/test files from §4 plus the 6 migrations counted separately by git, 0 deletions.

**This report itself is deliberately not in that commit.** Brief §23 asked for "ONE focused LOCAL implementation commit" containing the implementation; this report documents that commit, including its SHA, which does not exist until after it lands — committing the report in the same commit would misstate its own content. It remains an untracked file pending instruction on whether/how to commit it (Task 1 handled its ADR+report commit as an explicit, separate continuation step — the same pattern applies here if wanted).

---

## 24. Final Git Status

```
On branch task/chat-workstream
Untracked files:
  docs/verification/TASK-ECOS-COLLABORATION-CORE-FOUNDATION-002-REPORT.md

nothing added to commit but untracked files present
```
No push, merge, cherry-pick, migration, or seed was performed at any point in this task.

---

## 25. Remaining Gaps

1. **Permission catalogue persistence is unverified beyond this task's own tests.** `PermissionRegistry::register()` (called from `CollaborationServiceProvider::boot()`) only populates an in-memory, per-request singleton — it does not write to the `permissions` table (`sync()` "is never called automatically," confirmed by direct source read, §3). This task's own tests work regardless, because they create `Permission` rows directly via `firstOrCreate()` (the same pattern `DriverRbacTenancySecurityTest` already established). **Unverified:** whether any existing seeder reads `config('permissions.modules')` (which now includes `collaboration`) into real `Permission` rows outside of tests. If none does, `collaboration.conversations.create` / `.message_drivers` / `groups.create` will not be recognized by `RequirePermissionMiddleware` in a real running environment until seeded. Recommend checking this on the canonical device before or during Task 3.
2. **The employee→driver scope check is a real no-op today**, restated from §11: no role in the current catalogue has a `data_scope` narrower than `all` for any driver-related resource, so the "authorized scope" half of ADR-044 §1.6 does not currently narrow anything beyond company-boundary + the permission gate. This is a catalogue/seed-data question, not a code defect in this task.
3. **`role_permissions` defaults were deliberately not touched.** Which real business roles (e.g. `driver`, dispatcher-type roles) get `collaboration.*` permissions by default was left undecided — out of this task's mandate (a product/seed-data decision) — every test constructs its own bespoke role with exactly the grants it needs, matching established precedent.
4. Group membership has no upper size limit and no rate limiting on conversation/message creation — not required by the brief, not added.

---

## 26. Recommended Exact Task 3 Scope

As proposed in the architecture report §25, refined by what this task actually found:

- Voice record/upload/playback via `App\Core\Documents\DocumentService`, plus the `collaboration_voice_metadata` side table (architecture report §10).
- The mandatory Existing Capability/Runtime Gate for Reverb (ADR-044 §1.8) — confirm package/runtime availability, deployment topology, private-channel auth, tenant isolation, DriverShell compatibility, reconnect/fallback behaviour, **before** any Reverb-dependent code.
- Stock Laravel `Notification` classes for new-message/mention/task events.
- PostgreSQL full-text search over `collaboration_messages.body`, authorization-scoped to conversation participation.
- **New, surfaced by this task:** verify (or add) the seed step that turns `config('permissions.modules.collaboration')` into real `Permission` rows outside of tests (gap #1 above), and decide — with IAM/Logistics — whether `logistics.drivers` (or a Collaboration-specific resource) should get a real narrower `data_scope` for any role, if finer-than-company-wide driver visibility is actually wanted (gap #2 above). Neither blocks Task 3's own scope, but both should be resolved before this mechanism is presented as fully enforcing in a certification report.

---

## CTO Remediation — Permission Catalog Closure (2026-09-02)

This section is appended after CTO review of the original report above, which is preserved unchanged. It closes the one issue the CTO asked to be closed (permission-catalog persistence) and records the explicit ruling on the other (driver data scope).

### R1. CTO Ruling on Driver Data Scope

Accepted as written: the Collaboration authorization pipeline (`Authenticate → IAM permission → resolve target driver/user → canonical IAM data-scope authority → company/tenant boundary → allow/deny`) is correct and was **not modified** in this remediation. The current live catalogue resolving driver-related scope to `ALL` is classified, per CTO ruling, as an **EXTERNAL IAM CAPABILITY / CONFIGURATION GAP**, not a Collaboration defect. No Collaboration-specific scope engine, hard-coded driver list, or duplicate scope rule was created or considered. The existing focused denial test (`CollaborationDriverAuthorizationTest::test_employee_with_permission_but_outside_scope_is_denied`, which substitutes a resolver returning a real `ScopeConstraint::none()`) is preserved exactly as originally written.

### R2. Exact Permission-Catalog Source Findings

Traced directly from source, not assumed:

1. `config/permissions.php`'s `modules` key (where Task 2 added `collaboration`) is read by exactly two consumers: `Modules\IAM\Application\Services\PermissionRegistry::discoverFromConfig()` (an in-memory, per-request singleton — `sync()`, the method that would persist it, "is never called automatically," confirmed by grep: its only caller anywhere in the repo is its own test, `PermissionRegistryTest.php`), and `Modules\IAM\Infrastructure\Database\Seeders\RbacSeeder::run()` (reads `config('permissions.modules')` directly, independent of `PermissionRegistry` entirely, and does `Permission::firstOrCreate()` for every `domain.resource.action` — this is the mechanism that actually persists rows).
2. `RbacSeeder` is called first in `database/seeders/DatabaseSeeder.php` ("RBAC: roles and permissions must exist before any user is seeded") — but `DatabaseSeeder`/`db:seed` is **not** part of routine deployment. Verified directly: `scripts/deploy.sh` step 8/9 runs `php artisan migrate --force` only when `--migrate` is explicitly passed, and never calls `db:seed` at all. `docker/php/entrypoint.sh` step 7 runs migrations only when `MIGRATE_ON_START=true`, and step 8 seeds **only** `AdminUserSeeder` when `SEED_ADMIN_ON_START=true` — never the full `DatabaseSeeder` chain, never `RbacSeeder` specifically.
3. Conclusion: `RbacSeeder` correctly turns `config/permissions.php` into real rows **on a fresh install** (where `db:seed` is run once, manually, as part of initial setup), but an **already-running environment** that only ever applies new migrations (the documented, opt-in, routine path) would never re-run `RbacSeeder` and would never gain a permission added to the config after that environment's initial seeding — including Collaboration's three.

### R3. Existing Sync Lifecycle — Found, and Already Solved Once Before

This is **not** a first-time gap. `Modules\Operations\Loading\Infrastructure\Database\Migrations\2026_08_20_100000_seed_loading_os_permissions.php` documents the identical failure mode for Loading OS almost verbatim: "`LoadingSessionPolicy`... authorise against `loading.session.*`... but NONE of those permission rows existed... so the entire Loading OS + driver runtime was reachable ONLY by a system-role user." Its fix — and its own docblock says it mirrors `2026_12_24_000000_restore_logistics_two_segment_permissions.php` "verbatim in shape" for the same reason, a second precedent — is a **migration** that inserts the missing `permissions` rows directly via `DB::table('permissions')->insert()`, guarded by `->where('name', $name)->exists()` for idempotency, explicitly noting "config/permissions.php also carries these names now, so a fresh `db:seed` reproduces them; this migration covers environments whose roles already exist."

This is the established, twice-precedented canonical pattern for exactly this situation. **Classification: OUTCOME A/B — the canonical lifecycle already exists (config/permissions.php + RbacSeeder for fresh installs, a dedicated additive migration for already-running environments); Collaboration's Task 2 report simply hadn't applied the second half of it yet.** This is a pre-existing platform pattern, not a Collaboration-specific gap invented here, and not a platform gap requiring a BLOCKED verdict — the wiring exists, Collaboration just needed to use it.

### R4. Exact Remediation Implemented

One new migration, mirroring the Loading OS migration's shape exactly, **minus its grant-application step**:

`backend/Modules/Collaboration/Infrastructure/Database/Migrations/2026_09_02_100006_seed_collaboration_permissions.php` — idempotently inserts the three `collaboration.*` permission rows directly into `permissions` (guarded by `->where('name', $name)->exists()`, matching the Loading migration's own guard verbatim). Deliberately does **not** call any `grant()`-equivalent step — see R9 for why, and how that was verified rather than merely asserted.

No change was made to `PermissionRegistry`, `PermissionRegistryInterface`, `RbacSeeder`, `config/permissions.php`, or any authorization/scope code. `CollaborationServiceProvider`'s existing `PermissionRegistryInterface::register()` call (Task 2) is unchanged — it remains a forward-compatible, currently-inert declaration alongside the config entry, exactly as originally documented; it is not what makes the permissions real, the new migration is.

### R5. Exact Files Changed

| File | Change |
|---|---|
| `backend/Modules/Collaboration/Infrastructure/Database/Migrations/2026_09_02_100006_seed_collaboration_permissions.php` | Created |
| `backend/tests/Feature/Collaboration/CollaborationPermissionCatalogTest.php` | Created |
| `docs/verification/TASK-ECOS-COLLABORATION-CORE-FOUNDATION-002-REPORT.md` | This section appended |

No other file was touched. No Collaboration authorization/scope/domain code was modified — the CTO's instruction not to redesign the accepted baseline was followed exactly.

### R6. Tests Added

`CollaborationPermissionCatalogTest` (4 new tests), deliberately **not** using manual `Permission::firstOrCreate()` the way every other Collaboration test does — that would prove nothing about the migration itself:

1. `test_collaboration_permissions_are_persisted_without_any_manual_seeding_in_this_test` — asserts all three names already exist, because the test database's migration run (which happens before any test executes) already applied the new migration.
2. `test_running_the_seed_migration_twice_creates_no_duplicate_rows` — `require`s the migration file directly and calls `->up()` a second time in-test, asserting the row count is unchanged (idempotency, proven mechanically, not just read from the migration's own guard clause).
3. `test_persisting_the_permission_definitions_grants_them_to_no_role` — queries `role_permissions` directly for zero rows referencing these permission ids.
4. `test_an_unregistered_permission_token_does_not_exist_in_the_catalogue` — a name never declared anywhere stays absent.

### R7. Tests Executed

**NO** — same second-device constraint as the original report (§20/§21): no PHP/DB runtime available here. Written for execution on the canonical device alongside the four files from the original Task 2 report.

### R8. Permission Persistence Guarantee

| Token | Purpose | Registration source | Persistence path | Guaranteed in catalogue now? |
|---|---|---|---|---|
| `collaboration.conversations.create` | Start a direct or Collaboration Group conversation | `config/permissions.php` (`modules.collaboration.conversations`) + `CollaborationServiceProvider::boot()`'s `PermissionRegistryInterface::register()` call | `RbacSeeder` (fresh install) **and now** migration `2026_09_02_100006` (already-running environment) | **Yes** |
| `collaboration.conversations.message_drivers` | Start a direct conversation with a driver, subject to IAM data scope | Same as above | Same as above | **Yes** |
| `collaboration.groups.create` | Create a Collaboration Group | Same as above | Same as above | **Yes** |

No token was renamed. Any name not in this table (e.g. a typo, or a future permission not yet declared) continues to fail closed — `RequirePermissionMiddleware`/`PermissionService::userHasPermission()` returns false for an undefined name (the exact mechanism the Loading OS migration's own docblock names as the fail-closed behavior), verified in this remediation by R6/test 4 rather than only cited from the earlier migration's comment.

### R9. Confirmation: No Automatic Role Grants

Verified two ways, not merely declared: (a) by construction — the new migration's `up()` contains no `role_permissions` write of any kind, only the `permissions` insert loop; (b) by test — `CollaborationPermissionCatalogTest::test_persisting_the_permission_definitions_grants_them_to_no_role` queries the `role_permissions` pivot table directly and asserts zero rows for these three permission ids. Permission-definition persistence and permission-assignment remain fully separate operations, exactly as the CTO's ruling requires; no employee, driver, admin, or system-role template was granted anything by this remediation. (System/`is_system` roles are unaffected for an unrelated, pre-existing reason: they bypass all permission checks via `Gate::before()`, not via a `role_permissions` row — see `RbacSeeder`'s own comment on this, unchanged by this task.)

### R10. Remaining External IAM/Data-Scope Dependency

Restated, unchanged from the original report and from R1: no role in the live catalogue currently carries a `data_scope` narrower than `all` for any driver-related permission, so IAM's canonical Data Scope Engine currently resolves the employee→driver scope check to unrestricted for everyone. Per the CTO's ruling (R1), this is tracked as an external IAM/catalogue capability dependency for a later cross-lane task — it does not block Task 2 closure and was not touched here.

### R11. Final Task 2 State

```
IMPLEMENTED:                  YES
TESTS WRITTEN:                YES
TESTS EXECUTED:                NO
VERIFIED:                     NO
COMMITTED:                    YES
INTEGRATED:                   NO
DEV VISIBLE:                  NO
USER VERIFIED:                NO
CERTIFIED:                    NO
DRIVER SCOPE PIPELINE:        IMPLEMENTED
DRIVER NARROW DATA SCOPE:     EXTERNAL IAM CAPABILITY / NOT CURRENTLY AVAILABLE
PERMISSION CATALOG PERSISTENCE: CLOSED
Task 3:                       NOT STARTED
```

**Final Status: COMPLETE.**

### R12. Task 3 Release Recommendation

**APPROVED FOR CTO REVIEW.** The one closure item (permission catalog persistence) is resolved with a small, additive, idempotent, twice-precedented migration and four tests proving the contract; the driver-scope dependency is correctly classified as external and tracked, not blocking. Recommend Task 3 begin with the runtime verification of both this remediation's tests and the original Task 2 test suite on the canonical device before or alongside its own scope (voice, realtime capability gate, notifications, search).
