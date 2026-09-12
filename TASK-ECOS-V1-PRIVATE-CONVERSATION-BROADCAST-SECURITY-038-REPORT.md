# TASK-ECOS-V1-PRIVATE-CONVERSATION-BROADCAST-SECURITY-038

## Base / Head

- **Canonical base:** `E:\ECOS\ecos-develop` @ `6509c4a8e38bf2765f35707426dd881687b923ca`
  (`develop`) — confirmed unchanged throughout this task, still the current
  canonical HEAD.
- **Isolated worktree:** `E:\ECOS\_v1-private-conversation-broadcast-security-038`,
  branch `task/v1-private-conversation-broadcast-security-038`.
- **Head:** `813dfcf58cb290b07221d6b0a63080635fdb0751` — one commit on top of
  `6509c4a8`. Working tree clean. Not merged, not cherry-picked into
  canonical, not pushed. `main` untouched.

## Root Cause Confirmation

Fresh-read (not assumed from memory) end-to-end:

- **`config/broadcasting.php:28`** — `'default' => env('BROADCAST_CONNECTION', 'log')`.
  Confirmed still unset in both `ecos-dev-app` and `ecos-dev-testrunner`, so
  the effective driver is `'log'`. `laravel/reverb` still has zero references
  in `composer.json` — never installed.
- **`routes/channels.php`** — `collaboration.conversation.{conversationId}`
  and `collaboration.user.{userId}` are registered correctly; both callbacks
  are logically sound on their own (re-confirmed by reading them fresh).
- **`bootstrap/app.php`** — `withBroadcasting(routes/channels.php,
  ['middleware' => ['auth:sanctum']])`. The auth route and its middleware are
  wired correctly.
- **The actual defect, traced to vendor source:**
  `Illuminate\Broadcasting\Broadcasters\LogBroadcaster::auth()` and
  `NullBroadcaster::auth()` are both unconditional no-ops (empty method
  bodies). Neither calls the shared, protected
  `Broadcaster::verifyUserCanAccessChannel()` that every real driver
  (confirmed by reading `PusherBroadcaster::auth()` directly: it calls
  `parent::verifyUserCanAccessChannel($request, $channelName)` after
  normalizing the channel name via the `UsePusherChannelConventions` trait)
  already uses. `BroadcastController::authenticate()` just returns
  `Broadcast::auth($request)` directly — whatever the resolved driver's
  `auth()` produces. For `log`/`null`, that is unconditionally `null` → an
  empty-body HTTP 200, for any channel, for any authenticated user.
- **Do not assume the transport is a sufficient gate — verified, not
  assumed:** confirmed `NullBroadcaster` has the identical defect (so
  switching `BROADCAST_CONNECTION=null` would silently reopen the same hole)
  and confirmed real drivers do not have it, by reading `PusherBroadcaster`
  directly rather than presuming either way.

## Authorization Boundary Used

**Reused exactly, added nothing new.** The canonical authority is
`ConversationParticipant::query()->where('conversation_id', ...)
->where('user_id', ...)->whereNull('left_at')->exists()`, already registered
in `routes/channels.php` — the same check every Collaboration REST endpoint
uses (per that file's own docblock). Confirmed tenant/company isolation is
already structurally guaranteed one layer up, at participant-creation time:
`AddGroupParticipantAction` (`app`-level, `Modules/Collaboration/Application
/Actions/AddGroupParticipantAction.php:54-57`) requires
`User::where('id', $targetUserId)->where('company_id',
$conversation->company_id)->firstOrFail()` before a `ConversationParticipant`
row can ever be created — a cross-company participant row cannot exist. No
second membership/participant engine was written anywhere in this fix.

## Source Fix

Three files, isolated worktree only:

- **`backend/app/Broadcasting/FailClosedLogBroadcaster.php`** (new) —
  `extends LogBroadcaster`, overrides only `auth()` /
  `validAuthenticationResponse()` to call the inherited
  `verifyUserCanAccessChannel()` (via `UsePusherChannelConventions` for
  prefix stripping, identically to `PusherBroadcaster`). `broadcast()` —
  outbound log-based delivery — is inherited unchanged.
- **`backend/app/Broadcasting/FailClosedNullBroadcaster.php`** (new) — same
  pattern, `extends NullBroadcaster`, closes the identical gap for
  `BROADCAST_CONNECTION=null`.
- **`backend/app/Providers/AppServiceProvider.php`** (modified) —
  `Broadcast::extend('log', ...)` / `Broadcast::extend('null', ...)` in
  `boot()`, registering both fail-closed subclasses in place of the stock
  ones. Confirmed safe registration timing by reading
  `ApplicationBuilder::withBroadcasting()`: it loads `routes/channels.php`
  inside an `$app->booted()` callback, which fires strictly after every
  provider's `boot()` — so `Broadcast::extend()` is always registered before
  the manager ever resolves either driver for the first time. Keyed by
  **driver name** (`BroadcastManager::resolve()` checks `customCreators`
  before its own `create{Driver}Driver()` methods), so this holds regardless
  of which connection name in `config/broadcasting.php` uses that driver.

No change to `routes/channels.php`, no change to any Collaboration domain
model or action, no change to message delivery semantics, no change to the
broadcasting stack beyond these two drivers' authorization behavior.

## Tenant / Company Isolation

Explicitly proven, not assumed (see Focused Security Tests below):

- Participant in Conversation A cannot subscribe to Conversation B — proven.
- User in another company cannot subscribe — proven (and structurally
  guaranteed one layer up, see Authorization Boundary Used).
- Removed/departed (`left_at` set) participant cannot subscribe — proven.
- Valid, active participant can subscribe — proven.
- No system/admin bypass was added; none exists in `routes/channels.php`
  either — not invented here.

## Focused Security Tests

**`backend/tests/Feature/Collaboration/CollaborationBroadcastAuthorizationSecurityTest.php`
— 8/8 passing** (run against the real `/broadcasting/auth` HTTP endpoint,
under this application's actual effective `'log'` driver, using the real
canonical authorization path — no exploit tooling, no offensive probes):

1. `test_the_effective_broadcast_driver_is_the_one_this_fix_targets` — confirms
   the suite genuinely runs under `'log'`, resolved to `FailClosedLogBroadcaster`.
2. `test_unauthenticated_request_is_denied` — 401, rejected before channel
   authorization is ever reached.
3. `test_authenticated_non_participant_is_denied` — 403.
4. `test_an_active_participant_is_allowed` — 200.
5. `test_a_user_in_a_different_company_is_denied` — 403.
6. `test_a_participant_of_a_different_conversation_is_denied` — 403 (proves
   the check is scoped to the exact conversation id).
7. `test_a_participant_who_has_left_the_conversation_is_denied` — 403.
8. `test_a_user_cannot_subscribe_to_another_users_private_task_channel` — 403
   for someone else's channel, 200 for their own (proves the fix is generic,
   not conversation-channel-specific).

**Existing canonical compatibility — `CollaborationRealtimeTest`, run
unmodified: 6/6 passing**, including the exact two tests this whole task
exists to reconcile:
`test_a_non_participant_is_refused_the_conversation_broadcast_channel` (the
test that failed in Task 037 — **now passes**) and
`test_a_participant_is_authorized_for_the_conversation_broadcast_channel`
(continues to pass — no false-positive lockout of legitimate access).

**Broader Collaboration suite, run for regression visibility (203 tests,
185 passing, 18 failing) — every failure individually reconciled, none
caused by this fix:**

- 14 of the 18 were already failing in Task 037's original full-suite run
  (cross-checked directly against that run's captured failing-tests list),
  i.e. pre-existing and unrelated to broadcasting entirely: `CollaborationConversationTest`
  (2), `CollaborationSearchTest` (4), `CollaborationMessageTest` (2),
  `CollaborationMuteTest` (1), `CollaborationTask3RegressionTest` (2),
  `CollaborationTask4RegressionTest` (1 of its 3), `CollaborationTaskActivityNotificationTest`
  (1), `CollaborationVoiceTest` (1).
- The remaining 4 (`CollaborationPermissionCatalogTest` ×2,
  `CollaborationTask4RegressionTest` ×2) are a direct, expected consequence
  of this task's own schema-only test-database clone (see below) — they
  assert that specific permission rows exist "from the migration, not from
  test setup," which a deliberately data-free schema clone will never
  satisfy. None of the 8 focused security tests need any such pre-seeded
  data (`userWithGrants()` creates its own `Role`/`Permission` rows via
  `firstOrCreate()`), so none of this affected the actual gate being closed.
- Zero of the 18 touch `/broadcasting/auth`, channel authorization, or any
  code this fix changed — confirmed both by content (none reference
  broadcasting) and by the disjoint-code-path reasoning that a driver-level
  broadcaster change cannot affect REST controllers, search, or migrations.

**Regression preservation (Task 035D):** not reopened, not regressed.
`my_participant`/`unread_count` create-response hydration, the same-second
polling/unread fix, the recipe snapshot hash, and the subtotal-consistency
invariant were not touched by this fix. The unread-count-related failures
above are the exact same anomaly already flagged as unexplained in the Task
037 report — re-observed here, not newly caused, and out of this task's
scope to fix (Collaboration scope was deliberately not broadened).

## Environment note (why a live database was needed, and how it was handled)

The disposable `ecos_dev_test` database was found mid-way through an
orphaned `migrate:fresh` left running by an earlier task (flagged, not
successfully killed, in the Task 037 report) — 377/828 migrations applied,
an inconsistent intermediate schema. Resolved by: (1) restarting only the
disposable `ecos-dev-testrunner` container (kills the stray process; does
not touch `ecos-dev-app`/DEV, MySQL, Redis, or Mailpit; the container's
baked-in code is not volume-mounted, so this is not a rebuild); (2) cloning
`ecos_dev`'s (DEV's real, already fully-migrated, 828/0-pending) schema into
`ecos_dev_test` via `mysqldump --no-data` — **structure only**, verified
directly (`companies`, `users`, `orders`, `collaboration_conversations`,
`collaboration_conversation_participants`, `role_permissions`, `roles`,
`permissions` all confirmed at exactly 0 rows) — plus the `migrations`
bookkeeping table's own row data, restoring `migrate:status` to 828 ran / 0
pending. No DEV business data was copied at any point. This was
dramatically faster than replaying a full `migrate:fresh` (minutes, not the
~90 minutes measured earlier this session) and did not require running
`migrate:fresh` or the full backend suite, per instruction. The 4
test failures this produces are documented above and do not affect the
security-gate verdict.

The fix's own source files were verified in the isolated worktree first
(PHP syntax, Pint, PHPStan all clean there) and then copied — never
rebuilt, never merged into canonical — directly into the already-running
`ecos-dev-testrunner` container purely to exercise them against a real,
live `/broadcasting/auth` HTTP round-trip; the authoritative, reviewable
copy of the change lives only in the isolated worktree/branch, exactly as
instructed.

## Static Validation

- **PHP syntax** — clean, all 4 touched/added files.
- **Pint** — clean (`passed`; 3 minor auto-fixes applied and reconfirmed
  clean: `fully_qualified_strict_types`, `unary_operator_spaces`,
  `not_operator_with_successor_space`, `trailing_comma_in_multiline`).
- **PHPStan** (canonical `phpstan.neon.dist`, level 0, touched scope) —
  `[OK] No errors`.
- **`git diff --check`** (staged, all 4 files) — clean.
- Confirmed via the repository's own pre-commit "ECOS Engineering Guardian"
  gate at commit time: PHP Syntax `PASS`.

## Remaining Blockers

**None for this gate.** Private conversation broadcast authorization is now
enforced, fail-closed, under this application's actual effective broadcast
driver, proven via the real HTTP endpoint and the real canonical
authorization path — not source review alone.

Two pre-existing, out-of-scope items remain open (unrelated to this gate,
not touched, not regressed, not part of this task's mandate): the
unread-count anomaly first flagged in the Task 037 report, and the general
observation that a fully-representative test database (with migration-seeded
catalog data) is needed for `CollaborationPermissionCatalogTest` and similar
tests to pass — neither blocks this security gate.

---

## Verdict

**PRIVATE CONVERSATION BROADCAST AUTHORIZATION — VERIFIED**

**V1 SECURITY BLOCKER CLOSED — READY FOR FINAL CERTIFICATION CLOSURE**

Do NOT integrate automatically. Do NOT deploy. Do NOT touch `main`. Do NOT
push. Do NOT certify.
