# EPIC-ENTERPRISE-GOLIVE-001 — Phase 2.5
## Product & Governance Decision Register

**Date:** 2026-08-08
**Type:** Decision identification only. No code, no design, no architecture proposals.
**Inputs:** [Enterprise Certification (NO-GO)](ECOS-ERP-ENTERPRISE-CERTIFICATION-FINAL.md) ·
[Phase 1](EPIC-ENTERPRISE-GOLIVE-001-PHASE1-INVESTIGATION.md) ·
[Phase 1.5](EPIC-ENTERPRISE-GOLIVE-001-PHASE1.5-SERVER-ENFORCEMENT.md) ·
[Phase 2 Design](EPIC-ENTERPRISE-GOLIVE-001-PHASE2-DESIGN.md)

**Status:** Awaiting executive approval. **No Phase 3 implementation begins until the Executive
Decision Checklist at the end of this document is complete.**

> ## 🔴 GO-LIVE-CERTIFICATION-001 = **NO-GO** — 2026-08-09
>
> [Final report](GO-LIVE-CERTIFICATION-001-FINAL-PILOT.md). **One verified blocker: the deployed
> artifact is not the certified source.** Repository `HEAD` is **`f0d7822a`** with **35 uncommitted
> files** — every RC-6, D-8, Step 1/2/3/8, Steps 4–7 and D-10 change — and `ecos-app` has been up
> **32 hours**, predating all of it. Part 27 names this an explicit NO-GO condition.
>
> **No business-flow failure, no data loss, no tenant-isolation failure, no authorization bypass, no
> regression.** Phase 3's 8/8 certification stands; its evidence was produced against the worktree,
> not the deployed container. Path to GO: commit → build → deploy → re-run the certified suites
> against the deployed artifact → execute the Part 26 end-to-end flow and the browser matrix. **The
> engineering is done; this is a release exercise.**

> ### UPDATED 2026-08-08 by TASK-GOLIVE-DECISIONS-001
>
> Evidence added from [TASK-GOLIVE-DECISIONS-001 Engineering Report](TASK-GOLIVE-DECISIONS-001-ENGINEERING-REPORT.md).
> **All changes are additive. No owner decision has been recorded, changed or implied.**
>
> | Decision | Change |
> | --- | --- |
> | **SD-4** | **CLOSED** — 15-route matrix complete: 13 PASS · 2 PARTIAL · 0 FAIL · 0 UNVERIFIED |
> | **PD-1** | **Scope greatly reduced** — three of nine questions are already enforced in code |
> | **PD-2** | Two concrete instances added — `/complete` and `/review` |
> | **GD-1** | Two fail-open instances added |
> | **RC-6** | Root cause **PROVEN**; effort `Unknown` → **XS**; disposition still needs approval |
> | **New** | Engineering Defect Annex (D-1…D-7) appended before the checklist |

---

## How this register was built

Two sources were merged:

| Source | Raised |
| --- | --- |
| Certification Stage 0 | `D-A` … `D-F` — 6 decisions |
| Phase 2 Engineering Design | `Q1` … `Q4` — 4 open questions |

**Deduplication and merges applied:**

| Action | Detail |
| --- | --- |
| **Merged** | `D-C` ≡ Phase 2 `Q3` — the same question, asked twice |
| **Merged** | `D-E` + `D-F` → **PD-3** — one revenue policy answered by one group, in one sitting |
| **Merged** | RC-2 governance items (Units editable · Categories ownership · `Allow Negative` toggle) → **GD-2** — all are "who may write shared data" |
| **Split** | `D-C` → **PD-1** (what must be true) + **GD-3** (who may override) — different approvers, different urgency |
| **Split** | `D-A` → **GD-1** (read visibility contract) + **GD-2** (write authority) |
| **Removed** | Phase 2 `Q2` is **not a decision** — it is a question of fact answered by an engineering survey. The *decision* derived from it is **SD-4**. |
| **Added** | 5 operational decisions implied by the certification but never stated as decisions |

**Result: 18 decisions in 4 groups.**

| Group | Count | Blocks implementation | Blocks go-live | Can wait |
| --- | --- | --- | --- | --- |
| 1. Product | 5 | 3 | 2 | 0 |
| 2. Governance | 4 | 1 | 3 | 0 |
| 3. Scope | 4 | 1 | 3 | 0 |
| 4. Operational | 5 | 0 | 4 | 1 |
| **Total** | **18** | **5** | **12** | **1** |

**Five decisions block the start of Phase 3: PD-1, PD-2, PD-5, GD-1, SD-4.**

---
---

# GROUP 1 — PRODUCT DECISIONS

---

## PD-1 — Order transition preconditions

*(supersedes certification `D-C` and Phase 2 `Q3`)*

| | |
| --- | --- |
| **Priority** | **BLOCKS IMPLEMENTATION** |
| **Approver** | Business Operations + Sales leadership |
| **Root causes** | RC-10 (primary), RC-9 (consequence) |

**Background.** Phase 1.5 proved that no layer anywhere checks whether stock is reserved or a
warehouse is assigned before an order may be advanced toward shipping. `Mark Ready` is offered as
the *primary* action on an order whose own Inventory tab reads `Not Reserved` / `Assigned
Warehouse —`. Phase 2 designed the guard mechanism but deliberately did not decide which guards
apply.

**Why this decision exists.** Engineering can build a gate. Engineering cannot decide what the
business considers a legitimate reason to stop a salesperson from promising a delivery date. That
is a commercial policy, and it differs by company.

**The questions requiring an answer** — each is Yes / No / Conditional:

| # | Before an order may reach… | Must this be true? | Status *(updated 2026-08-08)* |
| --- | --- | --- | --- |
| 1 | `in_progress` (activation) | Stock is available — or may an order activate against stock we do not have (backorder)? | ✅ **ALREADY ENFORCED** — `MoveToPreparationWorkflow::execute()` auto-reserves and **diverts to `AwaitingStock`** when stock is insufficient. Needs **ratification, not specification**. |
| 2 | `ready_for_dispatch` | Inventory is **reserved** | ✅ **ALREADY ENFORCED** — `MoveToPreparationWorkflow::guard()` blocks terminal reservation states (*"H-2 fix: prevents entering dispatch with zero stock"*) and auto-reserves otherwise |
| 3 | `ready_for_dispatch` | A **warehouse is assigned** | ⚠️ **THE ONLY GENUINELY OPEN QUESTION** — enforced at **dispatch** (inside `ShipOrderInventoryAction`), not at ready. This is a **sequencing** decision, not an absence. |
| 4 | `ready_for_dispatch` | **Preparation is complete** (is preparation mandatory, or optional for small orders?) | Open — deferrable |
| 5 | `ready_for_dispatch` | **Payment is satisfied** — or do payment terms permit dispatch on credit? | Open — deferrable |
| 6 | `out_for_delivery` | A **driver** is assigned (own fleet only, or also third-party carrier?) | Open — deferrable |
| 7 | `out_for_delivery` | A **vehicle** is assigned | Open — deferrable |
| 8 | `delivered` | **Proof of delivery** is captured | Open — deferrable. *(Note: `CompleteDeliveryWorkflow` already requires `inventory_shipped_at !== null` — an order cannot be delivered if it was never dispatched.)* |
| 9 | Any partial case | May an order ship **partially** when only some lines are reserved? | ✅ **ALREADY ENFORCED** — `PartialReserved` requires explicit manager approval via `/approve-partial-reservation` before dispatch |

> **This decision is now far smaller than when first registered.** SD-4's survey found the
> enforcement layer built, correct and V3-native. The owner is being asked to **ratify existing
> behaviour** for Q1, Q2 and Q9 — and to answer **only Q3** as a new question. Evidence: Engineering
> Report §3.3 and §3.6.

**Business impact.** Questions 2 and 3 are the mechanism by which RC-9's false stock becomes a
delivery promise to a paying customer. Answering them closes the platform's single most damaging
behaviour. Questions 4–9 determine how much operational friction is introduced: each `Yes` stops
work that staff can perform today.

**Engineering impact.** Phase 2 established that questions 2, 3 and 8-minus-POD are derivable from
state the system already holds — no new data is required. Questions 4–7 depend on modules
(Preparation, Logistics) that the certification found to hold **zero records**, so a `Yes` there
gates orders on a module nobody is yet using.

**Default recommendation.** **Answer `Yes` to 2 and 3 only. Defer 4–9 to post-go-live.** This
closes the P0 with the two guards that need no other module to be populated, and introduces no
friction beyond the friction the business already intends.

| Option | Consequence |
| --- | --- |
| **A — Reserve + warehouse only** *(recommended)* | P0 closed. No dependency on empty modules. Payment and POD risk remain unguarded. |
| **B — Full gate (all 9 `Yes`)** | Strongest control. **Orders cannot progress at all** until Preparation and Logistics hold real data — likely halting operations on day one. |
| **C — Warn, do not block** | Zero friction. **Does not close RC-10** — the platform still permits the false promise. Not recommended. |

**Can implementation begin before this decision? NO.** Phase 2 Step 4 cannot define the guard set.

---

## PD-2 — Order lifecycle vocabulary

*(from Phase 2 `Q4` — not previously registered)*

| | |
| --- | --- |
| **Priority** | **BLOCKS IMPLEMENTATION** |
| **Approver** | Product + Business Operations |
| **Root causes** | RC-10 |

**Background.** The platform holds two order-status vocabularies. The persisted enum (V3) uses
`new · in_progress · ready_for_dispatch · out_for_delivery · delivered · …`. The controller that
decides which transitions are legal is written entirely in an older vocabulary (V2) —
`pending · confirmed · processing · preparing · completed · review · rescheduled` — containing
**zero** V3 tokens. Phase 1.5 certified that this mismatch is why no order has advanced in eleven
campaigns.

**Why this decision exists.** Phase 2 found that **only one of seven mappings is mechanical.** The
rest change what an order status *means*:

| V2 | Canonical (V3) | Nature |
| --- | --- | --- |
| `pending` | `new` | Rename — safe |
| `confirmed` + `processing` | `in_progress` | **Two distinct states collapse into one.** The old system treated them differently. |
| `preparing` | ? | **No V3 equivalent.** Is an order being prepared `in_progress` or `ready_for_dispatch`? |
| `completed` | ? | **No V3 equivalent.** Is `delivered` the end of the order, or is there a step after it? |
| `review` | ? | **No V3 equivalent at all.** Was order review abandoned deliberately? |

> ### Evidence added 2026-08-08 — two live instances
>
> This decision is no longer abstract. Two routes are in production today with **no enum case behind them**:
>
> | Route | Behaviour | Classification |
> | --- | --- | --- |
> | **`POST /fulfillment/orders/{order}/complete`** | Guard requires `Delivered`; `execute()` then sets `Delivered` again. **No status transition occurs**, so the engine skips the audit stamps and `OrderEvent` logs `previousValue: null` / `newValue: null`. Financial metadata (revenue, COGS, margin) is still emitted. | **D-5** — engineering defect; **resolution belongs to this decision** |
> | **`POST /fulfillment/orders/{order}/review`** | Sets `OnHold`. The route, controller method and workflow class are all named `review`; the error message says *"cannot be placed On Hold"*. | **D-6** — stale naming; **resolution belongs to this decision** |
>
> Deciding `completed` and `review` closes both. Evidence: Engineering Report §3.4.

**Business impact.** These names appear on every order screen, in every export, and in any report
built on order status. Collapsing `confirmed` and `processing` removes a distinction that
operations staff may rely on. Deciding `delivered` is terminal means there is no post-delivery
state before archival.

**Engineering impact.** The mapping is the input to Phase 2 Steps 4–7. A wrong guess does not fail
loudly — it silently changes order semantics.

**Default recommendation.** **Adopt V3 as canonical, unchanged. Retire `review` and `completed`;
treat `preparing` as an Operations wave state, not an order state; accept the
`confirmed`/`processing` merge.** V3 is already what the database stores, so this requires no data
migration and no customer-visible renaming.

| Option | Consequence |
| --- | --- |
| **A — V3 as-is** *(recommended)* | No migration. Three concepts retired. Requires operations to confirm they are not in use. |
| **B — Reintroduce missing states** | Preserves old distinctions. Requires schema and UI change, and re-opens a design the team already moved away from. |
| **C — Leave both vocabularies** | Zero cost today. **This is the current state, and it is the defect.** Not viable. |

**Can implementation begin before this decision? NO.** Steps 4–7 all consume the mapping.

---

## PD-3 — Revenue definition and posting trigger

*(merges certification `D-E` + `D-F`)*

| | |
| --- | --- |
| **Priority** | **BLOCKS GO-LIVE** |
| **Approver** | Finance leadership + Product |
| **Root causes** | RC-9 (executive layer), RC-11 |

**Background.** Two related gaps. **(a)** The Executive workspace reads `ORDERS —` while the
Dashboard reads `2` — the certification found both surfaces internally correct but measuring
different things: booked sales versus posted revenue. **(b)** EGP 21,132.00 of orders produced
**zero** ledger postings, and no invoice action exists anywhere in the platform.

**Why this decision exists.** "Revenue" is an accounting policy, not a calculation. Until the
business states when a sale becomes revenue, no dashboard number can be certified and the posting
pipeline has no trigger to subscribe to.

**Business impact.** Every executive figure, every commission calculation and every tax
declaration depends on this. Two internally-correct surfaces showing different numbers is, to a
board, indistinguishable from a broken system.

**Engineering impact.** The Finance posting pipeline is built and proven (F1–F5 complete,
balanced journals verified). It is **waiting for a trigger event that has never been defined.**
This is a configuration decision far more than an engineering one.

**Default recommendation.** **Post revenue on delivery. Executive shows both figures, explicitly
labelled — "Booked" and "Recognised".** Delivery is an event the platform already emits and can
evidence; showing both numbers removes the contradiction without forcing a premature choice.

| Option | Consequence |
| --- | --- |
| **A — Post on delivery, show both** *(recommended)* | Matches physical reality. Both dashboard numbers become explainable. Requires the delivery event to be reliable — see PD-1 Q8. |
| **B — Post on order** | Simplest trigger. **Recognises revenue for goods never shipped.** Likely unacceptable to an auditor. |
| **C — Post on invoice** | Most orthodox. **No invoice capability exists** — this makes SD-1 a go-live blocker. |

**Can implementation begin before this decision? YES** — RC-9 and RC-10 remediation is unaffected.
Finance and Executive certification cannot complete without it.

---

## PD-4 — Customer identity owner

*(certification `D-D`)*

| | |
| --- | --- |
| **Priority** | **BLOCKS GO-LIVE** |
| **Approver** | Product + Architecture |
| **Root causes** | RC-11 |

**Background.** CRM reports `Total customers 1`. Orders holds `2`. The certification recorded this
as **proven divergence, not mere non-integration** — two customer populations already exist and
have already drifted apart.

**Why this decision exists.** Both modules currently behave as though they own customer identity.
Only one can. This is an ownership decision with a data-reconciliation consequence, and it must be
made before the populations diverge further.

**Business impact.** Every customer-facing figure is currently ambiguous — customer counts, order
history, loyalty balances, credit exposure. Duplicate customer records also mean duplicate
communications to real people.

**Engineering impact.** CRM's C1 Customer Foundation was explicitly built as the customer identity
SSOT and already enriched the shared `customers` table. The divergence therefore suggests an
unwired write path rather than a design conflict — but **the certification recorded the cause as
`Unknown`, requiring diagnosis before estimation.**

**Default recommendation.** **CRM (C1) owns customer identity; Orders references it.** This
matches the architecture already built and requires no redesign — only reconciliation of the two
populations.

| Option | Consequence |
| --- | --- |
| **A — CRM owns identity** *(recommended)* | Matches existing design. Requires a one-time reconciliation of divergent records. |
| **B — Orders owns identity** | Contradicts a completed module. Large rework. |
| **C — Defer** | Divergence grows daily. Reconciliation cost rises with every order taken. |

**Can implementation begin before this decision? YES** for RC-9/RC-10. **NO** for any customer
reporting certification.

---

## PD-5 — Channel stock status: ownership and editability

*(from Phase 2 `Q1` and Step 8 — not previously registered)*

| | |
| --- | --- |
| **Priority** | **BLOCKS IMPLEMENTATION** |
| **Approver** | Product + E-commerce/Channel owner |
| **Root causes** | RC-9 |

**Background.** `products.stock_status` (`instock` / `outofstock` / `onbackorder`) is a WooCommerce
synchronisation attribute. It is **stored, human-editable in three request paths, and displayed in
the ERP grid as though it described ERP stock.** This is the mechanism producing
`Stock Status: In Stock` beside `On Hand 0` — the platform's most visible false assertion.

**Why this decision exists.** Two questions engineering cannot answer:

1. **Does the platform publish this value back out to WooCommerce?** If yes, changing what writes
   it changes what the storefront advertises to real shoppers.
2. **Should a human be able to edit it at all?** It is currently editable, and someone may be using
   that as a manual storefront override.

A third, consequential: once the ERP's `In Stock` filter reflects real availability instead of the
channel field, **the same filter will return a different set of products.** Users will notice.

**Business impact.** Question 1 touches live storefront behaviour and therefore real orders.
Removing human editability (Phase 2 Step 8) removes a capability someone may depend on — it is the
only step in the entire plan that takes something away.

**Engineering impact.** Phase 2's design retains the field deliberately rather than deleting it,
precisely because Q1 is unanswered. Steps 2 and 8 cannot proceed without it; Step 1 can.

**Default recommendation.** **Retain the field as an explicitly labelled channel attribute. Make
the ERP grid show real availability. Remove human editability only after confirming no manual
override workflow exists.**

| Option | Consequence |
| --- | --- |
| **A — Retain, relabel, restrict** *(recommended)* | Storefront behaviour unchanged. False assertion removed. Filter semantics change — needs user communication. |
| **B — Delete the field** | Cleanest model. **Unsafe until Q1 is answered** — may change what the storefront advertises. |
| **C — Leave as is** | Zero cost. **RC-9 stays open.** Not viable. |

**Can implementation begin before this decision? NO** — Phase 2 Step 1 may proceed (additive,
nothing consumes it); **Steps 2 and 8 may not.**

---
---

# GROUP 2 — GOVERNANCE DECISIONS

---

## GD-1 — The tenant scope contract

*(certification `D-A`, read-visibility half)*

| | |
| --- | --- |
| **Priority** | **BLOCKS IMPLEMENTATION** *(of RC-1 remediation)* · **BLOCKS GO-LIVE** |
| **Approver** | Executive + Product + Architecture |
| **Root causes** | RC-1, RC-2 |

**Background.** Product cost, markup and gross margin; supplier identities and balances; and
complete bills of materials are served to companies that own none of them — confirmed at request
level in **4 of 4 modules where data existed to leak**. `/api/warehouses` is called twice on one
page: once scoped, once not.

**Why this decision exists.** Engineering cannot simply "turn isolation on". The certification
found that strict isolation **would break what appears to be a deliberate group-buyer capability**
— `All companies` browsing on Purchases and Recipes. Whether cross-company visibility is a feature
or a leak is a business question, and the answer differs per entity.

**Required output:** every entity classified **GLOBAL** / **SHARED** / **COMPANY SCOPED**, plus —
for anything not COMPANY SCOPED — the permission that governs it.

> ### Evidence added 2026-08-08 — the scopes fail **open**
>
> Two tenant global scopes were traced and both return **all companies' rows** when the actor's
> `company_id` is `NULL`:
>
> | Model | Behaviour | Defect |
> | --- | --- | --- |
> | `Warehouse` | Global scope returns early on `NULL` (commented *"super-admin sees all warehouses"*) **and** the repository's own filter is skipped because the string is empty. **Both guards fail open at once.** | **D-3** |
> | `Order` | Identical pattern — a `NULL`-company actor can transition any company's orders | **D-4** |
>
> **This decision must state explicitly whether "no company means see everything" is intended.** If it
> is, it needs a named permission; if it is not, both scopes must fail closed. Engineering cannot
> choose — the comment asserts it is deliberate. Evidence: Engineering Report §2.8(b), §3.5(3).

**Business impact.** In a hosted multi-tenant deployment this is a reportable disclosure event and
a probable contractual breach. A BOM discloses composition, proportions and input cost — a
competitor learns how to make the product and what it costs.

**Engineering impact.** `S` per endpoint but `M` overall, and it must fail closed. Without the
classification, engineering would have to guess per entity — and a wrong guess in either direction
is serious: guess "scoped" and a legitimate capability breaks; guess "global" and the leak stays.

**Default recommendation.** **Classify all entities as COMPANY SCOPED by default. Promote to
SHARED or GLOBAL only by explicit, individually justified exception, each behind a named
permission.** Fail closed.

| Option | Consequence |
| --- | --- |
| **A — Deny by default, exceptions justified** *(recommended)* | Safest. Some current cross-company workflows will break and must be re-enabled deliberately. |
| **B — Preserve current behaviour, scope selectively** | No workflow disruption. **Any entity overlooked continues to leak.** |
| **C — Single-tenant deployment only** | Removes the risk entirely — see **OD-2**. Constrains the commercial model. |

**Can implementation begin before this decision? NO** for RC-1. **YES** for RC-9/RC-10.

---

## GD-2 — Write authority over shared and policy data

*(certification `D-A` write half + RC-2 items, merged)*

| | |
| --- | --- |
| **Priority** | **BLOCKS GO-LIVE** |
| **Approver** | Executive + Product |
| **Root causes** | RC-2 |

**Background.** Three findings share one cause — nobody has decided who may *write* shared data:

- **Units of Measure** is global reference data and is **tenant-editable and deletable**
- **Categories** has no decided ownership model at all
- **`Allow Negative`** stock is a one-click row toggle, **defaulted ON**, with no permission gate

**Why this decision exists.** GD-1 decides who may *see* shared data. This decides who may
*change* it. One tenant editing a global unit changes it for every other tenant.

**Business impact.** A tenant deleting a shared unit of measure can corrupt data across every
other company on the platform. `Allow Negative` defaulted ON means the platform will let stock go
negative by default — which directly undermines any guard decided in PD-1.

**Engineering impact.** `XS` to enforce once decided — **`L` if Categories must be split and
migrated.** The Categories answer is the expensive one.

**Default recommendation.** **Global reference data becomes read-only to tenants, maintained
centrally. `Allow Negative` becomes an administrator-only setting, defaulted OFF. Categories are
classified per GD-1.**

| Option | Consequence |
| --- | --- |
| **A — Central maintenance, admin-gated policy** *(recommended)* | Removes cross-tenant corruption risk. Tenants lose self-service on reference data — a support workload. |
| **B — Tenant-owned copies of reference data** | Full self-service. Requires split and migration — the `L` path. |
| **C — Status quo** | Zero cost. Cross-tenant corruption remains possible. |

**Can implementation begin before this decision? YES** — no Phase 3 step depends on it.

---

## GD-3 — Transition override authority

*(certification `D-C`, second half — split from PD-1)*

| | |
| --- | --- |
| **Priority** | **BLOCKS GO-LIVE** |
| **Approver** | Business Operations + Compliance |
| **Root causes** | RC-10 |

**Background.** PD-1 decides what must be true before an order advances. This decides what happens
when a supervisor needs to advance it anyway — the urgent customer, the known-good exception, the
Friday afternoon.

**Why this decision exists.** Every gate in an operational system eventually meets a legitimate
exception. If no override exists, staff will find a workaround — and workarounds are invisible. If
an override exists without audit, the guard provides assurance it does not actually deliver.

**Business impact.** An unaudited override means the platform cannot answer "who promised stock we
did not have?" — the exact question RC-10 exists to prevent.

**Engineering impact.** **None on the critical path.** Phase 2's guards can ship as hard blocks and
gain an override later without redesign. This decision does not delay Phase 3.

**Default recommendation.** **Ship hard blocks first. Add a permission-gated, mandatory-reason,
fully-audited override before go-live** — only if operations demonstrate a real need during
validation.

| Option | Consequence |
| --- | --- |
| **A — Hard block now, audited override before go-live** *(recommended)* | Guard is real from day one. Override designed against observed need, not imagined need. |
| **B — Override from day one** | No operational disruption. Risks the override becoming the normal path, nullifying PD-1. |
| **C — No override ever** | Strongest control. Staff will build off-system workarounds. |

**Can implementation begin before this decision? YES.**

---

## GD-4 — Data export governance

| | |
| --- | --- |
| **Priority** | **BLOCKS GO-LIVE** |
| **Approver** | Executive + Compliance |
| **Root causes** | RC-2 |

**Background.** CSV export exists on approximately **11 grids** with **no permission gate and no
audit record**. Combined with RC-1, any user can currently export data belonging to companies that
are not theirs, and no trace remains.

**Why this decision exists.** Export is the point at which data leaves the platform's control
entirely. Once exported, no later access control applies.

**Business impact.** This converts RC-1 from an exposure into an exfiltration path. In a hosted
deployment, an unauditable bulk-export capability is difficult to defend to a customer's security
review.

**Engineering impact.** `XS` — permission gating and audit logging on a known, enumerable set of
endpoints. The platform already has an audit facility.

**Default recommendation.** **Gate every export behind an explicit permission and write an audit
record for each — who, what, when, how many rows.**

| Option | Consequence |
| --- | --- |
| **A — Permission + audit on all exports** *(recommended)* | Cheap. Closes the exfiltration path. Some users lose export access until roles are assigned. |
| **B — Audit only** | No workflow disruption. Records the leak rather than preventing it. |
| **C — Disable export until go-live** | Maximum safety, immediate. Removes a capability users likely rely on. |

**Can implementation begin before this decision? YES.**

---
---

# GROUP 3 — SCOPE DECISIONS

---

## SD-1 — The absent capability portfolio

*(certification `D-B`)*

| | |
| --- | --- |
| **Priority** | **BLOCKS GO-LIVE** |
| **Approver** | Executive + Product |
| **Root causes** | RC-3 |

**Background.** Approximately **55 absent capabilities** across all eleven modules, including no
user administration, no price lists, no stock entry path, no quotations, no order approval, no tax,
no returns, no GL browser, no cash flow, no bank reconciliation, no CRM activities, and no
reporting of any kind. RC-3 alone explains **21 of 52 findings**.

**Why this decision exists.** The certification's explicit instruction: **decide all ~55 once, not
module by module.** Deciding them individually as each is discovered produces an unbounded backlog
and eleven separate go-live arguments.

**Business impact.** This decision defines what the product *is* at launch. It is the single
largest determinant of the go-live date.

**Engineering impact.** `XL` in total. But the decision itself is what makes the number knowable —
today the remaining work cannot be estimated at all.

**Default recommendation.** **Sort all ~55 into three fixed buckets in one session — `LAUNCH` /
`POST-LAUNCH` / `NOT PLANNED` — and treat the `LAUNCH` bucket as the definition of go-live scope.**
Anything not sorted is `NOT PLANNED` by default.

| Option | Consequence |
| --- | --- |
| **A — Single triage session, three buckets** *(recommended)* | Scope becomes finite and estimable. Requires several hours of senior product time. |
| **B — Decide per module as encountered** | No upfront cost. Scope never converges; every module re-opens the go-live debate. |
| **C — Build all before launch** | Complete product. Launch measured in quarters, not weeks. |

**Can implementation begin before this decision? YES** for blocker remediation (RC-9, RC-10, RC-1).
**NO** for anything inside RC-3.

---

## SD-2 — Advertised-but-absent modules

| | |
| --- | --- |
| **Priority** | **BLOCKS GO-LIVE** |
| **Approver** | Executive + Product + Sales |
| **Root causes** | RC-3, RC-8 |

**Background.** The command palette advertises **Manufacturing** (hard 404) and **Reports**
("Coming Soon"). These are not missing features inside a module — they are **two entire modules**
the navigation promises and the platform does not have. Related: `Settings` and `Configuration OS`
are one page under two nav entries, and `/app/accounting/financial-statements` 404s while the page
lives at `/statements`.

**Why this decision exists.** This is separated from SD-1 because it is a **commercial
representation** question, not a feature-priority one. The product currently tells a buyer that
capabilities exist, then tells them they do not.

**Business impact.** A prospect evaluating the platform finds Manufacturing in the menu and a 404
behind it. That is worse than the capability being absent — it reads as a broken product rather
than a scoped one.

**Engineering impact.** `XS` to remove the entries and fix the route mismatches. The certification
recommends doing this first regardless, as the cheapest visible improvement in the entire backlog.

**Default recommendation.** **Remove both entries from navigation and from all commercial material
until the capabilities exist. Fix the three route/label mismatches immediately.**

| Option | Consequence |
| --- | --- |
| **A — Remove from nav and collateral** *(recommended)* | Product presents honestly. `XS` effort. Visible scope reduction must be communicated to anyone already sold on it. |
| **B — Keep, marked "Coming Soon"** | Preserves the roadmap signal. Still shows an unfinished product to every user, every day. |
| **C — Build before launch** | Two modules. Moves go-live by quarters. |

**Can implementation begin before this decision? YES.**

---

## SD-3 — Minimum legal operating set

| | |
| --- | --- |
| **Priority** | **BLOCKS GO-LIVE** |
| **Approver** | Executive + Finance + Legal |
| **Root causes** | RC-3, RC-11 |

**Background.** The certification asked directly whether the platform can *legally* operate. The
absent set includes **tax, invoicing, returns and bank reconciliation**; EGP 21,132.00 of orders
produced zero ledger postings; and no invoice action exists anywhere.

**Why this decision exists.** Feature priority is a product judgement. Statutory obligation is
not — it is determined externally, and the platform either satisfies it or the customer cannot use
it to run a business.

**Business impact.** If tax and invoicing are statutory in the target jurisdiction, they are not
`POST-LAUNCH` items regardless of how SD-1 ranks them. This decision can override SD-1's
prioritisation.

**Engineering impact.** Determines whether the Finance posting pipeline — built, tested and idle —
must be wired before go-live or may follow. It also interacts directly with **PD-3 Option C**:
choosing invoice-triggered revenue makes invoicing a hard blocker.

**Default recommendation.** **Obtain a written legal determination for the target jurisdiction
before setting the go-live date.** Do not infer this from the backlog.

| Option | Consequence |
| --- | --- |
| **A — Legal determination first** *(recommended)* | Go-live date is defensible. Adds a short calendar dependency outside engineering control. |
| **B — Launch and add compliance after** | Fastest. Exposes the customer to statutory risk on transactions already processed. |
| **C — Assume full compliance needed** | Safe. May build capabilities the jurisdiction does not require. |

**Can implementation begin before this decision? YES** for engineering. **NO** for setting a
go-live date.

---

## SD-4 — Scope of transition enforcement

*(derived from Phase 2 `Q2` — the decision, not the survey)*

| | |
| --- | --- |
| **Priority** | ~~BLOCKS IMPLEMENTATION~~ → **CLOSED 2026-08-08** |
| **Approver** | Product + Engineering leadership |
| **Root causes** | RC-10 |

> ### ✅ CLOSED — the survey is complete
>
> **All 15 routes surveyed. 13 PASS · 2 PARTIAL · 0 FAIL · 0 UNVERIFIED.**
>
> The decision is resolved by evidence rather than by choice: **every dedicated route already
> enforces its contract independently.** Each bypasses the broken `resolveTransitionWorkflow()` and
> calls `FulfillmentEngine::run()`, which invokes `workflow->guard()` outside the transaction; guard
> failures return **422**. All 22 workflows use the V3 enum — the V2 vocabulary exists in exactly one
> place, the generic endpoint.
>
> **Option A was therefore already true.** There is no bypass to close and no scope choice to make.
>
> **Two gaps remain, both flagged rather than assumed:**
> - The **13 bulk routes** were outside SD-4's stated scope and are **UNVERIFIED** (Engineering Report §3.5(5))
> - The **generic `/transition` endpoint** remains **FAIL**, and it is the one the order drawer calls
>
> Evidence: Engineering Report §3.

**Background.** Phase 1.5 traced the generic `/transition` endpoint — the one the order drawer
uses — and certified it. It also recorded a scope limit: **fifteen dedicated routes** (`/confirm`,
`/dispatch`, `/move-to-preparation`, `/complete-delivery`, `/awaiting-stock`, …) exist alongside it,
call their workflows directly, and **were not traced.**

**Why this decision exists.** Phase 2 Step 5 lists the survey of these routes as a hard
prerequisite. The decision is whether Phase 3 guards **every** lifecycle entry point or only the
one proven defective.

**Business impact.** If the dedicated routes are reachable from any UI, a guard applied only to the
generic endpoint is bypassable — the control would exist on paper and not in practice.

**Engineering impact.** Scoping to the generic endpoint only is materially smaller. Scoping to all
sixteen entry points requires the survey first and expands the change surface. **The survey itself
is engineering work and should be commissioned regardless — it is the input to this decision, not a
substitute for it.**

**Default recommendation.** **Commission the survey now, then guard every entry point that is
reachable from a user interface.** Unreachable routes may be deferred and documented.

| Option | Consequence |
| --- | --- |
| **A — Survey, then guard all reachable entry points** *(recommended)* | The guard is genuinely enforced. Scope known only after the survey — a short, bounded unknown. |
| **B — Generic endpoint only** | Smallest change, fastest. **Guard may be trivially bypassable.** Cannot be certified. |
| **C — Guard all sixteen unconditionally** | Maximum assurance, no survey needed. Largest change surface; may touch working code unnecessarily. |

**Can implementation begin before this decision? NO** — Phase 2 Step 5 names it as a prerequisite.

---
---

# GROUP 4 — OPERATIONAL DECISIONS

---

## OD-1 — Status of existing platform data

| | |
| --- | --- |
| **Priority** | **BLOCKS GO-LIVE** |
| **Approver** | Executive + Operations |
| **Root causes** | RC-6, RC-11 |

**Background.** The platform currently holds two orders created 2026-08-07, both stalled; an empty
stock ledger; zero fulfilments, shipments and deliveries; a company created with **no currency**;
and at least one warehouse that was created successfully (`POST 201`) but which the system denies
exists (RC-6).

**Why this decision exists.** Some of this data is invalid by construction — the currency-less
company means no monetary amount attached to it has defined meaning. Whether it is carried into
production is a business decision, not a technical one.

**Business impact.** Carrying invalid records forward means the first production reports are built
on data the certification already identified as unsound.

**Engineering impact.** A clean baseline makes post-fix verification unambiguous: any contradiction
observed after cutover is new, not inherited.

**Default recommendation.** **Treat all current data as test data. Establish a clean baseline at
cutover, seeded only through validated onboarding flows.**

| Option | Consequence |
| --- | --- |
| **A — Clean baseline** *(recommended)* | Unambiguous verification. Onboarding must be re-performed. |
| **B — Carry data forward** | No re-entry effort. Known-invalid records enter production permanently. |
| **C — Selective cleanup** | Middle path. Requires per-record judgement and a defensible audit trail. |

**Can implementation begin before this decision? YES.**

---

## OD-2 — Pilot versus multi-tenant launch

| | |
| --- | --- |
| **Priority** | ~~BLOCKS GO-LIVE~~ → ✅ **DECIDED 2026-08-08** |
| **Approver** | Executive |
| **Root causes** | RC-1, RC-2 |

> # ✅ OWNER DECISION RECORDED — **OD-2 = PILOT**
>
> **Decided by the owner, 2026-08-08.** Recorded verbatim; not made or inferred by engineering.
>
> > ECOS will launch initially as a controlled Pilot rather than Multi-Tenant onboarding from day one.
> >
> > - Phase 3 may proceed.
> > - Tenant #2 onboarding is gated.
> > - Supplier tenant-isolation must be resolved before Tenant #2.
> > - ScopeResolver latent tenant-isolation risk must be assessed/resolved before Multi-Tenant expansion.
> > - No claim is made that the platform is fully Multi-Tenant certified.
>
> ### Consequent re-classification
>
> | Item | Was | Now |
> | --- | --- | --- |
> | **GD-1** — tenant scope contract | BLOCKS IMPLEMENTATION | **TENANT-2 GATE** |
> | **GD-2** — write authority over shared data | BLOCKS GO-LIVE | **TENANT-2 GATE** |
> | **GD-4** — export governance | BLOCKS GO-LIVE | **TENANT-2 GATE** |
> | **RC-1** — tenant scope not applied | BLOCKER | **Not exploitable under one company — TENANT-2 GATE** |
> | **RC-2** — no governance model | BLOCKER | **TENANT-2 GATE** |
> | **D-8** — Supplier fail-open | BLOCKING | **TENANT-2 GATE — owner named it explicitly** |
> | **D-9** — ScopeResolver | POST-GO-LIVE | **MULTI-TENANT EXPANSION GATE — owner named it explicitly** |
>
> ### The gate must be technically enforced
>
> Nothing in the platform today prevents a second company being created, and RC-1 is **invisible on a
> single-company system** — that is how it survived eleven UAT campaigns. The pilot's safety rests
> entirely on this gate holding. **Engineering recommendation carried forward: D-8 (Supplier) is a
> one-file change using an already-proven pattern; doing it now rather than at the gate removes the
> last *reachable* instance of the fail-open class.** Awaiting authorization — not implemented.

**Background.** RC-1 and RC-2 are the platform's most severe findings — and the certification
recorded that both were **invisible on a single-company demonstration.** They are only exploitable
when a second company's data exists.

**Why this decision exists.** This is the highest-leverage decision in the register. A
single-company pilot moves GD-1 and GD-2 off the go-live critical path entirely; a multi-tenant
launch makes them absolute blockers.

**Business impact.** Determines both the launch date and the commercial model. A pilot delivers
value to one customer sooner; multi-tenant launch delivers the intended business but requires the
full isolation programme first.

**Engineering impact.** Under a pilot, the blocking set reduces to **RC-9, RC-10 and RC-6** —
substantially smaller, and all three are already designed or diagnosable.

**Default recommendation.** **Launch as a single-company pilot. Complete the isolation programme
(GD-1, GD-2, GD-4) as the gate to onboarding a second tenant.**

> ### Decision brief prepared 2026-08-08 — still unsigned
>
> A full brief covering both options, the blocker set under each, the tenant-2 gates and the
> operational risks is at **Engineering Report §1**. The engineering recommendation is **Pilot**,
> with one stated reservation:
>
> > *The pilot's safety depends entirely on the tenant-2 gate holding. If the organisation cannot
> > commit to enforcing it **technically**, Pilot is not materially safer than Multi-Tenant — it is
> > Multi-Tenant with the risk hidden.*
>
> **The decision remains the owner's and has not been made.**

| Option | Consequence |
| --- | --- |
| **A — Single-company pilot** *(recommended)* | Fastest safe path to production value. Multi-tenant revenue deferred. Requires a hard, enforced rule that no second tenant is onboarded until isolation is certified. |
| **B — Multi-tenant launch** | Full commercial model immediately. GD-1, GD-2, GD-4 and RC-1 all become blockers. |
| **C — Delay all launch** | No risk taken. No value delivered; no production feedback. |

**Can implementation begin before this decision? YES** — but this decision **changes which other
decisions block go-live**, so it should be taken first.

---

## OD-3 — Enforcement activation risk acceptance

| | |
| --- | --- |
| **Priority** | **BLOCKS GO-LIVE** |
| **Approver** | Executive + Business Operations |
| **Root causes** | RC-10 |

**Background.** Phase 1.5 established that the generic transition endpoint currently rejects
**every** transition for current-vocabulary orders — including legal ones. The protection is
accidental. Phase 2 therefore requires Steps 4–6 to ship as **one atomic release**: repairing the
vocabulary before the guards are live would make illegal transitions genuinely possible for the
first time.

**Why this decision exists.** When enforcement activates, two things change on the same day.
Transitions that are currently impossible become possible — and transitions staff may *expect* to
work will begin failing with a guard message. Someone must accept that operational discontinuity.

**Business impact.** The first day after activation is the first day orders can actually progress
through the lifecycle. It is also the first day a salesperson is refused a `Mark Ready`. Both are
intended; neither should be a surprise.

**Engineering impact.** None beyond the sequencing already designed. This is an acceptance, not a
change — but it constrains release planning: **Steps 4, 5 and 6 cannot be split across releases.**

**Default recommendation.** **Accept the atomic release constraint. Activate in a controlled
window with operations briefed on the new guard messages in advance.**

| Option | Consequence |
| --- | --- |
| **A — Atomic release, briefed window** *(recommended)* | Guards and vocabulary land together. Requires coordinated release and user communication. |
| **B — Split across releases** | Smaller increments. **Creates a window in which illegal transitions succeed** — strictly worse than today. |
| **C — Feature-flag activation** | Reversible. Adds a flag path that must itself be tested in both states. |

**Can implementation begin before this decision? YES.**

---

## OD-4 — Integration verification ownership

| | |
| --- | --- |
| **Priority** | **BLOCKS GO-LIVE** |
| **Approver** | Executive + Operations |
| **Root causes** | RC-11 |

**Background.** **In eleven campaigns, not one cross-module transaction was observed end to end.**
Orders runs a live distribution probe against a Logistics module holding zero records. RC-11
explains 8 findings and its effort is recorded as `Unknown` — diagnosis first.

**Why this decision exists.** The modules are individually complete and individually certified. What
has never been demonstrated is that they work *together*. Someone must own proving each seam and
must be named before go-live, or every integration will be assumed working by the module on each
side of it.

**Business impact.** An unexercised integration fails the first time a real customer uses it — in
production, under load, with a real order.

**Engineering impact.** Requires an end-to-end scenario per seam with recorded evidence, not unit
tests. This is verification work, and it cannot start until PD-1, PD-2 and SD-4 make the order
lifecycle traversable at all.

**Default recommendation.** **Name a single owner per integration seam. Require one recorded
end-to-end transaction per seam as a go-live gate — order → reservation → preparation → dispatch →
delivery → posting.**

| Option | Consequence |
| --- | --- |
| **A — Named owner, one recorded transaction per seam** *(recommended)* | Integration risk becomes evidenced rather than assumed. Adds a verification phase before go-live. |
| **B — Rely on existing module tests** | No added time. **This is the current position, and it produced the NO-GO.** |
| **C — Verify only the revenue-critical path** | Focused. Leaves non-revenue seams unproven. |

**Can implementation begin before this decision? YES.**

---

## OD-5 — Re-certification evidence standard

| | |
| --- | --- |
| **Priority** | **CAN WAIT** |
| **Approver** | Executive |
| **Root causes** | All |

**Background.** The certification returned **NO-GO** with a platform average of **2.8/10** across
eleven campaigns. It did not define what evidence would convert that verdict to GO.

**Why this decision exists.** Without an agreed standard, re-certification becomes a negotiation
after the work is done rather than a target while it is being done. The team should know what it is
building toward.

**Business impact.** Defines when the platform may be sold and onboarded. An unclear bar tends to
resolve toward optimism under commercial pressure.

**Engineering impact.** Determines how much verification effort to plan. A "re-run all eleven
campaigns" standard is materially more work than "re-run the four blocker campaigns".

**Default recommendation.** **Re-run the campaigns covering the blocker root causes — 1, 5, 6 and
whichever cover the seams named in OD-4 — with the original rules, and require the specific
observed contradictions to be gone.** Not a full eleven-campaign re-run.

| Option | Consequence |
| --- | --- |
| **A — Targeted re-run of blocker campaigns** *(recommended)* | Proportionate. Assumes non-blocker areas did not regress — mitigated by the existing quality ratchet. |
| **B — Full eleven-campaign re-run** | Highest confidence. Repeats the full certification cost. |
| **C — Engineering sign-off only** | Fastest. **The original NO-GO came from customer-perspective testing that engineering sign-off did not catch.** |

**Can implementation begin before this decision? YES** — but it should be agreed before Phase 3
completes, not after.

---
---

# EXECUTIVE DECISION CHECKLIST

**No Phase 3 implementation begins until every `BLOCKS IMPLEMENTATION` row is signed.**

## Sequence

**Take OD-2 first.** It is the only decision that changes which other decisions are blocking.

```
  OD-2  Pilot vs multi-tenant
    │
    ├── PILOT ──────► blocking set = PD-1, PD-2, PD-5, SD-4   (+ RC-6 diagnosis)
    │                 GD-1 / GD-2 / GD-4 become the gate to tenant #2
    │
    └── MULTI ──────► blocking set = the above + GD-1, GD-2, GD-4
```

## Sign-off table

### Session 1 — Executive, first (30 minutes)

| ID | Decision | Priority | Owner | Decision | Signed |
| --- | --- | --- | --- | --- | --- |
| **OD-2** | Pilot vs multi-tenant launch | BLOCKS GO-LIVE | Executive | ☐ A ☐ B ☐ C | ☐ |

### Session 2 — Unblocks Phase 3 (must complete before any code)

| ID | Decision | Priority | Owner | Decision | Signed |
| --- | --- | --- | --- | --- | --- |
| **PD-1** | Order transition preconditions | **BLOCKS IMPL** | Business Ops + Sales | ☐ A ☐ B ☐ C | ☐ |
| **PD-2** | Order lifecycle vocabulary | **BLOCKS IMPL** | Product + Business Ops | ☐ A ☐ B ☐ C | ☐ |
| **PD-5** | Channel stock status ownership | **BLOCKS IMPL** | Product + Channel | ☐ A ☐ B ☐ C | ☐ |
| **SD-4** | Scope of transition enforcement | **BLOCKS IMPL** | Product + Eng leadership | ☐ A ☐ B ☐ C | ☐ |
| **GD-1** | Tenant scope contract | **BLOCKS IMPL** *(RC-1 only)* | Exec + Product + Arch | ☐ A ☐ B ☐ C | ☐ |

> **Note on GD-1:** required before RC-1 remediation. If **OD-2 = Pilot**, RC-1 is not on the
> go-live path and GD-1 may follow Session 3 — but it still gates tenant #2.

### Session 3 — Required before go-live (may run in parallel with Phase 3)

| ID | Decision | Priority | Owner | Decision | Signed |
| --- | --- | --- | --- | --- | --- |
| **PD-3** | Revenue definition & posting trigger | BLOCKS GO-LIVE | Finance + Product | ☐ A ☐ B ☐ C | ☐ |
| **PD-4** | Customer identity owner | BLOCKS GO-LIVE | Product + Architecture | ☐ A ☐ B ☐ C | ☐ |
| **GD-2** | Write authority over shared data | BLOCKS GO-LIVE | Exec + Product | ☐ A ☐ B ☐ C | ☐ |
| **GD-3** | Transition override authority | BLOCKS GO-LIVE | Business Ops + Compliance | ☐ A ☐ B ☐ C | ☐ |
| **GD-4** | Data export governance | BLOCKS GO-LIVE | Exec + Compliance | ☐ A ☐ B ☐ C | ☐ |
| **SD-1** | Absent capability portfolio | BLOCKS GO-LIVE | Exec + Product | ☐ A ☐ B ☐ C | ☐ |
| **SD-2** | Advertised-but-absent modules | BLOCKS GO-LIVE | Exec + Product + Sales | ☐ A ☐ B ☐ C | ☐ |
| **SD-3** | Minimum legal operating set | BLOCKS GO-LIVE | Exec + Finance + Legal | ☐ A ☐ B ☐ C | ☐ |
| **OD-1** | Status of existing platform data | BLOCKS GO-LIVE | Exec + Operations | ☐ A ☐ B ☐ C | ☐ |
| **OD-3** | Enforcement activation risk | BLOCKS GO-LIVE | Exec + Business Ops | ☐ A ☐ B ☐ C | ☐ |
| **OD-4** | Integration verification ownership | BLOCKS GO-LIVE | Exec + Operations | ☐ A ☐ B ☐ C | ☐ |

### Session 4 — Before Phase 3 completes

| ID | Decision | Priority | Owner | Decision | Signed |
| --- | --- | --- | --- | --- | --- |
| **OD-5** | Re-certification evidence standard | CAN WAIT | Executive | ☐ A ☐ B ☐ C | ☐ |

---

## Two items that are not decisions

Recorded here so they are not mistaken for pending approvals:

| Item | Why it is not a decision | Status |
| --- | --- | --- |
| **RC-6 — created warehouse invisible after `POST 201`** | Required engineering diagnosis, not executive approval. | ✅ **DIAGNOSED 2026-08-08 — root cause PROVEN.** Warehouse **writes** take `company_id` from the client payload (`StoreWarehouseRequest:28`, chosen in a `CompanySelect` dropdown); **every read** filters by `Auth::user()->company_id` — twice, via `WarehouseController:34` and the `Warehouse` model's `tenant` global scope. Nothing reconciles them. Effort `Unknown` → **XS**. **The disposition still requires approval** — see the new decision below. |
| **Phase 2 `Q2` — do the 15 dedicated routes enforce independently?** | A question of fact for an engineering survey; the *input* to SD-4. | ✅ **ANSWERED 2026-08-08 — yes, all 15.** SD-4 is closed. |

---

## New decision arising from this task

### ② RC-6 disposition — **BLOCKS IMPLEMENTATION**

The root cause is proven, but **the correct fix depends on GD-1** and therefore cannot be chosen by
engineering:

| Option | Consequence | Depends on |
| --- | --- | --- |
| **Minimum** — derive `company_id` on write from the same authority the reads use; reject mismatched payloads | Closes RC-6. Safe under either OD-2 option. Removes the ability to create a warehouse for a company other than your own. | Nothing |
| **Correct** — one company-context resolver shared by write and read paths | Makes the mismatch structurally impossible. Requires deciding whether the **active header company** or the **user's home company** is authoritative. | **GD-1** |
| **Also fail the read filters closed on `NULL` company** | Closes D-3/D-4. **Will hide records from any administrator who currently sees everything** — the code comments assert this is deliberate. | **GD-1** |

**Owner: Executive + Architecture.**

---

## Engineering Defect Annex — added 2026-08-08

Defects discovered during TASK-GOLIVE-DECISIONS-001. **None were fixed.** Listed here so they are not
mistaken for pending decisions — but note that four of the seven have a *resolution* that belongs to a
decision above.

| ID | Defect | Severity | Resolution belongs to |
| --- | --- | --- | --- |
| **D-1** | **RC-6** — write takes payload `company_id`, reads take `Auth::user()->company_id` | **P0** | RC-6 disposition (above) + **GD-1** |
| | *2026-08-08 — [TASK-GOLIVE-RC6-REPAIR-001](TASK-GOLIVE-RC6-REPAIR-001-ENGINEERING-REPORT.md): characterization tests written and **executed** (17 tests, 5 failures — all five vectors reproduced). Fix implemented across 5 files and lint-clean, but NOT CERTIFIED at that point.* | | |
| | ✅ *2026-08-08 — **CONTINUATION: RC-6 CERTIFIED CLOSED.** Post-fix suite **OK (17 tests, 50 assertions)**; both PHPStan configs `[OK] No errors`; **Guardian pre-push all eight validators passed**. Warehouse **and** Order now fail closed. Zero corrective iterations used. Two qualifications carried forward — see below.* | | |
| | ✅ *2026-08-08 — **RE-EXECUTED independently** against an unchanged tree: all three layers reproduced identically (suite `OK (17 tests, 50 assertions)`, both PHPStan configs clean, `GUARDIAN_EXIT=0`). **Certification is reproducible.** Neither qualification is affected.* | | |
| | ✅ *2026-08-08 — [TASK-GOLIVE-FINAL-GATES-001](TASK-GOLIVE-FINAL-GATES-001-ENGINEERING-REPORT.md): **both RC-6 qualifications DISCHARGED.** Parent-commit control ran with the fix temporarily reverted and restored byte-identical — all five unrelated failures are **PRE-EXISTING** (identical counts and messages). Production-admin audit executed read-only: **1 matching account, a permission-less test artifact; no real user affected.* **RC-6 remains CLOSED on executed evidence.*** | | |
| **D-2** | Warehouse grid **Company filter is inert** — the frontend sends `company_id`, the controller silently overwrites it | P2 | — *(fixing it naively **widens RC-1**)* |
| **D-3** | `Warehouse` tenant scope **fails open** when `company_id` is `NULL` — both guards fail together | **P0** multi-tenant / P3 pilot | **GD-1** |
| **D-4** | `Order` tenant scope **fails open** identically — a `NULL`-company actor can transition any company's orders | **P0** multi-tenant / P3 pilot | **GD-1** |
| **D-8** | ~~`Supplier` tenant scope fails open~~ → ✅ **CLOSED 2026-08-08** by [TASK-GOLIVE-PILOT-PHASE3-PREP-001](TASK-GOLIVE-PILOT-PHASE3-PREP-001-ENGINEERING-REPORT.md). Characterization tests first (baseline **5 tests, 1 failure** — the null-company fail-open reproduced), then the certified RC-6 three-branch pattern applied to one method. Post-fix **22/22 tenant-isolation tests green**, both PHPStan configs clean, `GUARDIAN_EXIT=0`. **Refinement:** only the *null-company* path failed open — an actor *with* a company was already scoped correctly | ~~P0~~ **RESOLVED** | — |
| **D-9** | *(new 2026-08-08)* `ScopeResolver:109` treats a null company as `unrestricted` *"super-admin-style"* for **every** entity using the IAM scope engine — the same conflation, platform-wide. Not repaired: changing it alters scoping everywhere | **P0** multi-tenant / P3 pilot | **GD-1** |

> ### D-8 and D-9 assessed 2026-08-08 — they are NOT equivalent
>
> [TASK-GOLIVE-FINAL-GATES-001](TASK-GOLIVE-FINAL-GATES-001-ENGINEERING-REPORT.md) §3 inspected both.
> Neither was modified.
>
> | | Classification | Evidence |
> | --- | --- | --- |
> | **D-8 Supplier** | **A + D — exploitable, genuine defect** | `GET /api/suppliers` needs **only `auth:sanctum`** (`api.php:558-563` gates write verbs only); the scope returns all companies for a null-company actor; **and such an account exists and is active** (user 1767). Reproduction requires no permission. |
> | **D-9 ScopeResolver** | **C — unreachable** | Applied only via the **opt-in** `scopedTo()` macro. A full-backend search finds **zero production call sites** — one test and four comments. Latent until a module adopts it. |
>
> **D-8 is a one-file change using the pattern already proven in Warehouse and Order.** It was not
> implemented because the task stated *"Do not modify Supplier or ScopeResolver."* It now needs an
> owner authorization, not further engineering.
>
> **D-9 is a design decision for GD-1**, not a repair — `ScopeResolver` is a singleton on the IAM
> authorization path, so it is not an isolated edit. It must be settled **before** the first module
> adopts `scopedTo()`.

> **D-3/D-4/D-8/D-9 sharpen GD-1.** The platform grants cross-company access on *absence of a
> company*, while `config/permissions.php` states privilege is granted by an **is_system role** and
> *"Never gate-bypass on slug"*. GD-1 must state which signal is authoritative. Note the corollary
> found during the repair: if any production administrator operates with `company_id = NULL` and no
> is_system role, closing this **locks them out** — that population has not been audited.
>
> **Update 2026-08-08 — D-3 and D-4 are now RESOLVED in code** (Warehouse and Order fail closed;
> privilege routed through `userHasSystemRole()`). **D-8 (Supplier) and D-9 (ScopeResolver) remain
> open and untouched**, so the fail-open class is *not* closed platform-wide. GD-1 still owns them.
>
> **Deployment gate — the corollary is now blocking, not theoretical.** Because D-3/D-4 are live,
> this query MUST return an empty set before the change is deployed:
>
> ```sql
> SELECT u.id, u.email FROM users u
> WHERE u.company_id IS NULL
>   AND NOT EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id
>                   WHERE ur.user_id = u.id AND r.is_system = 1);
> ```
>
> Status **superseded 2026-08-08 — ✅ AUDIT EXECUTED** (read-only, `ecos_erp`):
>
> | | |
> | --- | --- |
> | Users total | **3** · with `company_id IS NULL`: **1** |
> | Matching the at-risk criteria | **1** — id 1767, `noperm_1786059965@test.com`, **active**, **zero roles** |
> | Real administrators affected | **None.** `admin@ecos.local` holds `super-admin` **and** a `company_id`; `verify.accountant@ecos.local` has a company. |
>
> The single match is a **UAT test artifact** (`noperm_` prefix, `@test.com`, created 2026-08-07). It
> was an **active fail-open reader** — `GET /api/warehouses` carries no permission middleware — so the
> RC-6 fix *closes* an exposure rather than locking anyone out. Recommended hygiene: delete it or
> assign a company. **Not done — no data was mutated.**
>
> **Residual UNVERIFIED:** this covers `ecos_erp`, the only database that exists (`SHOW DATABASES`
> confirms no production instance; cutover was never executed). Any future production instance must
> be re-audited with the same query before deployment.
| **D-5** | `/complete` performs **no status transition**; audit stamps skipped | P2 | **PD-2** |
| **D-6** | `/review` sets `OnHold`; the name is stale | P3 | **PD-2** |
| **D-7** | **Zero test coverage** — no warehouse CRUD test, and 0 matches for `FulfillmentEngine` / the workflows / `fulfillment/orders` anywhere in `backend/tests` | **HIGH** | Engineering leadership |

**D-3 and D-4 are the reason GD-1 cannot simply be deferred under a Pilot launch** — they are latent
under one company and immediately exploitable the moment a second exists.

## Outstanding engineering input

| # | Input | Blocks | Status |
| --- | --- | --- | --- |
| **E-3** | Does outbound sync publish `products.stock_status`? *(Phase 2 Q1)* | **PD-5** | ✅ **ANSWERED 2026-08-08 — NO.** `ProductObserver` syncs only name/sku/description/short_description + prices, and its own comment names *"e.g. stock_status update"* as the non-sync case. `ProductSyncJob`/`PriceSyncJob` never mention the field. **It is an inbound-only channel mirror** — a human edit cannot reach the storefront. **PD-5 Option A confirmed safe; Steps 2 and 8 technically unblocked.** |
| **E-4** | Which other modules share the RC-6 pattern? | RC-6 fix scope | ✅ **EFFECTIVELY ANSWERED** — exactly three models carried `addGlobalScope('tenant', …)`: Warehouse, Order (RC-6) and Supplier (D-8). **All three now fail closed.** `ScopeResolver` (D-9) is the separate IAM-engine instance |
| **E-5** | Do the 13 **bulk** fulfillment routes enforce the same guards? | Completeness of SD-4's claim | ✅ **ANSWERED 2026-08-08 — YES, 13/13 PASS.** `BulkWorkflowEngine:49` delegates per order to `FulfillmentEngine::run()`, which invokes `workflow->guard()`. Same workflow objects as the dedicated routes; `resolveTransitionWorkflow()` never called. `Order::find()` keeps company isolation. **SD-4 is now fully closed — all 29 lifecycle entry points surveyed** |

### Step status under OD-2 = PILOT *(added 2026-08-08)*

| Phase 3 step | Status |
| --- | --- |
| **Step 1** — derive `availability_state` | ✅ **COMPLETE 2026-08-08** — [TASK-PHASE3-STEP1-AVAILABILITY-STATE-001](TASK-PHASE3-STEP1-AVAILABILITY-STATE-001-ENGINEERING-REPORT.md). Rule taken from the platform's own demand-independent branches (`DemandAnalysisService:143-148`), **not invented**: no record → `Untracked`, `available <= 0` → `OutOfStock`, else `InStock`. `Shortage` deliberately not mirrored (needs an ordered qty). Projection lives **inside** `InventorySummaryService` — no second engine. Additive DTO field + one response key. `OK (8 tests, 28 assertions)`, both PHPStan configs clean, `GUARDIAN_EXIT=0`. **`products.stock_status` untouched — repointing the grid is Step 2, gated on PD-5.** |
| **Step 3** — reconcile products stats/list | ✅ **COMPLETE 2026-08-09** — [TASK-PHASE3-GD1-STEP3-CLOSE-001](TASK-PHASE3-GD1-STEP3-CLOSE-001-ENGINEERING-REPORT.md). **GD-1 (Product population) RESOLVED = Option A** from existing behaviour: `stats()` always scoped to the authenticated company, the list never did, and no UI / permission / scope / doc supports cross-company product browsing — the certification's group-buyer note named Purchases and Recipes, **not Products**. Both endpoints now resolve population through the certified RC-6 `TenantOwnershipResolver`; a caller filter can only narrow. **`OK (7 tests, 24 assertions)`**, both PHPStan configs clean, `GUARDIAN_EXIT=0`, TypeScript baseline 24 held. **Closing it also removed a real cross-company product disclosure.** *(Superseded row below.)* |
| ~~Step 3~~ *(superseded)* | ⛔ ~~STOPPED — requires GD-1.~~ `stats` is always scoped to the authenticated company; `list` is scoped **only if the caller supplies a filter**. Reconciling means deciding whether cross-company product browsing is intended — the certification flagged `All companies` browsing as a possible deliberate group-buyer capability. Tightening `list` = fixing RC-1 for Products; loosening `stats` = introducing a disclosure. **Neither is engineering's call.** |
| **Step 2 / Step 8 — test certification** | ✅ **CERTIFIED 2026-08-09** — [TASK-PHASE3-STEP2-TEST-CERTIFICATION-001](TASK-PHASE3-STEP2-TEST-CERTIFICATION-001-ENGINEERING-REPORT.md). Write-path regression suite **`OK (7 tests, 18 assertions)`** in isolation. The 3 `InventoryCountSessionTest` failures are **PRE-EXISTING** — proven by a scoped parent-commit control (identical 17/35/3, same names and messages, with all uncommitted backend work reverted then restored and marker-verified). Both PHPStan configs clean, `GUARDIAN_EXIT=0`, TypeScript baseline **24** held. **Step 2 = FULLY CERTIFIED.** |
| **Step 8** — close human write path on `stock_status` | ✅ **COMPLETE 2026-08-09** — [TASK-GOLIVE-PD5-EXECUTION-001](TASK-GOLIVE-PD5-EXECUTION-001-ENGINEERING-REPORT.md). Rule removed from all three human write paths (Store/Update/Patch ProductRequest). **`import()` deliberately preserved** — machine ingestion, not the human path. `stock_status` still readable |
| **Step 2** — repoint availability presentation | ✅ **COMPLETE 2026-08-09** — [TASK-PHASE3-GD2-STEP2-CLOSE-001](TASK-PHASE3-GD2-STEP2-CLOSE-001-ENGINEERING-REPORT.md). Frontend duplicate availability engine **removed**: `resolveMaterialStockStatus` no longer computes a rule, it presents the server's `availability_state`. Grid, CSV export and detail drawer (2 call sites) repointed. Guardian PASS, TypeScript baseline 24 held, **0 i18n keys added** (existing EN/AR keys reused). *(Superseded row below.)* |
| ~~Step 2~~ *(superseded)* | 🟡 ~~PARTIAL.~~ **Backend COMPLETE** — projection consolidated into `AvailabilityState::fromAvailable()` (one rule, one place); `ProductResource` gains additive `availability_state`. **Frontend BLOCKED** — see the new decision below |

> ### ✅ PD-5 = RESOLVED 2026-08-09 *(engineering resolution, not a new business policy)*
>
> **`availability_state` is the ERP product-level availability state; `products.stock_status` remains
> the WooCommerce channel attribute. The two are never merged.** No outbound sync requirement was
> invented; ERP availability does not alter channel state.
>
> Derived entirely from already-approved artefacts: **E-3** (inbound-only, proven from
> `ProductObserver`'s own comment), **Phase 2 Design Part 1** (retain–relabel–restrict), **Step 1**
> (the canonical projection), and the **certification's RC-9** finding that the defect is two facts
> *"sharing one name"*. No contrary owner decision exists in the approved design.
>
> ### 🖊️ NEW DECISION REQUIRED — `allow_negative_stock` vs derived availability
>
> **Blocks the Step 2 frontend slice only.** Owner: Product + Operations (related to **GD-2**).
>
> The Raw Materials UI does not show the channel field — it runs its **own** client-side derivation,
> `resolveMaterialStockStatus(available_qty, allow_negative_stock)`
> (`raw-material-table.tsx:280`, `raw-materials-page.tsx:59`, `raw-material-detail-drawer.tsx:61`).
> That is a **second availability calculation**, and it uses a **different rule** — it honours
> `allow_negative_stock`, which the canonical rule does not. For a negative-stock-enabled product at
> zero available, the two answers disagree.
>
> Repointing the grid is therefore not a like-for-like swap: it changes what users see. **Whether
> `allow_negative_stock` may override derived availability is a business rule**, outside PD-5's
> scope (channel-vs-ERP separation). Not guessed; no frontend file was modified.
>
> ### ✅ RESOLVED 2026-08-09 — engineering resolution from existing behaviour
>
> **`allow_negative_stock` is a permission to PROCEED despite unavailability, applied at the point of
> action (reserve / manufacture / consume). It does not change what the warehouse physically holds,
> so it must not change measured availability in a stock column.**
>
> The platform already separated these and gave each its own field — the frontend util conflated
> them, the backend never did:
>
> | Concept | Field | Rule |
> | --- | --- | --- |
> | *What do we physically have?* | `availability_state` | `available <= 0 → OutOfStock` |
> | *May we proceed anyway?* | `manufacturing_availability` | `available > 0 **OR** allow_negative_stock` |
>
> Evidence: **`ManufacturingAvailabilityService:13-14, 80`** states the OR-rule verbatim and exposes
> it under a separate name (`ProductResource:160`); `ReserveOrderInventoryAction:157-162` applies it
> at reservation; `InventoryMutationAdapter:26,52` at consumption; `ComponentConsumptionPlan:14-15`
> distinguishes `will_go_negative` from `is_blocked`. **No new business rule was invented.**
>
> **Scope:** this resolves the *display-semantics* question only. The broader GD-2 governance items —
> who may toggle `Allow Negative`, its default, Units editability, Categories ownership — remain
> **OWNER DECISION REQUIRED** under the tenant-2 gate.
| **D-10** | ✅ **CLOSED 2026-08-09** — [TASK-PHASE3-D10-RC10-FINAL-CERTIFY-001](TASK-PHASE3-D10-RC10-FINAL-CERTIFY-001-ENGINEERING-REPORT.md). **Root cause was worse than first described:** `DispatchOrderWorkflow:75-76` passes **hardcoded literal `null`**, so that producer could **never** construct the event — dispatch was unconditionally broken, not conditionally. **Contract established from architecture (Option B, vehicle optional):** the event has two producers — `LoadVehicleWorkflow` (has a vehicle) and `DispatchOrderWorkflow` (never does); their coexistence *is* the contract, and `driverId` was already `?string`. Fix: two params → `?string` + a null-aware audit description. **17/17 runtime**, FIFO **10.0 → 8.0** on both layer and on-hand, Delivered reached, no silent partial success. Guardian `GUARDIAN_EXIT=0`. | ~~P1~~ **RESOLVED** | — |
| ~~D-10~~ *(superseded)* | ⛔ ~~P1 — dispatch is unusable.~~ `OrderDispatchedEvent::__construct()` declares `string $vehicleAssignmentId`; `DispatchOrderWorkflow:71` passes **null** when no vehicle is assigned → TypeError. Surfaced by [TASK-PHASE3-RC10-FINAL-CLOSE-001](TASK-PHASE3-RC10-FINAL-CLOSE-001-ENGINEERING-REPORT.md) once a **correct FIFO fixture** let execution reach event emission. Because `FulfillmentEngine` dispatches events **after commit**, the order is likely left `out_for_delivery` with inventory consumed while the caller gets a 500 and **no event fires** — *silent partial success* (reasoned from the engine's documented ordering; **not yet asserted**). PHPStan L0 cannot catch a nullable flowing into a non-nullable parameter. **Not fixed** — needs a small product call: is a vehicle mandatory at dispatch (guard rejects with 422) or optional (event accepts null)? | **P1** | Blocks RC-10 |
| **RC-10 / Phase 3** | ✅ **RC-10 CERTIFIED · PHASE 3 = 8/8 CERTIFIED — 2026-08-09** — [TASK-PHASE3-RC10-FRONTEND-TEST-CERTIFY-001](TASK-PHASE3-RC10-FRONTEND-TEST-CERTIFY-001-ENGINEERING-REPORT.md). Six required frontend tests written and executed — **7 passed (7)** — driving the real path (genuine `AxiosError` → `isAxiosError()` → `response.data.message` → refusal state → `role="alert"`), with selector-mode `t()` resolved against the **real** EN/AR bundles. **The original defect is now pinned: removing `onError` fails five tests.** Backend regression **`OK (40 tests, 203 assertions)`**, Guardian `GUARDIAN_EXIT=0`, TypeScript baseline **24**, i18n 0 missing. 6 `new-count-dialog` failures **control-proven PRE-EXISTING**. **Steps 1–8 all CERTIFIED.** | | |
| ~~RC-10 / UI refusal reason~~ *(superseded)* | ⚠️ ~~RC-10 NOT CERTIFIED 2026-08-09~~ — [TASK-PHASE3-RC10-UI-CLOSE-001](TASK-PHASE3-RC10-UI-CLOSE-001-ENGINEERING-REPORT.md). **Defect found and fixed:** the order drawer's `transition.mutate()` had **no `onError` at all** — every refusal was silently swallowed with no operator feedback. Now surfaces the backend's `message` verbatim via the house `axios.isAxiosError` pattern; drawer stays open on refusal (`onSuccess`-only close); no business logic added to the UI; +2 keys EN **and** AR, RTL-safe. Backend regression **`OK (40 tests, 203 assertions)`**, Guardian `GUARDIAN_EXIT=0`, TypeScript baseline **24** held. **Blocked on Part 6: no frontend tests written**, so criteria 2–7 are implemented but not test-proven — the programme's own standard says implementation + static validation is not certification. | | |
| **Steps 4–7 / RC-10 runtime** | ⚠️ ~~RC-10 NOT CERTIFIED 2026-08-09~~ — final runtime closure reached **17 tests / 46 assertions / 1 failure**; **16 of 17 scenarios PASS**, 11 of 14 criteria met. **Both warehouse gates now runtime-confirmed** (reservation = first, dispatch = final defensive; FIFO untouched on refusal) — PD-1 Option B confirmed, not reopened. Dedicated-route runtime matrix: **6 PASS · 1 FAIL · 3 blocked by D-10 · 5 not executed** — none claimed from static routing. Blocked by **D-10**. *(Superseded row below.)* |
| ~~Steps 4–7 / RC-10~~ *(superseded)* | ⚠️ ~~RC-10 NOT CERTIFIED 2026-08-09~~ — [TASK-PHASE3-RC10-E2E-CERTIFICATION-001](TASK-PHASE3-RC10-E2E-CERTIFICATION-001-ENGINEERING-REPORT.md). DB-backed runtime suite: **34 tests, 177 assertions, 2 incomplete**, Guardian `GUARDIAN_EXIT=0`. **9 of 11 scenarios PASS** — reservation, shortage→AwaitingStock, invalid 422, unauthorized 403, cross-company 404, bulk valid+refusal in one call, dedicated route + guard refusal, audit written on success and **not** written on rejection. **11 of 14 Part-15 criteria met.** Outstanding: dispatch/delivered legs (fixture needs FIFO receipt layers), isolated missing-warehouse refusal, and the UI refusal reason. **Finding (refines PD-1, does not reopen it): the warehouse requirement binds at RESERVATION, earlier than the documented dispatch gate — the platform is safer than PD-1 described.** *(Superseded row below.)* |
| ~~Steps 4–7~~ *(superseded)* | 🟢 ~~IMPLEMENTED & VERIFIED (routing) 2026-08-09~~ — [TASK-PHASE3-RC10-IMPLEMENT-CERTIFY-001](TASK-PHASE3-RC10-IMPLEMENT-CERTIFY-001-ENGINEERING-REPORT.md). `resolveTransitionWorkflow()` rewritten against the `OrderStatus` enum — the generic `/transition` endpoint now resolves V3 orders instead of 422-ing every one. Routing only: no guard rewritten, duplicated or bypassed; no second engine; no TS state machine; `delivered → completed` deliberately absent (PD-2). **`OK (23 tests, 148 assertions)`**, certified-step regression **44/44**, both PHPStan configs clean, `GUARDIAN_EXIT=0`, TypeScript baseline 24. **Atomicity satisfied** — vocabulary and guard-routing moved together, so the dangerous half-state never existed. **RC-10 still NOT CERTIFIED** — Part 9 (UI refusal reasons) and Part 12 (end-to-end lifecycle) not executed. *(Superseded row below.)* |
| ~~Steps 4–7~~ *(superseded)* | 🟢 ~~UNBLOCKED 2026-08-09~~ — [TASK-PHASE3-PD1-PD2-RC10-CLOSE-001](TASK-PHASE3-PD1-PD2-RC10-CLOSE-001-ENGINEERING-REPORT.md). **PD-1 and PD-2 both RESOLVED**, so no owner decision remains. **Not implemented** — deliberately not started rather than half-shipped, because the release is atomic and landing the vocabulary without the guards would make illegal transitions possible for the first time. **No code changed; tree clean.** |

> ### ✅ PD-1 = RESOLVED 2026-08-09 — **Option B**, from existing architecture
>
> **Warehouse assignment is mandatory at Dispatch, not at Ready for Dispatch — and already is.**
>
> `ShipOrderInventoryAction:43-44` throws a **purpose-built** `OrderWarehouseNotAssignedException`
> when `assigned_warehouse_id` is null, inside the dispatch transaction, rolling the status back
> atomically. `DispatchOrderWorkflow` documents it: *"If shipment fails (no warehouse, no
> reservation, insufficient reserved qty), the exception propagates and the status update never
> executes."* A dedicated exception class thrown at exactly one point is deliberate design.
>
> Choosing Option A would have **moved an existing gate earlier — inventing a rule, not deriving
> one**. **No code change required.** The RC-10 concern that an order could reach dispatch with no
> warehouse is already answered: it cannot. *(Option A remains available later as a UX improvement —
> failing earlier in the operator's day — not a correctness gap.)*
>
> ### ✅ PD-2 = RESOLVED 2026-08-09 — from existing architecture
>
> **V3 is canonical and unchanged. `Delivered` is terminal. There is no `Completed`, no `Review`, no
> `Preparing` order state.**
>
> | V2 | Outcome |
> | --- | --- |
> | `pending` → `new` · `rescheduled` → `scheduled` | Rename |
> | `confirmed` · `processing` → `in_progress` | Already how the V3 workflows map |
> | `preparing` | Not an order state — Operations wave concern; order sits at `ready_for_dispatch` |
> | `completed` | **Retired.** `/complete` stamps financial completion on an already-`Delivered` order — it is not a transition |
> | `review` | **Retired.** `/review` places the order `OnHold` |
>
> The two SD-4 PARTIALs are **correct-by-design, not defects** — `/complete` owes no transition, and
> `/review` is only stale naming. Both are retired in Step 7. **No state was invented.**

---

## Completion criteria

Phase 3 implementation is authorised when:

1. ☐ **OD-2** is signed — the launch model is fixed
2. ☐ **PD-1** ratified *(scope reduced — ratify existing behaviour + answer Q3 only)*
3. ☐ **PD-2** signed *(two live instances now documented: `/complete`, `/review`)*
4. ☐ **PD-5** signed — **requires E-3 first**
5. ☐ **GD-1** signed, **or** explicitly deferred to the tenant-2 gate under OD-2 = Pilot
6. ☑ ~~**SD-4** decided~~ — **CLOSED 2026-08-08**, resolved by evidence
7. ☑ ~~RC-6 diagnosis commissioned~~ — **COMPLETE 2026-08-08, root cause proven**
8. ☐ **RC-6 disposition** approved *(new — the diagnosis is done, the fix choice is not)*

**Status as of 2026-08-08: 2 of 8 satisfied. Phase 3 may not begin.**

Go-live is authorised when, additionally:

5. ☐ All twelve `BLOCKS GO-LIVE` decisions are signed
6. ☐ **OD-5**'s evidence standard is met and re-certification returns **GO**

---

**No code was written. No design was produced. No architecture was proposed. This document
identifies decisions requiring executive approval; it does not make them. Every default
recommendation is a starting position for discussion, not a prescription.**
