# TASK-ECOS-V1.1-WOOCOMMERCE-WOO-01-REFUND-FINANCE-INTEGRATION-043

**Architecture authority:** TASK-ECOS-V1.1-WOOCOMMERCE-ARCHITECTURE-042A-R1
**Checkpoint:** TASK-ECOS-V1.1-WOO-01-VERIFICATION-REMEDIATION-CHECKPOINT-043-R1
**Phase:** Implementation, verified against a real, isolated MySQL 8.4 instance.

---

## FINAL STATUS: SOURCE COMPLETE

All mandatory runtime gates in the checkpoint ticket passed. See §17-equivalent assessment at the
end of this report for the gate-by-gate result.

## BASE

`cce124e3d7edf46a09b5fbd0a6686f35fab53c30` (branch `feature/woocommerce-v1.1`).

## WOO_01_SHA

Recorded after the commit this report accompanies — see the commit this file is part of
(`git log -1` on this branch). This report and the commit are the same change; no separate
docs-only commit follows it.

## Preservation note (checkpoint §1)

The checkpoint's premise that the diff was "staged" was corrected on inspection: `git status`
showed the changed/new files as **modified + untracked, not staged** (`git diff --cached` was
empty). Nothing was reset, stashed, or discarded. Exact changed-file set, confirmed by `git status
--short` immediately before this checkpoint began and unchanged in kind since (one file renamed,
none removed):

| File | State |
|---|---|
| `backend/Modules/Commerce/Synchronization/Application/Jobs/ProcessOrderWebhookJob.php` | modified |
| `backend/Modules/Commerce/Synchronization/Application/Services/WooRefundApplicationService.php` | new |
| `backend/Modules/Commerce/Synchronization/Application/DTO/WooRefundOutcome.php` | new |
| `backend/Modules/Finance/Infrastructure/Database/Migrations/2026_09_12_150000_add_unique_source_reference_to_finance_customer_invoices_table.php` | new (renamed — see Migration Final Name) |
| `backend/tests/Feature/Commerce/WooCommerceRefundIntegrationTest.php` | new |
| Three `TASK-*-REPORT.md` files at repo root | new (documentation only) |

---

## Commercial Accounting Discovery — re-verified directly (checkpoint §2)

Re-read `CommercialAccountingService::recognizeRevenue()` (lines 62-109) and
`PostRevenueAndCogsOnOrderDelivered` fresh in this checkpoint. Confirmed, unchanged from the prior
report, **no second revenue-recognition path was added**:

| Field | Value |
|---|---|
| Order state/event that creates it | `OrderDeliveredEvent`, fired by `CompleteDeliveryWorkflow::events()` (order transitions `out_for_delivery` → `delivered` via canonical `FulfillmentEngine`), consumed by `PostRevenueAndCogsOnOrderDelivered` |
| Finance document type | `CustomerInvoice`, `document_type = invoice` (default), always exactly one `CustomerInvoiceLine` |
| Company scope | `companyId` parameter = `Order.company_id` |
| Currency | Not passed explicitly by `recognizeRevenue()` → `AccountsReceivableService::createDocument()`'s own default, `'EGP'` |
| `source_reference` structure | `source_type = 'order'`, `source_id = $orderId` (the ECOS `Order` UUID, not `external_order_id`) |
| Posting behavior | Created Draft, posted in the same call (`$invoice->isPosted() ? $invoice : $this->ar->postDocument(...)`) — posted by the time the method returns, barring an exception |
| Invoice/order linkage | `CommercialAccountingService::findOrderInvoice($companyId, $orderId)` — reused directly by `WooRefundApplicationService`, not reimplemented |

## Finance Invoice Authority

`CommercialAccountingService::findOrderInvoice()` is the sole, canonical way an order-linked
invoice is looked up — `WooRefundApplicationService` calls it, never re-queries
`finance_customer_invoices` for this purpose independently.

## Credit Note Authority — rules A-E (checkpoint §3)

| Rule | Behavior | Evidence |
|---|---|---|
| A. Reverse invoiced value per existing Finance rules | `CustomerDocumentType::CreditNote` via unmodified `AccountsReceivableService::createDocument()`/`postDocument()` | `test_full_financial_refund_posts_a_credit_note_for_the_full_amount` — PASS |
| B. Partial refund creates only the legitimate increment | bcmath-derived proportional split of the *original* invoice line, capped at the refundable ceiling | `test_partial_refunds_accumulate_to_the_correct_cumulative_total` — PASS |
| C. Second partial refund is a separate valid adjustment | distinct Woo refund id → distinct `source_id` → distinct Credit Note row | same test — PASS (200 then 100 → two rows, 300 total) |
| D. Full refund after an earlier partial refund uses only the remainder | ceiling = `invoice.total − Σ(already-posted Woo-refund Credit Notes)`; enforced before every post | `test_over_refund_is_rejected_without_posting_or_clamping` — PASS |
| E. No Finance backing document → explicit failure, zero mutation, never silent success | `reconciliation_required`, no invoice/Credit Note/journal fabricated | `test_pre_delivery_refund_requires_reconciliation_without_fabricating_anything` — PASS |

`CommercialAccountingService::reverseRevenue()` was deliberately **not** used (see prior report's
reasoning: it reverses a whole journal with no amount parameter and cannot represent a partial
refund) and was **not modified**.

## Pre-Delivery Refund Behavior (checkpoint §4)

Explicit case added: an order with `status = in_progress` (never delivered, so
`CommercialAccountingService` never created an invoice) receiving a refund. Result, proven by
`test_pre_delivery_refund_requires_reconciliation_without_fabricating_anything` (PASS): financial
status `reconciliation_required`; zero `CustomerInvoice` rows created; zero `CustomerReturn` rows
created; `Order.status` unchanged. No other canonical payment authority was substituted — none
exists for this case (confirmed in the 042A-R1 architecture report), so the honest failure path is
the only correct behavior, not a gap to invent around.

## Physical Return Evidence Rule — CRITICAL, corrected (checkpoint §5)

**This checkpoint's own critique was correct and the prior implementation was wrong.** The version
of `WooRefundApplicationService` reviewed at the start of this checkpoint invoked
`ReturnOrderWorkflow` whenever a Woo refund's `line_items` carried a non-zero quantity. On
re-examination: a refunded line-item quantity in WooCommerce's data model is a **commercial/
accounting allocation** — which order line, and how much of its price, a refund amount is
attributed to — not a warehouse-receipt confirmation. WooCommerce does not require, and stores
routinely do not practice, that a refunded quantity means the physical goods came back; nothing in
WooCommerce's standard REST/webhook payload distinguishes "refunded and returned" from "refunded,
customer keeps the item."

**Remediation:** `ReturnOrderWorkflow`, `FulfillmentEngine`, the refund-detail HTTP fetch, and all
line-item/quantity matching were **removed entirely** from `WooRefundApplicationService`. The
service is now financial-only. `WooRefundOutcome.physicalReturnStatus` is a fixed
`not_evaluated_no_reliable_evidence` with an explanatory message. Physical return remains
exclusively the pre-existing, unmodified ECOS warehouse/driver flow (`ReturnOrderWorkflow` +
`ReceiveReturnWorkflow`), triggered only by ECOS's own operational events — never by, or inferred
from, a Woo webhook.

Required regression (checkpoint's own wording) proven by
`test_refund_with_goods_shaped_payload_never_invokes_physical_return` (PASS, order deliberately
`OutForDelivery` — the one state that would have accepted the old, wrong call — to prove this is an
absence of the call, not merely a guard rejection): a refund whose reason text and shape suggest
goods returned → Credit Note posts (financial proceeds) → `CustomerReturn` count stays `0` →
`Order.status` unchanged.

**Explicit finding:** WooCommerce's standard webhook/REST payload contains no reliable
physical-return evidence for any store. WOO-01 is financial-only by design; if a future channel
capability (e.g. a store-side plugin reporting an actual warehouse scan) ever supplies trustworthy
evidence, it has a place to report through (`physicalReturnStatus`) without changing this class's
shape — but nothing manufactures that evidence today.

## Refunded Status Double-Path Check (checkpoint §6)

Proven end-to-end through `ProcessOrderWebhookJob` itself (not just the service in isolation) by
`test_refunded_status_produces_exactly_one_effect_never_a_second_status_transition` (PASS): a
webhook payload with `status: 'refunded'` and a matching `refunds` entry produces exactly one
Credit Note and leaves `Order.status` untouched — the generic status-`match()` branch is
unreachable for `'refunded'` (excluded explicitly in `ProcessOrderWebhookJob`), so it cannot also
route to `CancelOrderWorkflow`/etc. No direct `Order.status` write exists anywhere in the new code;
`FulfillmentEngine`/`OrderStatusGuard` are untouched and, after the §5 remediation, no longer even
referenced by this service.

## Idempotency Identity

`finance_customer_invoices.(company_id, source_type = 'woo_refund', source_id)`, where
`source_id = "{channel_id}:{external_order_id}:{woo_refund_id}"`. Never keyed on `Order.status`.

## Unique Constraint Columns (checkpoint §8)

**Three columns**, not one: `(company_id, source_type, source_id)` — confirmed by direct
`SHOW INDEX` against the real, migrated schema (see Migration UP/DOWN Result). `source_id` alone
additionally carries its own internal namespace (`channel_id : external_order_id : woo_refund_id`),
so collision is prevented on two independent levels: the DB constraint (company + document kind +
key) and the key's own content (channel + order + refund, so a different channel or a different
refund id can never produce the same string). The checkpoint's example format
(`woo:{channel}:refund:{id}`) was not adopted literally — the existing convention already
established by `CommercialAccountingService`'s migration (`source_type`/`source_id` as a generic
pair, `source_type` naming the *kind*) was followed instead, which is the "consistent with existing
source rules" alternative the checkpoint asked for.

## Source Reference Namespace

`source_type = 'woo_refund'` (a new, distinct kind alongside the existing `'order'`);
`source_id`'s `{channel_id}:{external_order_id}:` prefix is also reused directly as the SQL `LIKE`
scope for the "already refunded" ceiling sum — one string serves both the uniqueness key and the
per-order grouping query, with no second field required.

## Migration Old Name → Final Name (checkpoint §9)

- **Old:** `2026_12_23_000001_add_unique_source_reference_to_finance_customer_invoices_table.php`
  (dated to sort immediately after the *unrelated* latest Finance migration, `2026_12_23_000000_widen_source_event_id_for_integration.php`, out of excess caution).
- **Investigated:** read that Dec-23 migration directly — it touches `finance_journal_entries`,
  `finance_posting_audit`, `finance_posted_event_receipts`, `finance_posting_dead_letters`. **None
  of these is `finance_customer_invoices`.** No functional dependency exists between the two
  migrations. The only genuine dependency is `2026_09_02_200000_add_source_reference_to_finance_customer_invoices_table.php` (adds the `source_type`/`source_id` columns this migration constrains), and nothing between `2026_09_03` and `2026_12_23` touches that table at all.
- **Final:** `2026_09_12_150000_add_unique_source_reference_to_finance_customer_invoices_table.php`
  — a current-dated, correctly-sequenced migration (renamed via plain filesystem move; the file was
  never tracked/committed, so no git history was rewritten).

## Migration Duplicate Precheck (checkpoint §10)

Ran directly against the real, fully-migrated schema:
```sql
SELECT company_id, source_type, source_id, COUNT(*) c
FROM finance_customer_invoices
WHERE source_type IS NOT NULL AND source_id IS NOT NULL
GROUP BY company_id, source_type, source_id
HAVING COUNT(*) > 1;
```
**Result: zero rows.** (Expected: this is a freshly-migrated schema with no pre-existing production
history; the query is recorded here specifically so it can be re-run, unchanged, against a real
populated environment before this migration is ever applied there.)

## Migration UP/DOWN Result (checkpoint §11)

Proven against a real, disposable MySQL 8.4.9 instance (see REAL MYSQL below), via the migration's
own `up()`/`down()` methods invoked directly (not batch-relative `migrate:rollback`, which operates
on whole batches and would have rolled back unrelated migrations run in the same batch):

| Step | Result |
|---|---|
| UP (fresh, full ~450-migration run, `migrate --force`) | Succeeded, 74.21ms, zero errors across the entire schema |
| Unique index exists (`SHOW INDEX`) | `finance_ci_source_unique`, `Non_unique=0`, columns `company_id`, `source_type`, `source_id` in that order |
| Duplicate (company_id, source_type, source_id) insert | **Rejected by MySQL itself**: `SQLSTATE[23000]: … 1062 Duplicate entry … for key 'finance_customer_invoices.finance_ci_source_unique'` |
| DOWN | Restored `finance_ci_source_idx` as a plain (non-unique) index; `finance_ci_source_unique` confirmed absent afterward |
| UP again | Succeeded; `finance_ci_source_unique` confirmed present again |

## REAL MYSQL: PASS

Docker Desktop was unavailable in this sandbox (`com.docker.service` would not start; a
winget-driven MySQL Windows-service install was abandoned mid-flight per the checkpoint's own
correction, since it risked becoming a shared/ambiguous-ownership service). Built a genuinely
isolated, disposable instance instead: **MySQL Community Server 8.4.9** (official binary
distribution, no installer, no Windows service registered), own data directory, own port (`33061`,
confirmed free before binding, `bind-address=127.0.0.1` only), own process, holding only a
purpose-created `ecos_dev_test` schema (the actual database name this codebase's `TestCase.php`
requires — see Known Limitations #1) with no DEV/Staging/business data of any kind. **Stopped after
verification** (`mysqld` processes force-terminated; no service existed to leave running).

## FOCUSED TESTS

`backend/tests/Feature/Commerce/WooCommerceRefundIntegrationTest.php` — **12 tests, 46 assertions,
0 failures, 0 errors** (final run). One test was consolidated during this checkpoint (the original
13-case list's §9/§10 goods-return-quantity cases collapsed into one "never invoked" proof once §5's
removal made a positive goods-return case impossible to construct honestly — see §5 above for why).

| # | Test | Result |
|---|---|---|
| 1 | Full financial refund posts a Credit Note for the full amount | PASS |
| 2/3 | Partial refunds accumulate to the correct cumulative total (200+100=300) | PASS |
| 4 | Replaying the same refund event produces no additional financial effect | PASS |
| 5 | Cumulative refund-array replay across separate webhook deliveries (checkpoint §7's exact example) | PASS |
| 6 | Over-refund is rejected without posting or clamping | PASS |
| 7 | Refund with goods-shaped payload never invokes physical return | PASS |
| 8 | Finance posting failure propagates; no half-applied state; resumes correctly on retry | PASS |
| 9 | Pre-delivery refund requires reconciliation without fabricating anything | PASS |
| 10 | Refunded status produces exactly one effect, never a second status transition | PASS |
| 11 | Currency mismatch fails explicitly without conversion | PASS |
| 12 | Refund against one company never touches another company's invoice | PASS |
| 13 | Two channels sharing an external order id do not collide | PASS |

Existing Woo tests (`EcosOrderStatusToWooTranslatorTest`, `OrdersSyncControlsAndHistoricalImportTest`,
`ChannelSynchronizationDualRunTest`) were not modified and were not part of this run (per "no full
repository suite").

## Real bugs found and fixed by this real-database run (not test-fixture issues)

1. **`sync_logs.correlation_id` is `varchar(36)`** (sized for exactly one UUID, per this codebase's
   existing Phase-B domain-event convention) — the service was passing its full
   `channel:external_order_id:refund_id` composite key into it, which exceeds 36 characters and was
   rejected by MySQL (`1406 Data too long`). **Fixed at the root**: the composite key is no longer
   sent as `correlation_id` (it was already fully recorded in `request_payload`); `correlation_id`
   is now `null` for these log entries rather than truncated or widened on a shared column outside
   this ticket's scope.
2. **A failed `postDocument()` retry was permanently stuck.** The original idempotency check
   treated *any* existing `(company_id, source_type, source_id)` row — Draft or Posted — as
   "already applied." If `postDocument()` ever failed after `createDocument()` succeeded (proven by
   the Finance-failure test), a legitimate retry after the operator fixed the underlying issue would
   forever report `idempotent_replay` against a Draft row that was never actually posted. **Fixed**:
   the check now distinguishes Posted (true idempotent replay) from Draft (resume — post the *same*
   row rather than creating a second one), proven by the retry half of the Finance-failure test.

## Transaction Failure Evidence (checkpoint §14)

Proven by execution, not source inspection alone: disabling the company's AR control account
*after* the original invoice had already posted causes `applyRefund()`'s own `postDocument()` call
to throw `FinanceException`; the assertion confirms exactly one Draft (unposted) Credit Note exists
afterward — never zero (that would mean losing the record of the attempt) and never a Posted row
(that would mean a GL-affecting change happened despite the failure). Re-enabling the control
account and retrying the identical refund event completes it (`financialStatus = 'posted'`),
proving the durable state is genuinely recoverable, not merely inert.

## Full Refund / Partial Refund / Incremental Refund / Cumulative Payload Replay / Over-Refund / Currency Mismatch

All PASS — see the FOCUSED TESTS table above (tests 1, 2/3, 2/3, 5, 6, 11 respectively).

## Money / Currency (checkpoint §15)

- **Minor-unit / no float refund math**: all comparisons and derived amounts (over-refund ceiling,
  already-refunded sum, proportional net/tax split) use `bccomp`/`bcsub`/`bcdiv`/`bcmul` against the
  DECIMAL-backed string values the database and Eloquent's own `decimal:4` casts already provide.
  PHP floats are produced exactly once, at the final boundary, to satisfy
  `AccountsReceivableService`'s own existing (unchanged) float-typed parameters.
- **Positive refund amount**: enforced (`bccomp($requested, '0', 4) <= 0` → rejected).
- **Cumulative refund ≤ refundable amount**: enforced server-side, proven by test.
- **Currency matches the canonical Finance document**: enforced (`currency_mismatch` status,
  case-insensitive comparison against `invoice->currency`), proven by test — no conversion logic
  exists anywhere in this class.

## STATIC VALIDATION (checkpoint §16, re-run after all remediation)

| Gate | Result |
|---|---|
| `php -l` (all 5 changed/new PHP files) | Pass |
| Pint | Pass |
| PHPStan (`phpstan.neon.dist`, all touched files incl. the test) | No errors |
| `git diff --check` | Pass, no whitespace issues |

## Known Limitations

1. **Database name mismatch trap for future runs.** This project's `tests/TestCase.php`
   deliberately overrides `DB_DATABASE` to `ecos_dev_test` (hardcoded, to match the real `ecos-dev`
   Docker stack's baked-in env) — **not** `ecos_erp_test`, which is what `.env.testing`/
   `phpunit.xml` state. `DB_HOST`/`DB_PORT` are not forced and were successfully overridden via real
   shell environment variables in this checkpoint; `DB_DATABASE` cannot be, by this project's own
   design. Anyone standing up a fresh test database for this suite must migrate a schema literally
   named `ecos_dev_test`, or `TestCase::setUp()` will connect to a nonexistent database.
2. **`APP_KEY` is not supplied by `phpunit.xml` or `.env.testing`** in this worktree — the real
   `ecos-dev` container presumably bakes it in via its own env. A local `.env` was created
   (gitignored, not part of this commit) with a freshly generated key, exported as a real shell
   variable for the actual PHPUnit invocation.
3. **`Order` has no `HasFactory` trait** (confirmed directly in current source) — the test file
   uses a small `makeOrder()`/`makeCustomerId()` helper pair instead of `Order::factory()`, since
   that method does not exist on this model.
4. Sections explicitly out of scope per the original and checkpoint tickets were not touched:
   customer tenant matching, `CustomerObserver` binding, product inbound ownership, Go-Live
   lifecycle, webhook registration lifecycle, plugin adapter, UI.
5. **Single-line invoice assumption** stands as previously reported: `recognizeRevenue()` always
   creates exactly one invoice line; multi-line proportional allocation is not implemented because
   nothing in this codebase can currently produce a multi-line order-linked invoice.
6. **A brand-new order arriving via `order.updated` that already carries refund history in the same
   payload** has those refunds processed starting from the *next* webhook delivery, not the one that
   first imports it — `importSingle()`'s `bool`-only return was judged out of this ticket's focused
   scope to extend.
7. This environment has still never processed a real Woo refund against a real, connected store
   (both channels remain disconnected per the 042A empirical finding) — every proof above is a
   real database enforcing real constraints and real business logic, but with synthetic fixtures,
   not live Woo traffic. WOO-08 remains the slice that closes that specific gap.

## READY FOR CTO REVIEW: YES

---

**No push. No deployment. Other lanes untouched (confirmed: `git -C ../ECOS-V1-STAGING status
--short` is clean).**

**STOP. Do not begin WOO-02. Wait for CTO review and approval.**

---
🤖 Generated with [Claude Code](https://claude.com/claude-code)

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
