# TASK-ORDERS-PAYMENT-FULFILLMENT-WOOCOMMERCE-GATEWAY-VOCABULARY-AUDIT-001

**Type:** Audit only — no implementation, no code change, no configuration change, no database mutation, no commit, no deploy.
**Date:** 2026-08-23
**Scope:** Resolve the remaining WooCommerce gateway-vocabulary blocker for Payment/Fulfillment.

---

## 1. Executive Summary

**Business mapping is undefined.**

No authoritative statement anywhere in this repository maps a WooCommerce payment gateway ID to an
ECOS payment-proof policy key. The absence is not an oversight that this audit discovered — it is a
condition that five independent artefacts already record deliberately, each one explicitly refusing
to invent a mapping as a side effect of other work.

Three findings decide the question:

1. **There is no translation layer, and the omission is structural.** `orders.payment_method` is
   written verbatim from the WooCommerce payload with nothing but `trim()`. The platform's own
   inbound contract document specifies a `WooStatusMap` for the adjacent `status` field and lists
   no payment row at all.

2. **The current control binds by accidental string equality, not by mapping.**
   `PaymentFulfillmentGate::requirementFor()` is a plain array lookup with `?? 'none'`. A gateway ID
   only triggers the proof requirement if it is spelled exactly like an ECOS policy key. `bacs`
   — the real WooCommerce bank-transfer ID — resolves to `none` and is therefore unguarded, while
   the manually-entered `bank_transfer` is `required`. That asymmetry is documented and open.

3. **The observable gateway population is empty.** No WooCommerce order has ever been imported into
   `ecos_dev`; `orders.payment_method` is NULL on all 17 rows; both channels are `disconnected`
   with placeholder URLs and have never received a webhook or completed a sync. There is no
   evidence of what any real store sends, because no real store has ever been connected.

The mapping therefore cannot be derived from code, configuration, documentation, or data. It
requires a business statement of which gateways the live stores are configured with.

---

## 2. Current WooCommerce Gateway Vocabulary

**Observed in this system: none.** The set of distinct WooCommerce gateway IDs present in
`ecos_dev` is empty (see §7).

Three *different* payment vocabularies exist in the platform, and none of them is a WooCommerce
gateway vocabulary:

| Vocabulary | Values | Where defined | Governs |
|---|---|---|---|
| **ECOS order payment-proof policy keys** | `cod`, `instapay`, `bank_transfer`, `mobile_wallet`, `credit_card` | `BrandPolicy::defaultOrderSettings()` (`backend/Modules/Admin/Configuration/Domain/Models/BrandPolicy.php:159-165`) | Fulfilment eligibility |
| **ECOS manual-entry allow-list** | `cod`, `instapay`, `mobile_wallet`, `credit_card`, `bank_transfer` | An inline `in:` validation rule, duplicated in three request classes | `payment_method_manual` only — **never** `payment_method` |
| **Purchasing goods-receipt methods** | `cash`, `bank_transfer`, `cheque`, `wallet`, `credit`, `other` | `backend/Modules/Purchasing/GoodsReceipts/Domain/Enums/PaymentMethod.php:9-14` | Supplier-side receipts — unrelated to orders |

The three allow-list copies:

- `backend/Modules/Commerce/Orders/Presentation/Http/Requests/StoreManualOrderRequest.php:45`
- `backend/Modules/Commerce/Orders/Presentation/Http/Requests/UpdateOrderRequest.php:66`
- `backend/Modules/Commerce/Orders/Presentation/Http/Requests/PatchOrderRequest.php:35`

```php
'payment_method_manual' => ['nullable', 'in:cod,instapay,mobile_wallet,credit_card,bank_transfer'],
```

**There is no enum, no config file, and no database table defining the ECOS order payment
vocabulary.** It exists only as a validation string repeated three times.

**A fourth, drifted vocabulary is live in the database.** The brand policy row actually in force
carries six keys, including `cash`, which the manual allow-list does not accept and the system
default does not declare (see §7).

---

## 3. Import Path

Two entry points reach the same importer, and neither consults a translator:

```
Woo webhook (public, unauthenticated, throttled)
  routes/api.php  →  WooCommerceWebhookController::handleOrder
                  →  ProcessOrderWebhookJob            (queued — no Auth::user())
                  →  WooCommerceOrderImporter::importSingle

Woo poll (authenticated, permission:sales.channels.sync)
  routes/api.php  →  OrderImportController::importOrders
                  →  ImportOrdersAction
                  →  WooCommerceOrderImporter::import
```

Both converge on `WooCommerceOrderImporter::buildOrder()` and then on the single physical writer
`EloquentOrderRepository::create()` → `Order::query()->create()`.

**A status translator exists on this path. A payment translator does not.**
`Modules/Commerce/Synchronization/Application/Services/WooCommerceOrderStatusTranslator.php` holds a
declared `MAP` for `status`. There is no equivalent class, array, or config key for
`payment_method`. This asymmetry is the clearest structural evidence that the payment mapping was
never authored.

---

## 4. Storage Fields

`backend/Modules/Commerce/OrderImport/Application/Services/WooCommerceOrderImporter.php:414-416`

```php
'payment_method'       => trim((string) ($wooOrder['payment_method'] ?? '')) ?: null,
'payment_method_title' => trim((string) ($wooOrder['payment_method_title'] ?? '')) ?: null,
'transaction_id'       => trim((string) ($wooOrder['transaction_id'] ?? '')) ?: null,
```

| Column | Source | Content |
|---|---|---|
| `orders.payment_method` | WooCommerce `payment_method` | **Raw gateway ID, verbatim** |
| `orders.payment_method_title` | WooCommerce `payment_method_title` | Raw human label from the store |
| `orders.payment_method_manual` | ECOS manual form / edit endpoints | Constrained to the five ECOS keys. **The importer never writes it.** |

`orders.payment_method` has exactly **one** inbound writer in the entire backend — line 414 above.
(`OrderSnapshotPersistenceAdapter.php:114` writes to the snapshot table from an already-built DTO;
it is a copy, not an ingestion point.)

---

## 5. Normalization Path

**There is none.** The value is copied verbatim after `trim()`.

Exhaustive searches for a normaliser returned zero results across the whole backend — including
`vendor/` — for: `payment_method_map`, `gateway_map`, `normalisePaymentMethod`,
`normalizePaymentMethod`, `mapPaymentMethod`, `paymentMethodFor`, `payment_gateway`, `gatewayId`,
`gateway_id`, `PaymentMethodMap`, and any `strtolower()` applied to a payment method.

Two artefacts must not be mistaken for normalisation:

- **`WaveDemandController::paymentMethodOf()`** — a first-non-empty display coalescer over
  `payment_method_manual → payment_method_title → payment_method`. It returns the raw stored string
  unchanged. Note it uses a **different precedence** from the gate, which ignores
  `payment_method_title` entirely.
- **`PAYMENT_METHOD_LABELS` / `formatPaymentLabel()`** —
  `frontend/src/features/orders/components/order-detail-drawer.tsx:140-166`. A display-label map
  resolved by **fuzzy substring matching**. It contains no WooCommerce gateway ID at all, produces
  only human labels, and never touches policy. Its key set (`visa`, `mastercard`, `card`, `bank`,
  `instalment`, `wallet`, `online`, `cheque`, `check`) matches neither the policy vocabulary nor any
  Woo vocabulary — a third disagreeing list, and the artefact most likely to be misread as a mapping.

---

## 6. Policy Resolution Path

The imported value is interpreted in exactly one place.

**Step 1 — which string is evaluated.**
`backend/Modules/Commerce/Orders/Domain/Services/PaymentFulfillmentGate.php:94-98`

```php
public function methodOf(Order $order): string
{
    return trim((string) ($order->payment_method_manual ?? $order->payment_method ?? ''));
}
```

Manual entry wins; otherwise the **raw gateway ID** is what the financial control evaluates.
`payment_method_title` is deliberately ignored.

**Step 2 — the lookup.** `PaymentFulfillmentGate.php:121-126`

```php
public function requirementFor(string $method, ?string $channelId, ?string $companyId): string
{
    $policy = $this->orderPolicyFor($channelId, $companyId);

    return (string) ($policy[$method] ?? 'none');
}
```

A plain array lookup against the policy map resolved through the D2-B chain
(Channel → Company → System default). **A key miss yields `'none'` — non-blocking.**

**Consequence.** The proof requirement binds an imported order only when its gateway ID is
character-for-character one of `cod`, `instapay`, `bank_transfer`, `mobile_wallet`, `credit_card`.
That is string equality, not semantics. The behaviour is certified and test-asserted, and the
production code says so at `WooCommerceOrderImporter.php:515-522`:

> `// NO GATEWAY MAPPING IS INVENTED HERE … the control binds only where the gateway id happens to`
> `// equal an ECOS policy key … A store whose instapay gateway is called 'paymob' is still`
> `// unguarded. Closing that needs a Woo-gateway vocabulary that does not exist anywhere in this`
> `// codebase; inventing one would be a guess`

---

## 7. Live Data Evidence

All queries read-only against `ecos_dev`. No row was modified.

**Distinct WooCommerce gateway IDs present:**

| `orders.payment_method` | `payment_method_title` | Count |
|---|---|---|
| `NULL` | `NULL` | **17 (all orders)** |

**Distinct ECOS manual methods present:**

| `orders.payment_method_manual` | Count |
|---|---|
| `cod` | 12 |
| `instapay` | 4 |
| `mobile_wallet` | 1 |

**Import and connection history:**

| Check | Result |
|---|---|
| Orders with `external_order_id` | **0** — no Woo order has ever been imported |
| Orders with a `transaction_id` | **0** |
| Inbound sync log entries | **0** — all 52 entries are `outbound`, all `failed` (36 customer, 10 inventory, 6 price) |
| Channels | 2, both `platform = woocommerce`, both `connection_status = disconnected` |
| Channel `store_url` | `https://store.ecos.example.com`, `https://wholesale.ecos.example.com` — placeholders |
| `last_successful_sync_at` / `last_webhook_received_at` | `NULL` on both channels |
| Channel credentials | present but 24/27 characters — real WooCommerce keys are 43 (`ck_`/`cs_` + 40 hex); these are seeded dummies |

**Policy actually in force:**

| Scope | Value |
|---|---|
| Brand `ECOS Holding` (`config_brand_policies`, group `order`) | `{"cod":"none","cash":"none","instapay":"required","credit_card":"required","bank_transfer":"required","mobile_wallet":"required"}` |
| Company-scope `order` settings | **none** — chain step 2 is unconfigured |

Two observations on the live policy: it carries a sixth key `cash` that the manual allow-list does
not accept, and it sets `credit_card` to `required` where the system default says `optional`. Both
are ECOS-side vocabulary drift, not gateway mapping — reported for accuracy, out of scope here.

**Conclusion.** The population of observed WooCommerce gateway IDs is **empty**. There is no live
data from which any mapping could be derived, inferred, or validated.

---

## 8. ORD-00003 Evidence

Read-only. The order was not modified.

| Field | Value | Meaning |
|---|---|---|
| `external_order_id` | **NULL** | **Not imported from WooCommerce** |
| `payment_method` | **NULL** | No gateway ID present |
| `payment_method_title` | **NULL** | — |
| `transaction_id` | **NULL** | — |
| `payment_method_manual` | **`instapay`** | The manual field |
| `channel_id` | `019f4e1c-2f68-…` (ECOS Main Store, platform `woocommerce`) | A channel *reference*, nothing more |
| `created_at` | 2026-08-19 21:38:10 | — |

Creation event trail:

```
initiate_order    actor_id 1  Administrator
order_created     actor_id 1  Administrator   source: dashboard
awaiting_payment  actor_id 1
```

**Verdict: manual entry.** ORD-00003's `instapay` came from a human using the ECOS manual-order form
— `source: dashboard`, actor `Administrator` — constrained by the five-value `in:` rule in
`StoreManualOrderRequest`. It did **not** come from an ECOS mapping, and it did **not** come from a
WooCommerce gateway.

**This corrects a standing assumption.** ORD-00003 has been described as "WooCommerce-related". It
is not a WooCommerce order. It is a manually-created order that references a channel whose platform
happens to be WooCommerce. Selecting that channel does not make the order an import, and
ORD-00003 is therefore **not evidence of any gateway mapping** in either direction.

---

## 9. Existing Documentation / ADR Evidence

### 9.1 The strongest evidence: a structural omission in the inbound contract

`docs/contracts/ANTI-CORRUPTION-LAYER.md:51-84` is the platform's formal
"Inbound: WooCommerce Order → ECOS Order" specification. It maps eleven fields and writes out an
explicit `WooStatusMap` for `status`:

```
  woo.status                → order.status (via WooStatusMap)
  ...
WooStatusMap:
  pending     → draft
  processing  → confirmed
  ...
```

**`payment_method` does not appear in the mapping table at all.** A map is authored for the
adjacent field and omitted for this one. The omission is structural, not a formatting gap.

### 9.2 Authoritative statements — all of them negative

| Source | Statement |
|---|---|
| `docs/verification/…OWNER-DECISION-003-REPORT.md:203-212` | "their `payment_method` is the raw gateway id (`bacs`, `ppcp`, `stripe`), which is not a key in the ECOS `payment_proof_policy` vocabulary, so the requirement resolves `'none'` **by key-miss** … **Standing ambiguity, reported and deliberately not resolved, no mapping invented**" |
| `docs/verification/…DECISION-002-REPORT.md:731-734` | "Whether that asymmetry is intended is a business question. **No mapping was invented, and none should be added as a side effect of either decision.**" |
| `docs/verification/…DECISION-002-REPORT.md:873-878` | "the ECOS `payment_proof_policy` vocabulary … and WooCommerce gateway ids … **do not share a namespace**, so proof policy is effectively inert for imported orders. Whether Woo orders should be subject to proof policy at all is a business question for a separate task." |
| `docs/verification/…IMPLEMENTATION-002-FINAL-REPORT.md:320-334` | "**Not modified. No mapping invented. No STOP triggered.** … The two vocabularies do not share a namespace. Reported, not resolved." |
| `WooCommerceOrderImporter.php:515-522` (production code) | "a Woo-gateway vocabulary **that does not exist anywhere in this codebase**; inventing one would be a guess" |

### 9.3 Documents checked and found silent

| Document | Result |
|---|---|
| `docs/architecture/ADR-003-WooCommerce-Integration.md` | **Zero** occurrences of `payment` — the dedicated integration ADR does not discuss payment |
| `docs/adr/ADR-042-order-fsm-v3-canonical.md` | Defines the proof-required rule; names **no gateway ID** and never mentions WooCommerce |
| `docs/adr/ADR-011`, `docs/architecture/ADR-015` | Zero `payment`/`gateway` matches |
| `docs/architecture/ENTERPRISE-CONFIGURATION-PLATFORM.md` | Zero matches for `payment_proof_policy` / `gateway` / `instapay` |
| `docs/domain/Channels-Domain.md` | Zero `payment`/`gateway` matches |
| `.claude/` (5 files) | Zero `payment` / `gateway` / `woo` matches |
| All `*.json` / `*.yaml` / `*.yml` (excl. lockfiles) | No gateway list anywhere. i18n files carry ECOS keys and display labels only |

### 9.4 Two near-misses to discount

- `docs/contracts/ANTI-CORRUPTION-LAYER.md:221-254` and `docs/information/INTEGRATION-CATALOG.md:139-146`
  name Stripe / Paymob / Fawry as **prospective outbound charge gateways** with their own webhook
  ACL. That is a different subsystem from WooCommerce order ingestion and states no key mapping.
- `docs/information/REFERENCE-DATA-MODEL.md:102` lists
  `cash, card, bank_transfer, cheque, digital_wallet, cod` — a *fifth* vocabulary matching neither
  the policy keys nor any Woo IDs.

### 9.5 Is WooCommerce's own `payment_method` semantics documented here?

**NOT FOUND.** No document states what values the connected stores send. The only empirical
observation on record — `docs/verification/…COD-FULFILLMENT-AUDIT-001-REPORT.md:46-47` — reports the
field NULL on every order, which this audit re-confirms. Mentions of `bacs`, `ppcp`, `stripe`,
`cheque` in the decision reports are generic WooCommerce knowledge cited as illustration. **No
document names a store, a site, or an observed gateway configuration.**

---

## 10. Existing Tests

| Test | Gateway ID | Assertion | Classification |
|---|---|---|---|
| `OrderImportWarehouseTest::makeWooOrder()` | `bacs` (+ title "Direct bank transfer") | none — asserts warehouse assignment only | **Fixture scenery.** No assumption, no lock. Evidence only that its author believed `bacs` is what a real store sends for bank transfer. |
| `OrderPaymentContractImplementation002Test::test_c4_an_unrecognised_method_key_still_resolves_to_none` | `bacs` | `assertSame('none', requirementFor('bacs', …))` | **Locks key-miss behaviour.** The reverse of a mapping assumption — a guard against one being added silently. |
| `OrderPaymentFinalCompletionTest::test_w3_an_unmapped_gateway_id_is_not_covered_by_the_control` | `bacs` | order imports `in_progress`; requirement is `'none'` | **Locks key-miss behaviour**, deliberately as a visible gap marker. |
| `OrderPaymentFinalCompletionTest::test_w2_a_cod_gateway_is_imported_unchanged` | `cod` | imports `in_progress` | **Borderline.** `cod` *is* a genuine WooCommerce core gateway ID, so the collision is real — but the test relies on it without stating so. Its purpose is COD non-regression. |
| **`OrderPaymentFinalCompletionTest::test_w1_a_proof_required_gateway_is_imported_awaiting_payment`** | **`instapay`** | imports `awaiting_payment` | **⚠ ENCODES AN UNSTATED MAPPING ASSUMPTION — FLAGGED, NOT CHANGED.** |
| `DeficitDecisionsImpactTest` (Wave demand) | `visa_gateway` (fabricated) | title wins over raw slug | **Display precedence only.** Notably asserts the raw slug is never interpreted. |
| `DistributionOrdersFilterApiTest` | `cod` | filter match/exclude | No mapping. |
| `ChannelSynchronizationDualRunTest` | — | zero payment surface | Not applicable. |

**The flagged assumption, stated plainly.** `test_w1` uses `instapay` as a *WooCommerce gateway ID*.
`instapay` is not a WooCommerce core gateway ID, and no document in this repository establishes that
any connected store is configured to emit it. The test passes only through string collision. Read
alongside `test_w3` — which proves that `bacs`, the realistic bank-transfer ID, is unguarded — the
pair demonstrates that the control's WooCommerce coverage is a coincidence rather than a contract.

Per §9 of the task brief this is **flagged as an assumption and left unchanged.** It is not
incorrect (it tests real behaviour for a real input); it is *unrepresentative*, and it should be
revisited when the business vocabulary is approved.

---

## 11. Authoritative Mappings

**None.**

Zero WooCommerce gateway ID → ECOS payment-method mappings exist in code, configuration,
documentation, ADRs, the database, the frontend, or tests.

---

## 12. Undefined Mappings

**All of them.** Every WooCommerce gateway ID a live store could emit is currently undefined, and
the observed population is empty, so the undefined set cannot even be enumerated from this system.

The four ECOS policy keys the business must account for are `cod`, `instapay`, `bank_transfer`,
`mobile_wallet` (plus `credit_card`, and `cash` which is live in the brand policy but absent from
the manual allow-list).

---

## 13. Conflicting Mappings

**No conflicting mapping exists** — because no mapping exists.

One **behavioural conflict** does exist, and it is the substantive risk:

> The same real-world payment method receives **opposite** financial treatment depending on how the
> order entered the system. A bank transfer entered manually is `bank_transfer` → **`required`** and
> blocks fulfilment until payment and a verified proof exist. The same bank transfer arriving from
> WooCommerce as `bacs` is a key miss → **`none`** and reaches a fulfilment-eligible status with no
> payment and no proof.

This is documented in four separate reports as a known, deliberately-unresolved asymmetry. It is
currently **latent**, not active: no order has ever been imported.

Two lesser vocabulary conflicts, reported for completeness and out of scope:

- the live brand policy declares `cash`, which the manual allow-list rejects;
- the live brand policy sets `credit_card` to `required` where the system default declares `optional`.

---

## 14. Business Decision Table

No gateway ID is currently observed in the system, so the table is presented against the ECOS policy
keys that require coverage, plus the gateway IDs named illustratively in the existing decision
records.

| WooCommerce Gateway ID | Current ECOS Value | Proposed ECOS Policy Key | Evidence | Business Decision Required |
|---|---|---|---|---|
| *(none observed)* | — | — | 0 imported orders; `payment_method` NULL on all 17 rows; 0 inbound syncs; both channels disconnected | **Which gateways are the live stores actually configured with?** Nothing can proceed without this. |
| `cod` | `cod` (by string equality) | **do not propose** | Genuine Woo core ID; collides exactly with the ECOS key. `test_w2` relies on it. **INFERRED** | Confirm the collision is intended and may be relied on, rather than left accidental. |
| `bacs` | *(key miss)* → `none` | **do not propose** | Named in OWNER-DECISION-003, DECISION-002 §17/§22, IMPLEMENTATION-002 §13, and `test_w3`; all four decline to map it. **UNDEFINED** | Does a Woo `bacs` order carry the same proof requirement as a manual `bank_transfer`? |
| `ppcp` | *(key miss)* → `none` | **do not propose** | Named illustratively in OWNER-DECISION-003 only. Never observed. **UNDEFINED** | Is PayPal in use at all? If so, which policy key? |
| `cheque` | *(key miss)* → `none` | **do not propose** | Only occurrence is the unrelated Purchasing enum. **UNDEFINED** | In use? |
| `stripe` | *(key miss)* → `none` | **do not propose** | Illustrative mention only; also named as a *prospective outbound* gateway in a different subsystem. **UNDEFINED** | In use for orders, or outbound-charge only? |
| `instapay` | `instapay` (by string equality) | **do not propose** | **Not a WooCommerce core gateway ID.** Used as one only in `test_w1`. No store configuration on record. **CONFLICTING** — the test implies coverage the evidence does not support | Does any store emit a gateway literally named `instapay`? If not, `test_w1` is unrepresentative. |
| `mobile_wallet` | `mobile_wallet` (by string equality) | **do not propose** | Not a Woo core ID. Never observed. **UNDEFINED** | Which Egyptian wallet gateway (Paymob / Fawry / Vodafone Cash / Meeza …) do the stores use? |
| Any custom Egyptian gateway (`paymob`, `fawry`, `myfatoorah`, `valu`, `aman`, `meeza`, …) | *(key miss)* → `none` | **do not propose** | Zero occurrences in production code. **UNDEFINED** | Which are installed, and what does each settle to? |

Per §7 of the task brief, no mapping is proposed on name similarity. Every row above is
**UNDEFINED**, **INFERRED** (string collision only), or **CONFLICTING**. **No row is AUTHORITATIVE,
so no row may be used for implementation.**

---

## 15. Implementation Recommendation

**Do not build anything yet.** Per §8 of the brief, no mapping layer should be created before the
business vocabulary is approved. When it is, the following is sufficient and no architecture change
is warranted:

1. **Collect the vocabulary first, from the stores rather than from this repository.** For each
   connected WooCommerce site, export the enabled gateways (`WooCommerce → Settings → Payments`, or
   `GET /wp-json/wc/v3/payment_gateways`) and record the gateway `id` of each. That list is the
   input this audit cannot supply.

2. **Then add a small explicit map — configuration, not code.** The natural home is the existing
   brand-policy store the gate already reads: a sibling key alongside `payment_proof_policy` in
   `config_brand_policies` → `settings`, e.g. a `payment_gateway_map` of
   `{"bacs": "bank_transfer", "cod": "cod", …}`, resolved through the same
   Channel → Company → Default chain D2-B already implements. This requires **no migration** (the
   column is JSON), **no new source of truth**, and **no second gate**.

3. **One normalisation point only.** `PaymentFulfillmentGate::methodOf()` is the single place the
   effective method is derived; the map belongs there and nowhere else. Adding it in the importer
   instead would put a second interpretation of the same field on a second code path — the exact
   defect `PaymentFulfillmentGate` was created to eliminate.

4. **Decide the unmapped default explicitly.** Today an unrecognised key is non-blocking (`'none'`).
   Once a map exists, the business must state whether an *unmapped* gateway should stay non-blocking
   (permissive, current behaviour) or become proof-required (fail-closed). This is a second business
   decision, distinct from the mapping itself.

5. **Revisit `test_w1`** when the vocabulary lands, replacing `instapay` with a gateway ID a real
   store actually emits.

---

## 16. STOP Conditions

| # | Condition | Status |
|---|---|---|
| 1 | Authoritative gateway → policy-key mapping | **NOT FOUND** — business decision required |
| 2 | Live gateway data from which to derive a mapping | **NOT AVAILABLE** — zero imported orders, zero inbound syncs, both channels disconnected with placeholder credentials |
| 3 | Documented store gateway configuration | **NOT FOUND** — no document names a store or an observed gateway |
| 4 | Behavioural asymmetry (`bacs` unguarded vs `bank_transfer` guarded) | **OPEN** — documented in four reports, latent because nothing has been imported |
| 5 | Unmapped-gateway default (permissive vs fail-closed) | **UNDECIDED** — a second decision the business must make alongside the mapping |

No STOP was worked around. Nothing was implemented, inferred, or invented.

---

## 17. Final Verdict

# AUDIT COMPLETE — BUSINESS MAPPING REQUIRED

The mapping does not exist, and its absence is deliberate and certified rather than accidental: five
independent artefacts record it, and the platform's own inbound contract omits the payment field
while specifying a map for its neighbour. It cannot be derived from this system, because the
observable gateway population is empty — no WooCommerce store has ever been connected and no order
has ever been imported.

**The minimum decision required from the business:** the list of payment gateway IDs enabled on each
connected WooCommerce store, and for each one, which ECOS payment-proof policy key it settles to —
plus a ruling on whether an unmapped gateway stays non-blocking or becomes proof-required.

Until then the current behaviour is safe but incomplete: it is a string coincidence, not a control,
and it should not be described as WooCommerce coverage.

---

**No implementation. No code change. No configuration change. No database mutation. No commit. No deploy.**
