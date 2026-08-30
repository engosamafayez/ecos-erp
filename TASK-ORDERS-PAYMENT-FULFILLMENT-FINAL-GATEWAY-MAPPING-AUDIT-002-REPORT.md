# TASK-ORDERS-PAYMENT-FULFILLMENT-FINAL-GATEWAY-MAPPING-AUDIT-002

**Type:** Final audit gate — read-only. No code, configuration, ADR, schema, business-data, permission, test, commit or deploy change.
**Date:** 2026-08-23
**Predecessor:** TASK-ORDERS-PAYMENT-FULFILLMENT-WOOCOMMERCE-GATEWAY-VOCABULARY-AUDIT-001

---

## 1. Executive Summary

**Two STOP conditions are triggered. Implementation must not begin.**

The approved Paymob evidence is internally consistent and the four specific integration slugs are
mutually distinguishable — the mapping *itself* is sound. What blocks implementation is not the
mapping but the two layers on either side of it.

**STOP-3 — the approved vocabulary conflicts with the existing ECOS vocabulary.** The business
approved `card` as the policy key for Debit/Credit Card. ECOS uses **`credit_card`** in four
places: the system-default policy, the live brand-policy row, and three copies of the manual
allow-list. `card` is not a policy key anywhere in the platform, and it was **empirically observed
to resolve to `none`** — i.e. adopting `card` verbatim today would make every card order
proof-exempt. One vocabulary must yield to the other, and that is a business decision.

**STOP-5 — the importer cannot see anything except the gateway slug.** It reads exactly three
payment fields from the WooCommerce payload — `payment_method`, `payment_method_title`,
`transaction_id` — and **no `meta_data`, no gateway metadata, and no Paymob integration ID**. If an
order arrives on the umbrella gateway `paymob` (business evidence item 5, "Pay with Paymob"), ECOS
is structurally incapable of telling Card from Mobile Wallet. There is no fallback field to read.

Two further findings shape the implementation but do not block it:

- **The fail-closed rule inverts a certified contract.** Unknown → `none` today. Making it
  `required` reverses behaviour that two existing tests deliberately pin (`test_c4`, `test_w3`) and
  that four decision records certify. It is an implementation gap plus a certified-contract change,
  not a bug fix.
- **The technical carrier is compatible.** `orders.payment_method` is `varchar(255)` and
  `config_brand_policies.settings` is `json`, so the 42-character slugs fit and a sibling
  `payment_gateway_map` needs no migration. **STOP-4 is not triggered.**

Order-level evidence remains unobtainable: still zero imported orders, zero inbound syncs, both
channels disconnected. Every order-layer row below is therefore `NOT OBSERVABLE`.

---

## 2. Approved Business Vocabulary

| # | Business method | Approved ECOS policy key | Status in ECOS today |
|---|---|---|---|
| A | Cash on Delivery | `cod` | ✅ exists, resolves `none` |
| B | InstaPay (operational replacement for Bank Transfer) | `instapay` | ✅ exists, resolves `required` |
| C | Debit / Credit Card | **`card`** | ❌ **does not exist** — ECOS uses `credit_card` |
| D | Mobile Wallet | `mobile_wallet` | ✅ exists, resolves `required` |
| E | Bank Transfer (**not active**; InstaPay replaces it) | `bank_transfer` | ⚠ exists and is `required` — still an accepted manual value |
| F | Unknown / unmapped gateway | **proof-required, fail-closed** | ❌ **resolves `none`** — fail-open |

Three of six rows disagree with the platform as it stands.

---

## 3. Paymob Configuration Evidence

Supplied by the business. **No Paymob configuration exists in this repository or its seeded data** —
a case-insensitive sweep including hidden and gitignored files and all five `.env` files returned
**zero** occurrences of `137918`, `2149531`, `3099831`, `3099268`, `vpc-egp`, `uig-egp`,
`accept.paymob`, or `integration_id`.

| Integration ID | Business method | Supplied WooCommerce identifier | Chars | Approved key |
|---|---|---|---|---|
| `137918` | Debit / Credit Card | `paymob-137918-aseel-card-vpc-egp` | 32 | `card` |
| `3099268` | Debit / Credit Card | `paymob-3099268-aseelalexwatermelon-vpc-egp` | 42 | `card` |
| `2149531` | Mobile Wallets | `paymob-2149531-aseel-main-wallet-uig-egp` | 40 | `mobile_wallet` |
| `3099831` | Mobile Wallets | `paymob-3099831-aseel-mobile-uig-egp` | 35 | `mobile_wallet` |
| — | Provider umbrella | `paymob` ("Pay with Paymob") | 6 | **MUST NOT be mapped** |

**Structural observation (not a mapping claim).** The four specific slugs are mutually distinct on
two independent axes — the integration ID, and a scheme token (`-vpc-` on both Card entries,
`-uig-` on both Wallet entries). Given the slug, Card and Wallet are separable. **STOP-2 is
therefore not triggered — conditional on the slug being the value ECOS actually receives.**

The only Paymob references in the repository are narrative: a code comment naming Paymob as the
*unhandled* case (`WooCommerceOrderImporter.php:520`), and prospective *outbound charge gateway*
entries in `docs/contracts/ANTI-CORRUPTION-LAYER.md:224,240`, `INTEGRATION-CATALOG.md:145` and
`EXTERNAL-INTEGRATIONS.md:23` whose named translators (`PaymentACL`, `PaymentGatewaySerializer`,
`PaymentWebhookTranslator`) have no implementation. That is a different subsystem from WooCommerce
order ingestion.

`aseel` does appear authoritatively — but only as deployment infrastructure
(`docker/nginx/default.conf:17` `server_name aseelhoneyeg.com`). Zero payment content.

---

## 4. WooCommerce Gateway Identifiers

**No WooCommerce gateway identifier has ever been observed in this system.** `orders.payment_method`
is NULL on all 17 rows; the distinct-value set is empty.

The identifiers in §3 are **business-supplied configuration evidence**, not values this audit
observed passing through the import path.

---

## 5. WooCommerce Order Representation

Per §4 of the brief, each layer is reported independently. Order-layer rows cannot be observed
because no WooCommerce order has ever been imported.

### A. Cash on Delivery

| Layer | Actual value |
|---|---|
| Paymob Integration ID | n/a — not a Paymob method |
| Paymob Payment Method | n/a |
| WooCommerce Gateway ID | `cod` (WooCommerce **core** gateway) |
| Woo Order `payment_method` | NOT OBSERVABLE — NO LIVE ORDER EVIDENCE |
| Woo Order `payment_method_title` | NOT OBSERVABLE — NO LIVE ORDER EVIDENCE |
| ECOS imported `payment_method` | NOT OBSERVABLE — NO LIVE ORDER EVIDENCE |
| ECOS policy key | `cod` — **by string collision**, empirically resolves `none` |

### B. InstaPay

| Layer | Actual value |
|---|---|
| Paymob Integration ID | NOT SUPPLIED |
| Paymob Payment Method | NOT SUPPLIED — no InstaPay integration appears in the §2 evidence |
| WooCommerce Gateway ID | **NOT SUPPLIED** — no identifier was provided for InstaPay |
| Woo Order `payment_method` | NOT OBSERVABLE — NO LIVE ORDER EVIDENCE |
| Woo Order `payment_method_title` | NOT OBSERVABLE — NO LIVE ORDER EVIDENCE |
| ECOS imported `payment_method` | NOT OBSERVABLE — NO LIVE ORDER EVIDENCE |
| ECOS policy key | `instapay` exists and resolves `required`, but **nothing connects any gateway to it** |

InstaPay is the business's primary proof-required method and is the **only approved active method
with no gateway identifier supplied at all.**

### C. Paymob Card — Integration 137918

| Layer | Actual value |
|---|---|
| Paymob Integration ID | `137918` |
| Paymob Payment Method | Debit / Credit Card |
| WooCommerce Gateway ID | `paymob-137918-aseel-card-vpc-egp` (business-supplied) |
| Woo Order `payment_method` | NOT OBSERVABLE — NO LIVE ORDER EVIDENCE |
| Woo Order `payment_method_title` | NOT OBSERVABLE — NO LIVE ORDER EVIDENCE |
| ECOS imported `payment_method` | NOT OBSERVABLE — would be the slug verbatim (§6) |
| ECOS policy key | **none today** — empirically `requirementFor(...) === 'none'` |

### D. Paymob Card — Integration 3099268

Identical shape; slug `paymob-3099268-aseelalexwatermelon-vpc-egp`; empirically resolves `none`.

### E. Paymob Mobile Wallet — Integration 2149531

Identical shape; slug `paymob-2149531-aseel-main-wallet-uig-egp`; empirically resolves `none`.

### F. Paymob Mobile Wallet — Integration 3099831

Identical shape; slug `paymob-3099831-aseel-mobile-uig-egp`; empirically resolves `none`.

### G. General Paymob umbrella gateway

| Layer | Actual value |
|---|---|
| Paymob Integration ID | **none — the umbrella carries no integration ID** |
| Paymob Payment Method | Indeterminate (provider, not a method) |
| WooCommerce Gateway ID | `paymob` |
| Woo Order `payment_method` | NOT OBSERVABLE — NO LIVE ORDER EVIDENCE |
| Woo Order `payment_method_title` | `Pay with Paymob` (business-supplied) — **non-discriminating** |
| ECOS imported `payment_method` | NOT OBSERVABLE — would be `paymob` verbatim |
| ECOS policy key | **UNDETERMINABLE** — see STOP-5 |

---

## 6. ECOS Import Path

**The AUDIT-001 finding is re-confirmed and unchanged.**

`backend/Modules/Commerce/OrderImport/Application/Services/WooCommerceOrderImporter.php:414-416`

```php
'payment_method'       => trim((string) ($wooOrder['payment_method'] ?? '')) ?: null,
'payment_method_title' => trim((string) ($wooOrder['payment_method_title'] ?? '')) ?: null,
'transaction_id'       => trim((string) ($wooOrder['transaction_id'] ?? '')) ?: null,
```

| Question | Answer |
|---|---|
| Which payload field is read? | `payment_method` — **and it is what the financial control evaluates** |
| Is `payment_method_title` read? | Yes — **stored and displayed only.** `PaymentFulfillmentGate::methodOf()` ignores it entirely |
| Is gateway metadata read? | **No** |
| Are Paymob integration identifiers read? | **No** |
| Is `meta_data` read? | **No** |
| Any normalization? | **No** — `trim()` only |
| Any mapping? | **No** |
| Written verbatim? | **Yes** |

The importer reads **19 payload fields in total**: `id`, `number`, `status`, `date_created`,
`date_paid`, `customer_note`, `billing`, `shipping`, `shipping_lines`, `shipping_total`,
`discount_total`, `total`, `total_tax`, `line_items`, `fee_lines`, `coupon_lines`,
`payment_method`, `payment_method_title`, `transaction_id`. **`meta_data` is not among them**, and
`ProcessOrderWebhookJob` reads no payment field either.

**This is the mechanism behind STOP-5.** There is no second field from which a specific method
could be recovered when the gateway slug is the umbrella.

---

## 7. Policy Resolution

`PaymentFulfillmentGate.php:121-126` is a plain array lookup: `return (string) ($policy[$method] ?? 'none');`
`methodOf()` (`:94-98`) prefers `payment_method_manual`, else the raw gateway slug, else `''`.
`permits()` (`:52-59`) short-circuits to **`true`** when the method is `''`.

**Observed empirically** against the live brand policy (read-only evaluation, nothing mutated):

| ECOS value | Current policy result | Expected business policy | Gap? |
|---|---|---|---|
| `cod` | `none` | `none` | ✅ no gap |
| `instapay` | `required` | `required` | ✅ no gap |
| **`card`** | **`none`** (key miss) | proof policy for Card — **not stated** | ❌ **key does not exist** |
| `credit_card` | `required` | — (not the approved key) | ⚠ vocabulary conflict |
| `mobile_wallet` | `required` | `required` | ✅ no gap |
| `bank_transfer` | `required` | inactive method | ⚠ still accepted on the manual path |
| `cash` | `none` | not in the approved list | ⚠ live-policy drift |
| **`paymob`** | **`none`** | must not be mapped; must not be fulfilment-eligible | ❌ **gap** |
| `paymob-137918-aseel-card-vpc-egp` | **`none`** | `card` | ❌ **gap** |
| `paymob-2149531-aseel-main-wallet-uig-egp` | **`none`** | `mobile_wallet` | ❌ **gap** |
| `bacs` (any unknown) | **`none`** | **`required` — fail-closed** | ❌ **gap — inverts current behaviour** |
| `NULL` / empty | **non-blocking** (`permits()` returns `true` before any lookup) | fail-closed | ❌ **gap — a third fail-open path** |

**Three distinct fail-open paths** must be closed for the approved rule F to hold: the key-miss
default, the umbrella gateway, and the empty/NULL method short-circuit. Only the first is commonly
discussed; the third is a separate branch that a policy-map change alone would not reach.

**The fail-closed change is not a bug fix.** The key-miss → `none` behaviour is deliberate,
documented in four decision records, and pinned by two live tests. Inverting it is a change to a
certified control and will require those tests to change.

---

## 8. Live Order Evidence

Re-verified read-only. **Every AUDIT-001 finding remains true.**

| Check | Result |
|---|---|
| Total orders | 17 |
| Orders with `external_order_id` | **0** |
| Orders with a non-empty `payment_method` | **0** |
| Distinct `(payment_method, payment_method_title)` | `(NULL, NULL)` — one row |
| Inbound sync-log entries | **0** (52 total, all outbound, all failed) |
| Channels | 2 × `woocommerce`, both `connection_status = disconnected` |
| `last_webhook_received_at` / `last_successful_sync_at` | **NULL on both channels** |

No WooCommerce order has ever reached this system. **Order-level mapping is unverified and cannot
be verified here.** No evidence was manufactured.

---

## 9. Existing Test Assumptions

| Test | Literal | Classification |
|---|---|---|
| **`OrderPaymentFinalCompletionTest::test_w1_a_proof_required_gateway_is_imported_awaiting_payment`** | **`instapay`** as a Woo `payment_method` | **TEST ASSUMPTION — NOT AUTHORITATIVE** |
| **`DistributionGroupsTest::test_payment_method_uses_the_orders_source_of_truth`** | **`instapay`** written into `orders.payment_method` | **TEST ASSUMPTION — NOT AUTHORITATIVE** |
| `OrderPaymentFinalCompletionTest::test_w2_a_cod_gateway_is_imported_unchanged` | `cod` | TEST ASSUMPTION — NOT AUTHORITATIVE (the collision is real, but unstated) |
| `OrderPaymentFinalCompletionTest::test_w3_an_unmapped_gateway_id_is_not_covered_by_the_control` | `bacs` | LOCKS EXISTING BEHAVIOUR |
| `OrderPaymentContractImplementation002Test::test_c4_an_unrecognised_method_key_still_resolves_to_none` | `bacs` | LOCKS EXISTING BEHAVIOUR |
| `OrderImportWarehouseTest::makeWooOrder()` | `bacs` | FIXTURE SCENERY |
| `DistributionOrdersFilterApiTest` (several) | `cod`, `bank_transfer` in the gateway column | TEST ASSUMPTION — NOT AUTHORITATIVE |
| `DeficitDecisionsImpactTest::test_gateway_method_is_used_...` | `visa_gateway` (invented) | TEST ASSUMPTION — NOT AUTHORITATIVE (the *precedence* assertion is sound) |
| ~40 further sites across the payment suites | `instapay` etc. as `payment_method_manual` | ECOS MANUAL METHOD — out of scope |

**Two tests treat `instapay` as a WooCommerce gateway ID**, named explicitly above as required by
§8. No authoritative source states that any store emits a gateway literally named `instapay`, and
§3 supplied no InstaPay identifier at all. **Neither test was modified.**

`test_w1` in particular is the sole basis for any claim that the import path is guarded — and §5.B
shows the value it uses is not established for any store.

**No test anywhere uses `paymob`, any Paymob integration ID, `fawry`, `valu`, or `meeza`.**

---

## 10. Final Mapping Table

| WooCommerce identifier | Payment meaning | ECOS policy key | Evidence status |
|---|---|---|---|
| `cod` | Cash on Delivery | `cod` | **INFERRED** — genuine Woo core gateway ID that collides exactly with the ECOS key; no store configuration on record, no order observed |
| *(none supplied)* | InstaPay | `instapay` | **NOT OBSERVABLE** — no gateway identifier supplied for the primary proof-required method |
| `paymob-137918-aseel-card-vpc-egp` | Card | `card` | **CONFIGURATION VERIFIED** (business-supplied) · target key **CONFLICTING** — `card` is not an ECOS key |
| `paymob-3099268-aseelalexwatermelon-vpc-egp` | Card | `card` | **CONFIGURATION VERIFIED** · target key **CONFLICTING** |
| `paymob-2149531-aseel-main-wallet-uig-egp` | Mobile Wallet | `mobile_wallet` | **CONFIGURATION VERIFIED** — target key exists and resolves `required` |
| `paymob-3099831-aseel-mobile-uig-egp` | Mobile Wallet | `mobile_wallet` | **CONFIGURATION VERIFIED** — target key exists and resolves `required` |
| `paymob` (umbrella) | Provider gateway | **DO NOT DIRECTLY MAP** | **CONFLICTING** — no ECOS field can disambiguate it (STOP-5) |
| *anything else* | Unknown | proof-required | **NOT OBSERVABLE** — and current behaviour is the opposite (`none`) |

**No row is ORDER VERIFIED.** No row reached `AUTHORITATIVE`: the four Paymob rows are verified as
*Paymob configuration* but not as *values ECOS receives*, and two of them target a key that does
not exist.

---

## 11. Implementation Contract

Documented, **not implemented**. Compatibility was verified before recording it, as §10 of the brief
requires.

```
WooCommerce external vocabulary        (raw gateway slug, verbatim, no meta_data available)
        ↓
Explicit payment_gateway_map           (config_brand_policies.settings, sibling of payment_proof_policy)
        ↓
ECOS policy key                        (cod | instapay | <card-key TBD> | mobile_wallet)
        ↓
PaymentFulfillmentGate::methodOf()     (the ONE normalisation point — manual still wins)
        ↓
payment_proof_policy                   (unchanged resolution chain: Channel → Company → Default)
        ↓
Fulfillment eligibility                (unchanged: payment sufficient AND active verified proof)
```

**Compatibility verified:**

| Requirement | Finding |
|---|---|
| Carrier for the map | `config_brand_policies.settings` is `json` → a sibling key needs **no migration** ✅ |
| Slug fits the column | `orders.payment_method` is `varchar(255)`, no index; longest slug is 42 chars ✅ |
| Resolution chain reusable | `orderPolicyFor()` already resolves Channel → Company → Default; the map can ride the same chain ✅ |
| Single normalisation point | `methodOf()` is the only place the effective method is derived ✅ |
| No second source of truth | The map lives beside the policy it feeds, in the store the gate already reads ✅ |
| No second gate, no new table, no API change | None required ✅ |

**Placement constraint.** The map belongs in `methodOf()`, **not** in the importer. Putting it in
the importer would create a second interpretation of the same field on a second code path — the
exact defect `PaymentFulfillmentGate` was created to eliminate — and would leave manually-created
orders and edited orders unmapped.

**Three fail-open paths must close together** for rule F, or the control remains advisory: the
key-miss default (`?? 'none'`), the umbrella gateway, and the empty-method short-circuit in
`permits()` (`:57-59`).

---

## 12. Remaining Gaps

1. **`card` vs `credit_card`** — the approved key does not exist; `card` empirically resolves `none`.
   Four sites carry `credit_card`: the system default, the live brand-policy row, and three manual
   allow-list copies.
2. **No InstaPay gateway identifier** — the primary proof-required method has no supplied Woo value.
3. **Umbrella `paymob` is undisambiguatable** — no `meta_data` is read; no fallback field exists.
4. **Card's proof requirement is unstated** — §2 assigns the key but not the policy. The live row
   says `credit_card => required`; the system default says `optional`. Card payments settle
   instantly at the gateway, which argues the other way. Undecided.
5. **Fail-closed inverts a certified contract** — pinned by `test_c4` and `test_w3`, certified by
   four decision records.
6. **Empty/NULL method is a third fail-open path** — `permits()` returns `true` before any lookup.
7. **`bank_transfer` is declared inactive but still accepted** on all three manual write paths and
   still resolves `required`.
8. **Live-policy drift** — the brand row carries `cash` (absent from the manual allow-list) and sets
   `credit_card => required` where the system default says `optional`.
9. **Frontend substring matcher mislabels gateway slugs** — `order-payment-cell.tsx:29`,
   `order-payment-badge.tsx:29`, `order-detail-drawer.tsx:162` match unanchored substrings against
   keys `cod, cash, visa, credit_card, bank, instalment, wallet`. Consequence for the approved
   slugs:

   | Slug | Rendered as | Correct? |
   |---|---|---|
   | `paymob-2149531-aseel-main-wallet-uig-egp` | **"Wallet"** (contains `wallet`) | accidentally right |
   | `paymob-3099831-aseel-mobile-uig-egp` | `Paymob…` (7-char truncation) | wrong |
   | `paymob-137918-aseel-card-vpc-egp` | `Paymob…` — note `card` alone is **not** a map key | wrong |
   | `paymob-3099268-aseelalexwatermelon-vpc-egp` | `Paymob…` | wrong |

   **The same business method renders two different ways** depending on the integration slug. Display
   only; no policy effect. Reported, not fixed.
10. **`distribution_delivery_stops.payment_method` is `varchar(30)`** with a matching `max:30` rule
    (`DeliveryController.php:84`). A 40-42 char value would 422. **Latent** — no code path currently
    copies `orders.payment_method` there.
11. **Inverse precedence between two readers** — `PaymentFulfillmentGate::methodOf()` reads
    manual → slug; `DistributionAggregationService:709` reads slug → manual. Pre-existing.

---

## 13. STOP Conditions

| # | Condition | Status |
|---|---|---|
| 1 | Same Woo identifier maps to multiple ECOS meanings | **NOT TRIGGERED** — each of the four slugs has exactly one meaning |
| 2 | Paymob identifiers cannot be distinguished Card vs Wallet | **NOT TRIGGERED** *(conditional)* — separable by integration ID and by `-vpc-`/`-uig-`, **provided the specific slug is the value ECOS receives** |
| 3 | Existing ECOS keys conflict with the approved vocabulary | 🛑 **TRIGGERED** — `card` vs `credit_card`; also `cash` present and `bank_transfer` declared inactive but still live |
| 4 | Mapping would require schema/API redesign | **NOT TRIGGERED** — `varchar(255)` column, `json` settings; no migration, no API change |
| 5 | Umbrella `paymob` is the only value and no metadata can identify the real method | 🛑 **TRIGGERED** — the importer reads **no `meta_data`, no integration ID**; `payment_method_title` ("Pay with Paymob") is equally non-discriminating. The business listed the umbrella as a configured gateway, so this is live, not hypothetical |

Neither STOP was worked around. Nothing was implemented.

---

## 14. Recommendation

**Do not implement.** Three decisions are required first — two business, one technical.

**Business decision 1 — resolve the card key (STOP-3).** Either
**(a)** adopt the existing `credit_card` and treat `card` as shorthand — zero code change to the
vocabulary, the mapping simply targets `credit_card`; or
**(b)** rename the platform key to `card` — which touches the system default, the live brand-policy
row, and three manual allow-list copies, and requires a data migration of the stored policy JSON.
**(a) is materially cheaper and is the recommendation** unless the business specifically wants the
shorter name in the UI. Whichever is chosen, state Card's proof requirement explicitly
(`required` / `optional` / `none`), because §2 assigns the key but not the policy.

**Business decision 2 — the umbrella gateway (STOP-5).** Either
**(a)** disable the umbrella `paymob` gateway in WooCommerce so every order carries a specific
integration slug — the cleanest fix, and it removes the blocker entirely; or
**(b)** rule that `paymob` maps to the fail-closed unknown branch (proof-required), accepting that
those orders park at `awaiting_payment` for manual triage; or
**(c)** commission the technical work to read Paymob's integration ID from the order's `meta_data`
— which is a change to the importer's payload contract and a larger piece of work.
**(a) or (b)** keep this inside the small explicit map. **(c) should not be chosen without
evidence** that the umbrella is genuinely in use.

**Technical evidence still required.** Supply the InstaPay gateway identifier (§5.B — it is the
primary proof-required method and has none), and confirm from a real store or a real order which
form `payment_method` actually takes. One exported order JSON from a connected store would close
§5's entire `NOT OBSERVABLE` column at once. Until then no row can be promoted to ORDER VERIFIED.

**When those land**, the previous architectural recommendation stands and is now verified
compatible: a sibling `payment_gateway_map` in `config_brand_policies.settings`, applied at
`PaymentFulfillmentGate::methodOf()`, closing all three fail-open paths together — no new table, no
new gate, no new source of truth, no migration, no API change.

---

## 15. Final Verdict

# AUDIT BLOCKED — ADDITIONAL BUSINESS DECISION REQUIRED

STOP-3 and STOP-5 are both triggered, and §11 of the brief requires that implementation not begin
when a STOP occurs.

The Paymob evidence is good: the four specific integrations are internally consistent and mutually
distinguishable, and the technical carrier is compatible with no schema or API change. But the
approved vocabulary names a key the platform does not have and which currently resolves to
"no proof required", and the umbrella gateway cannot be resolved to a payment method by any field
ECOS reads.

**Minimum to unblock:** (1) resolve `card` vs `credit_card` and state Card's proof requirement;
(2) rule on the umbrella `paymob` gateway; (3) supply the InstaPay gateway identifier.

A fourth item is not a blocker but must be acknowledged before implementation: making unknown
gateways fail-closed **reverses a certified, test-asserted contract** and will require `test_c4` and
`test_w3` to change.

---

**No implementation. No code change. No configuration change. No database change. No API change. No business-data change. No test changed. No commit. No deploy.**
