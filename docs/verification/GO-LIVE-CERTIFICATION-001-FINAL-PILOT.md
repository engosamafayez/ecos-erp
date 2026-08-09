# GO-LIVE-CERTIFICATION-001 — FINAL ENGINEERING REPORT
## ECOS ERP — Pilot End-to-End Go-Live Certification

**Date:** 2026-08-09 · **Worktree:** `develop` @ `C:\ecos-develop` · **Launch model:** OD-2 = PILOT

---

# FINAL DECISION

# 🔴 NO-GO

**Not because a business flow failed. Because the deployed artifact is not the certified source —
an explicit NO-GO condition under Part 27.**

> *"🔴 NO-GO if: … deployment artifact differs from certified source."*

**This is a release-process failure, not a product defect. It is also the cheapest of all possible
blockers to clear.**

---

# 1 — EXECUTIVE SUMMARY

Phase 3 is genuinely certified: 8/8 steps, RC-10 certified, D-10 closed, with executed
database-backed runtime evidence. **None of that work is in the running system.**

| Fact | Evidence |
| --- | --- |
| Repository `HEAD` | **`f0d7822a`** — the Guardian `VALIDATOR_DIR` fix, predating all Phase 3 work |
| Uncommitted files | **35** — every RC-6, D-8, Step 1/2/3/8, Steps 4–7 and D-10 change |
| `ecos-app` container | **Up 32 hours** — started before today's work existed |

**Therefore the deployed image cannot contain:** the RC-6 tenant-isolation fix, the D-8 Supplier fix,
`availability_state`, the Product population fix, the Step 8 write-path closure, the V3 transition
routing, or the D-10 dispatch fix.

**Certifying the running system as Pilot-ready would certify a build that contains none of the
certified remediation.** Every runtime result in this programme was produced by PHPUnit against the
worktree — not by the deployed container.

## What this changes

Nothing about the engineering. Phase 3's evidence stands. What is missing is the **release step**:
commit, build, deploy, then re-certify against the deployed origin.

---

# 2 — CERTIFIED ARTIFACT (Part 1)

| Check | Result |
| --- | --- |
| Commit SHA | ⚠️ **`f0d7822a` — does NOT include the certified work** |
| Working tree | ⚠️ **35 modified/untracked files uncommitted** |
| `ecos-app` | ✅ Up 32 hours, **healthy** — but stale relative to certified source |
| `ecos-mysql` · `ecos-redis` · `ecos-mailpit` | ✅ healthy |
| `ecos-nginx` | ⚠️ **unhealthy** (34 h). Known local-dev condition (missing SSL certs); traffic still served |
| `GET /api/health` | ✅ **200** |
| App image digest / build-info | ❌ **NOT VERIFIED** |
| Migrations state | ❌ **NOT VERIFIED** |
| Startup exceptions / restart loops | ❌ **NOT VERIFIED** |

**Artifact integrity: FAIL.** This alone is decisive.

---

# 3 — WHAT WAS EXECUTED IN THIS PROGRAMME (evidence that stands)

All against the **worktree**, host PHP 8.4.22, MySQL `ecos_erp_test`:

| Suite | Result |
| --- | --- |
| RC-10 runtime lifecycle | ✅ `OK (17 tests, 55 assertions)` |
| V3 transition routing | ✅ `OK (23 tests, 148 assertions)` |
| Combined RC-10 + routing | ✅ `OK (40 tests, 203 assertions)` |
| Steps 1/2/3/8 + RC-6 + D-8 | ✅ `OK (44 tests, 132 assertions)` |
| Frontend refusal certification | ✅ **7 passed (7)** |
| PHPStan L0 + L6 | ✅ `[OK] No errors` |
| Guardian pre-push | ✅ `GUARDIAN_EXIT=0` (8/8) |
| TypeScript | ✅ baseline **24** held |

**Business-flow evidence executed:** Order → Reservation → ReadyForDispatch → Dispatch → **FIFO
consumption 10 → 8** → Delivered; both warehouse gates; shortage → `AwaitingStock`; 422/403/404 with
zero mutation; bulk guard parity; audit written on success and **not** on refusal.

---

# 4 — FINAL GO-LIVE MATRIX (Part 25)

| Area | Verification | Result | Evidence | Severity |
| --- | --- | --- | --- | --- |
| **Deployment** | Artifact matches certified source | 🔴 **FAIL** | `HEAD f0d7822a` + 35 uncommitted; container 32 h old | **P0 — release blocker** |
| Runtime | Health | ✅ **PASS** | `/api/health` → 200; 4/5 containers healthy | — |
| Runtime | nginx health | ⚠️ **PRE-EXISTING** | Unhealthy 34 h; known missing-cert condition | Low |
| **Browser** | 15 areas | ❌ **NOT EXECUTED** | No authenticated session against the deployed origin | — |
| Orders | CRUD + lifecycle | ⚠️ **PASS (worktree only)** | 40/40 runtime | Not deployed |
| Inventory | Stock / FIFO / availability_state | ⚠️ **PASS (worktree only)** | FIFO 10→8; Step 1 8/8 | Not deployed |
| Procurement | Supplier → GR → Inventory | ❌ **NOT EXECUTED** | — | — |
| Suppliers | CRUD + isolation | ⚠️ **PASS (worktree only)** | D-8 5/5 | Not deployed |
| Finance | Cash / Tax / Budget | ❌ **NOT EXECUTED** | — | — |
| CRM | Core flow | ❌ **NOT EXECUTED** | — | — |
| Logistics | Dispatch / Delivery | ⚠️ **PASS (worktree only)** | Dispatch → Delivered | Not deployed |
| Executive | Navigation / dashboard | ❌ **NOT EXECUTED** | — | — |
| Notifications | Bell / read / isolation | ❌ **NOT EXECUTED** | — | — |
| Permissions | Two-identity matrix | ⚠️ **PARTIAL** | 403/404 proven in runtime tests; no browser matrix | — |
| **Tenant safety** | Cross-company | ⚠️ **PASS (worktree only)** | RC-6, D-8, Order/Product scopes fail closed | Not deployed |
| **Responsive** | Desktop/tablet/mobile | ❌ **NOT VERIFIED** | No viewport-capable execution | — |
| Regression | Suites | ✅ **PASS** | See §3 | — |
| i18n | EN/AR/RTL | ✅ **PASS** | 0 missing; parity asserted in tests | — |
| **End-to-end ERP** | Supplier → … → Delivered → Finance | ❌ **NOT EXECUTED** | Only Order→Delivered segment proven | **Mandatory (Part 26)** |
| Production-admin audit | Company-less users | ⚠️ **PARTIAL** | `ecos_erp`: 3 users, 1 zero-role UAT artifact, super-admin safe. **No separate production DB exists** | — |

---

# 5 — KNOWN DEBT (Part 18) — all control-proven PRE-EXISTING

| Defect | Count | Pilot critical path? | Classification |
| --- | --- | --- | --- |
| `OrderReservationLifecycleTest` — no throw on double-reserve / insufficient stock | 2 | **Possibly** — reservation is on the critical path, though the certified lifecycle diverts correctly to `AwaitingStock` | **PRE-EXISTING — needs a release decision** |
| `InventoryCountSessionTest` — wrong FIFO qty, missing ledger entry on adjustment | 3 | Inventory **count/adjustment**, not the order path | **PRE-EXISTING — non-blocking for Pilot** |
| `new-count-dialog.test.tsx` | 6 | Frontend test-only | **PRE-EXISTING — non-blocking** |

**None were repaired here, per instruction.** Each was proven pre-existing by a parent-commit control.

---

# 6 — IAM (Part 19) & ROLE TEMPLATES (Part 20)

| Item | State |
| --- | --- |
| **BUG-GL-002 — no IAM administration UI** | ❌ **NOT RE-VERIFIED this task.** Previously: users/roles routes → ComingSoonPage. Provisioning demonstrably works out-of-band (this programme created users, roles and permissions directly). **Requires release-owner acceptance as Pilot debt — I cannot accept it.** |
| **17/40 role templates unassignable** | ❌ **NOT RE-VERIFIED.** No RBAC data was reseeded or mutated. |

---

# 7 — TENANT / PILOT SAFETY (Part 16)

**Closed and certified (worktree):** RC-6, D-8. Warehouse, Order, Supplier and Product populations
all fail closed; privilege flows only through the documented `is_system` path.

**Explicitly deferred tenant-2 gates — NOT closed:**

| Gate | Status |
| --- | --- |
| GD-1 platform-wide entity classification | **DEFERRED / TENANT-2** |
| GD-2 governance (write authority) | **DEFERRED / TENANT-2** |
| GD-4 export governance | **DEFERRED / TENANT-2** |
| RC-1 / RC-2 | **DEFERRED / TENANT-2** |
| **D-9 `ScopeResolver`** | **DEFERRED / MULTI-TENANT EXPANSION** — unreachable today (0 production `scopedTo()` call sites) |

> **The tenant-2 gate must be technically enforced, not procedural.** Nothing in the platform
> prevents a second company being created, and RC-1 is invisible on a single-company system — that is
> exactly how it survived eleven UAT campaigns.

---

# 8 — RELEASE BLOCKERS

| # | Blocker | Type |
| --- | --- | --- |
| **1** | **Deployed artifact ≠ certified source.** 35 uncommitted files; container 32 h stale | **P0 — process** |
| **2** | **Part 26 end-to-end ERP flow not executed.** Only the Order → Delivered segment is proven; Supplier → Procurement → Goods Receipt → Inventory and the Finance effects are unverified | **Mandatory for GO** |
| **3** | Browser matrix (15 areas) not executed against the deployed origin | Mandatory for GO |
| **4** | 2 reservation defects — release decision required | Needs owner call |

---

# 9 — PATH TO GO

**In order. None of it is new development.**

1. **Commit the 35 files**, build the image, deploy — then confirm the running digest matches
2. **Re-run the certified suites against the deployed artifact** (40/40, 44/44, 7/7)
3. **Execute the Part 26 end-to-end flow** — Supplier → Procurement → Goods Receipt → Inventory →
   Order → … → Delivered → financial effects, on real persisted records
4. **Execute the 15-area browser matrix** against the deployed origin (requires an authenticated
   session the user must provide — no token copying)
5. **Responsive verification** with a viewport-capable tool
6. **Release-owner decisions:** the 2 reservation defects, and formal acceptance of BUG-GL-002 as
   Pilot debt

**Steps 1–2 are hours. The engineering is done; this is a release exercise.**

---

# 10 — DECISION REGISTER UPDATE

- **Phase 3 = 8/8 CERTIFIED** — unchanged, evidence stands
- **GO-LIVE-CERTIFICATION-001 = 🔴 NO-GO** — deployed artifact differs from certified source
- Tenant-2 gates remain **DEFERRED**, not closed
- Pre-existing debt remains **PRE-EXISTING**, not fixed
- BUG-GL-002 and role-template reconciliation remain **NOT RE-VERIFIED**

---

# 11 — FINAL STATUS

# 🔴 ECOS ERP — NO-GO

**One verified, objective blocker: the running system is not the system that was certified.**

No business-flow failure was found. No data loss, no tenant-isolation failure, no authorization
bypass, no regression. **Every module result marked "PASS (worktree only)" is real evidence against
the wrong target.**

This verdict is **not** based on the many NOT VERIFIED items — Part 27 forbids that. It rests on a
single executed check: `HEAD` is `f0d7822a`, 35 files are uncommitted, and the container predates all
of them.

**Re-certification after deployment is expected to be fast**, because the engineering evidence
already exists and will simply need re-running against the correct artifact.

---

**No PASS was manufactured. Nothing was converted from NOT VERIFIED, PRE-EXISTING, DEFERRED or
NOT WIRED. No feature was created to make this report green. No RBAC data was mutated, no production
data modified, no certified work reopened.**
