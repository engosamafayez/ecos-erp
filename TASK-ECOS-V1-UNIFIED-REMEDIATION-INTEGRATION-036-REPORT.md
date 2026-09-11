# TASK-ECOS-V1-UNIFIED-REMEDIATION-INTEGRATION-036

## Canonical Before / After

- **Canonical Before:** `7d122fdc6a9cd0aeae977df7c181ad8945dc4a23` (matched the
  expected base exactly; writer gate confirmed branch=`develop`, tracked tree
  clean, no MERGE_HEAD/rebase/cherry-pick in progress, no competing writer —
  re-checked fresh immediately before the real write, unchanged both times)
- **Canonical After:** `0b2d273e0746f88bc31548cb2549e9b3d6b646da`
- 7 commits landed, 0 pushed to `origin` (local `develop` was already 265
  commits ahead of `origin/develop` before this task; now 272 ahead)

## 035A — Procurement / Supplier / Invoice Receiving: INTEGRATED

Landed as `ff85b96f` (cherry-picked from `7c47a6b6`). Verified present: Supplier
list ambiguous-`company_id` fix (plus the same dormant bug in
`SupplierCategory`), invoice-first supplier/receipt authority correction,
receipt-anchor preservation (`InvoiceReceivingLinkService`), partial-receipt
status/cancellation correctness (`physicallyAcceptedQuantity()`), cross-company
Product failure handling at every layer (Goods Receipt actions, both request
classes, both posting paths). No duplicate receiving engine introduced. Item 6
("unknown inventory class") remains investigated-and-found-stale, not fixed —
carried forward as-is, not reopened.

## 035B — Platform / MySQL / Security: INTEGRATED

Landed as `858cd71b` + `6d64e00f` (cherry-picked from `819c4c8f` →
`7707f0f2`). Verified present: MySQL-safe `like` replacing PostgreSQL-only
`ilike` (17 files), the channels-to-brand-ownership migration guard fix,
the new `reconcile_channels_company_id_drop` reconciliation migration,
`ChannelFactory`'s removed obsolete `company_id` supply, context-aware Media
upload authorization (fail-closed context→permission map through the single
`AuthorizationGatewayInterface` entry point), the three missing
`organization.{brands,business_accounts,teams}` permission registrations, and
the MySQL-native `TIMESTAMPDIFF`/`DATE_SUB` rewrite of the Waste Investigation
report SQL. Preserved exactly as authored — no changes made during
integration.

## 035C — Operations / Distribution: INTEGRATED

Landed as `dcf501a8` (cherry-picked from `9729acc4`, the confirmed
application-source integration point — `5f63be96`, its R1 report-only
successor, was correctly excluded per instruction and remains on its own
branch/worktree history). Verified present: `WaveClosureCustodyService` no
longer releases Orders already `OutForDelivery`/`Cancelled`/`Delivered`/
`FinalCash` (the ones `DeliveryAttemptClosureService` owns or that are already
terminal), and `WaveClosedListenerOrderIndependenceTest` (the listener-order
commutativity proof) is present. §2 (Distribution window/slot scoping)
required no application-source change, exactly as its own R1 concluded — not
reopened here.

## 035D — Collaboration / Integrity: INTEGRATED

Landed as `33fd57c6` + `846e42b5` (cherry-picked from `fd0e60a9` →
`8d4b89d9`). Verified present: `my_participant`/`unread_count`
create-response hydration (Item 1, `fd0e60a9`); the same-second message
polling/unread fix — widened `TIMESTAMP(6)` precision plus the UUIDv7-`id`
polling cursor and its new `(conversation_id, id)` index; the semantic recipe
snapshot hash (`RecipeSnapshot::semanticFingerprint()`, excluding volatile
`resolved_at`) across all three hash call sites; the subtotal-consistency
invariant (`assertSubtotalMatchesLineTotals()`) in `SnapshotValidator`. Item 6
(Collaboration Task Activity Notification) remains classified **C —
environment issue, no source altered** — carried forward, not reopened, not
claimed fixed.

## 035E — Frontend Validation: INTEGRATED

Landed as `0b2d273e` (cherry-picked from `3eebfae0`). Verified present:
mobile-menu state reset on parent close, New Count Dialog label/control
accessibility id, 9 test-fixture/mock/query corrections (including the Driver
Settlement suite), missing Marketing EN/AR enum/status keys, and the
`eslint-suppressions.json` reduction (independently re-verified against the
real rule at the time of 035E's own authoring, not re-verified again here).
Only the 18 individually-reviewed changes from the Task 033 recovery snapshot
were ever included — the other 21 of that snapshot's 39 files were never part
of 035E and are not part of canonical now.

## Exact Commits Landed

| Lane | Original checkpoint | Landed as | Content match |
|---|---|---|---|
| 035A | `7c47a6b6` | `ff85b96f` | byte-identical (`diff` empty) |
| 035B (1/2) | `819c4c8f` | `858cd71b` | — |
| 035B (2/2) | `7707f0f2` | `6d64e00f` | byte-identical incremental patch |
| 035C | `9729acc4` | `dcf501a8` | byte-identical incremental patch |
| 035D (1/2) | `fd0e60a9` | `33fd57c6` | — |
| 035D (2/2) | `8d4b89d9` | `846e42b5` | byte-identical incremental patch |
| 035E | `3eebfae0` | `0b2d273e` | byte-identical incremental patch |

Each lane's own isolated patch (`diff <its base> <its final checkpoint>`) was
compared against its exact incremental slice on canonical
(`diff <canonical commit before this lane> <canonical commit after this
lane>`) — every one produced an empty `diff`, proving the landed content is
exactly the approved content, no more and no less, regardless of integration
order. Excluded, as instructed: `7b9c1c5b` (Task 033 recovery snapshot) —
never cherry-picked, not reachable from canonical `develop`.

## Conflicts / Resolutions

**Zero mechanical or semantic conflicts across all 7 cherry-picks**, both in
the isolated rehearsal and in the real integration (identical outcome, same
commits, same order) — the five lanes touch disjoint modules (Purchasing/
Inventory; Commerce/Channels+Marketing+Organization+Media+Core; Logistics/
Distribution; Collaboration+Manufacturing+Common/Snapshots; frontend) with no
file overlap.

**One pre-existing data-quality note surfaced by `git diff --check`, not a
conflict:** `frontend/src/features/customers/pages/customers-page.test.tsx`
has mixed LF/CRLF line endings — confirmed present in canonical *before* this
task (193 of 458 lines already CRLF in `7d122fdc` itself) — unrelated to any
of the 5 lanes' actual content. 035E's own edits to this file inherited the
same pre-existing mixed convention. Left untouched: normalizing ~195 unrelated
lines' line endings would be scope creep into a canonical file's pre-existing
formatting, not a fix for approved-lane content, and no lane's required-
presence checklist calls for it.

## Migration Inventory

5 migration files touched across the 2 lanes that have any:

| File | Lane | Change | Idempotent? |
|---|---|---|---|
| `2026_12_30_090000_reconcile_channels_company_id_drop.php` | 035B | **New.** Reaches environments where the original migration below already ran as a no-op. | Yes — guards on `hasColumn('channels','company_id')`, wraps every drop/add in try/catch for already-applied state, refuses (throws, does not silently drop data) if any channel still lacks a resolvable `brand_id`. |
| `2026_07_06_180000_migrate_channels_to_brand_ownership.php` | 035B | **Modified** — guards were keyed on `brand_id` presence (always true), now keyed on actual column/state. | Yes, for installs that never ran it. Does not retroactively fix installs where it already ran (that's what the new migration above is for). |
| `2026_09_11_100000_widen_collaboration_timestamp_precision_for_same_second_ordering.php` | 035D | **New.** Widens 3 columns to `TIMESTAMP(6)`, adds one index. | Yes — guards on `hasTable()`/index-existence before every `ALTER`/index creation. |
| `2026_06_29_400000_create_disassembly_transactions_table.php` | 035D | **Modified** — doc-comment only (`toArray()`→`semanticFingerprint()`), zero DDL change. | N/A — no schema effect either way. |
| `2026_06_29_000003_create_manufacturing_transactions_table.php` | 035D | **Modified** — doc-comment only, identical to above. | N/A — no schema effect either way. |

No filename/timestamp collisions anywhere in the tree (each new filename
confirmed unique). Ordering correct: both reconciliation/new migrations sort
after the originals they depend on. Both genuinely new migrations were read
in full and are safe against an existing DEV database in any state (never
run, partially run, or already-correct). **Migrations were not run** — static
inspection only, per instruction.

## Static Validation

Run against the isolated rehearsal (identical result confirmed for the real
integration, since the content is byte-identical):

- **`git diff --check`** — clean except the one pre-existing CRLF note above
  (not introduced by this integration).
- **PHP syntax** (`php -l`, all ~46 touched PHP files) — clean.
- **Pint** (`--test`, same file set) — `passed`.
- **PHPStan** (`phpstan.neon.dist`, same file set) — `[OK] No errors`.
- **ESLint** (correctly scoped to 035E's 16 touched TS/TSX files) — 0 errors,
  0 warnings.
- **TypeScript** (`tsc -b --noEmit`, full project) — 13 pre-existing errors in
  files none of the 5 lanes touch (Admin/HR/IAM/Logistics-dispatch pages —
  `StatusVariant`/index-signature issues unrelated to any lane's types),
  consistent with this session's repeatedly-confirmed canonical baseline
  noise; zero errors in any touched file.
- **EN/AR parity** (`i18n-audit.mjs --json`) — 0 missing keys, 0 invalid JSON,
  0 duplicate keys.

**Not run, per instruction:** full backend test suite, `migrate:fresh`, DEV
rollout.

## Worktrees Retired / Preserved

Plain `git worktree remove` (no `--force`) attempted on all 5:

| Worktree | Result |
|---|---|
| `_v1-remediation-procurement-035a` | **Removed.** Branch `task/v1-remediation-procurement-035a` preserved. |
| `_v1-remediation-platform-mysql-security-035b` | **Removed.** Branch `task/v1-remediation-platform-mysql-security-035b` preserved. |
| `_v1-remediation-operations-distribution-035c` | **Removed.** Branch `task/v1-remediation-operations-distribution-035c` preserved. |
| `_v1-remediation-frontend-validation-035e` | **Removed.** Branch `task/v1-remediation-frontend-validation-035e` preserved. |
| `_collaboration-integrity-remediation-035d` | **Left in place** — contains an untracked file (`TASK-ECOS-V1-REMEDIATION-COLLABORATION-INTEGRITY-035D-R1-REPORT.md`, its own R1 report, never committed there). Plain removal refused it (`contains modified or untracked files, use --force`); not forced, per instruction. Its approved checkpoint `8d4b89d9` is confirmed landed in canonical regardless — nothing of value is at risk, only worktree cleanup is deferred. |

All 5 branches confirmed to still exist and resolve after this pass. The
isolated rehearsal worktree (never one of the 5 approved lanes — my own
throwaway verification copy, content now proven byte-identical to real
canonical) was force-removed; it held nothing not already safely represented
in `develop`.

## Manual Security Gate

Carried forward exactly, not resolved by this integration:

**PRIVATE CONVERSATION BROADCAST AUTHORIZATION**
**— MANUAL SECURITY VERIFICATION REQUIRED BEFORE V1 CERTIFICATION**

Source integration was never blocked by this gate. `routes/channels.php`'s
`collaboration.conversation.{conversationId}` authorization was not touched by
any of the 5 lanes and was not touched by this integration.

## DEV = NOT DEPLOYED

Confirmed: no deploy action taken, no migration run, `main` untouched, no
`git push` executed.

## Final Checkpoint

- **Canonical branch:** `develop`
- **Canonical Before:** `7d122fdc6a9cd0aeae977df7c181ad8945dc4a23`
- **Canonical After:** `0b2d273e0746f88bc31548cb2549e9b3d6b646da`
- **Working tree:** clean after this report's own commit.

**V1 UNIFIED REMEDIATION CANONICAL INTEGRATED — SOURCE READY FOR FINAL DEV
SYNC + RELEASE SUITE**

Do NOT deploy DEV. Do NOT touch main. Do NOT certify. Do NOT push.
FINAL NOTIFICATION REQUIRED.
