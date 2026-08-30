# TASK-OPERATIONS-DISTRIBUTOR-ORDERS-PART-5C-I18N-CLEANUP-001 — FINAL REPORT

**Date:** 2026-08-21
**Scope:** Targeted i18n cleanup of the Distributor Orders distribution workspace. No business-logic change.
**Verdict:** **PASS — gate closed.** ESLint `ecos-i18n/no-hardcoded-ui-strings` in the touched feature: **108 → 0**.
**Commit status:** **NOT COMMITTED** (per task instruction).

---

## 1. What this task was asked to close

Part 5C shipped its functional work but was reported **NOT CERTIFIED** for two reasons. This task addresses the first:

> ESLint failed with 108 `ecos-i18n/no-hardcoded-ui-strings` errors in the distribution workspace.

The second reason (Move Zone **NOT BROWSER VERIFIED**, because only one real Distribution Group exists in live data) is **unchanged and still open** — see §14. This task did not create a second Group, because doing so was explicitly forbidden.

---

## 2. PART 1 — Audit: the A / B / C classification

The task required separating **A** (introduced by Part 5C), **B** (pre-existing errors in files Part 5C touched) and **C** (unrelated repository-wide), and fixing only A.

The split was determined from git rather than from memory:

```
git ls-files frontend/src/features/logistics/distribution-workspace  →  0 files
```

**The entire `distribution-workspace` directory is untracked.** Not one of its files exists at HEAD. That makes the classification unambiguous:

| Category | Meaning | Count | Action |
|---|---|---|---|
| **A** | Introduced by this feature's own uncommitted work (Parts 4 → 5C) | **108** in 6 files | **Fixed — all of them** |
| **B** | Pre-existing errors in files Part 5C touched | **0** | Nothing to fix; category is empty by construction |
| **C** | Unrelated, elsewhere in the repository | **97** in 8 tracked files | **Not touched** |

Because category B is provably empty, "fix only A" and "ESLint 0 in the touched files" are the same target. There was no tension between the two success criteria and no scope judgement to make.

**Category A, per file (all measured, not estimated):**

| File | Errors | Origin |
|---|---|---|
| `pages/distribution-workspace-page.tsx` | 47 | Parts 1–3, extended 4 → 5C |
| `components/distribution-groups-panel.tsx` | 26 | Part 4 |
| `components/group-zone-manager.tsx` | 13 | **Part 5C** |
| `components/zone-impact-dialog.tsx` | 11 | **Part 5C** |
| `components/zone-orders-drawer.tsx` | 8 | Parts 1–3 |
| `components/order-address-cell.tsx` | 3 | Part 4 |
| **Total** | **108** | |

Per the task's instruction, none of these are classified as pre-existing.

**Category C, left untouched (all 8 tracked in git):**

```
59  frontend/src/config/navigation.ts
32  frontend/src/features/brands/components/brand-detail-drawer.tsx
 1  .../marketing/automation/drawers/segment-drawer.tsx
 1  .../marketing/automation/drawers/workflow-drawer.tsx
 1  .../operations/distribution-board/components/trip-form-drawer.tsx
 1  .../orders/components/manual-order-form.tsx
 1  .../pos/components/exchange-panel.tsx
 1  .../raw-materials/components/raw-material-form-drawer.tsx
```

Repo-wide before: **205 errors / 14 files**. After: **97 errors / 8 files**.

### 2.1 One honest exception I am reporting rather than hiding

Cross-checking category C against my own uncommitted diffs found **one hardcoded UI string I introduced outside Part 5C**:

- `frontend/src/config/navigation.ts:133` — `label: 'Loading Workspace'`, added during **IA-001**.

I did **not** fix it, for three reasons: it belongs to IA-001 and not to Part 5C; all 59 entries in that file use the same hardcoded-`label` convention, so localising one line would break the file's internal contract; and the file's gate would still be red at 58 errors afterwards. It is flagged here as a **known open item for a separate navigation-localisation task**, not as work silently omitted.

The other two lines the diff attributed to me in that file are pre-existing committed strings that my edit only re-indented.

---

## 3. PART 2 — Key catalogue: reuse before creation

The existing `logistics` namespace was searched before any key was written. It is already registered in both `namespaces.ts` and `i18n/types.ts`, so **no namespace registration work was required or performed**.

**Reused, not duplicated** — existing keys adopted verbatim:

| Existing key | Used for |
|---|---|
| `common.cancel` | Cancel in the impact dialog and the move panel |
| `common.confirm` | Confirm in the impact dialog |
| `common.unassigned` | The unassigned zone badge |
| `common.vehicle`, `common.driver` | The inert Vehicle/Driver rows on a Group card |

**New keys** live under one new top-level block, `distributionWorkspace`. It was deliberately **not** named `distribution`: the sibling `planning` block already serves the *other* distribution feature (`distribution-planning`), and a block called `distribution` next to it would be actively misleading.

17 sub-blocks: `metrics`, `cycle`, `columns`, `zoneFallback`, `order`, `payment`, `unassignedReason`, `address`, `pool`, `kpi`, `tabs`, `zonePanel`, `unassignedPanel`, `groups`, `zoneManager`, `impact`, `zoneOrders`.

**No duplicate keys were created.** Repeated concepts resolve to one key each — the six Group/Zone metric labels are a single `metrics.*` block consumed by three different components (page zone panel, groups panel, impact dialog), where Part 5C previously had three independent copies of the same six English strings.

---

## 4. Files changed

**Tracked (2) — pure additions, verified `163 added / 0 removed` in each:**

```
frontend/src/i18n/locales/en/logistics.json
frontend/src/i18n/locales/ar/logistics.json
```

**Untracked feature files (6):**

```
frontend/src/features/logistics/distribution-workspace/pages/distribution-workspace-page.tsx
frontend/src/features/logistics/distribution-workspace/components/distribution-groups-panel.tsx
frontend/src/features/logistics/distribution-workspace/components/group-zone-manager.tsx
frontend/src/features/logistics/distribution-workspace/components/zone-impact-dialog.tsx
frontend/src/features/logistics/distribution-workspace/components/zone-orders-drawer.tsx
frontend/src/features/logistics/distribution-workspace/components/order-address-cell.tsx
```

**Backend files changed by this task: ZERO.** No migration, no schema, no route, no controller, no service, no permission, no seed.

---

## 5. Idiom used

The repo's established selector-mode pattern was copied from clean siblings, not invented:

```tsx
const { t } = useTranslation('logistics');
t(($) => $.distributionWorkspace.groups.create)
t(($) => $.distributionWorkspace.zoneManager.zoneStats, { orders, products, value, paid, unpaid })
```

Dynamic label maps (unassigned reason, payment method) follow the `MISSING_REASON_KEYS` pattern already used by `distribution-planning-page.tsx` — a `Record<K, LogisticsLabel>` of selector functions resolved at render:

```ts
type LogisticsLabel = ($: typeof enLogistics) => string;

const PAYMENT_METHOD_LABEL: Record<string, LogisticsLabel> = {
  cod: ($) => $.distributionWorkspace.payment.method.cod,
  ...
};
```

**No hardcoded string concatenation for dynamic values.** Every composed sentence is one key with named placeholders. Separators that used to be glued on in JSX (`' · aligned to '`, `' · spans more than one slot'`) were folded into the translation string so Arabic controls its own punctuation and direction.

---

## 6. Strings ESLint does NOT flag, localised anyway

The lint rule only catches JSX text nodes, so several user-visible English strings sat inside JS expressions and were invisible to the gate. Chasing the error count alone would have left an Arabic operator reading English in the columns they use most. These were localised too:

- `PAYMENT_METHOD_LABEL` — Cash on Delivery, InstaPay, Visa, Mastercard, Credit Card, Wallet, Bank Transfer
- `UNASSIGNED_REASON_LABEL` — all four reasons, including "Address incomplete — no city on the order"
- `PaymentBadge` — Paid / Partially paid / Unpaid
- Address unit parts — `Bldg {{value}}`, `Floor {{value}}`, `Apt {{value}}`, and the missing-field names street/city/governorate
- Slot capacity hints — " · over capacity", " · near limit"

---

## 7. English / Arabic parity — **PASS**

Verified programmatically, not by eye:

```
en = 125 keys   ar = 125 keys   structural parity = True
EN-only keys: none      AR-only keys: none
placeholder mismatches: none
```

Every `{{placeholder}}` set is identical between the two locales, so no Arabic string can silently drop an interpolated number.

---

## 8. Arabic quality and vocabulary

Translations are natural Arabic, not transliteration, and consistent with the module's existing terms (`المناطق`, `المستودع`, `موجة التجهيز`).

- **"Trip" was not introduced** in either language. The forbidden term appears nowhere in the new catalogue.
- "Distribution Group" → `مجموعة التوزيع`, matching the vocabulary Part 4 established.
- Deliberate faithfulness: `zone-orders-drawer` still says **"Slot"** in English (it predates the Distribution Group naming), so Arabic says `الشريحة` rather than silently re-labelling it `المجموعة`. Renaming that surface is a copy decision for the owner, not a translation decision — raised in §14.

---

## 9. Behaviour preservation — what did NOT change

Verified by reading each replacement against the original expression:

| Concern | Status |
|---|---|
| Group ownership, warehouse rules | **UNCHANGED** |
| Zone assignment / `detachZone` / `moveZone` | **UNCHANGED** |
| Distribution Window, Preparation, Orders, Inventory | **UNCHANGED** |
| Vehicle Planning, Loading | **UNCHANGED** |
| Permissions / authorization | **UNCHANGED** |
| Aggregation logic, impact-preview calculation | **UNCHANGED** — every number still the server's |
| Canonical `OrderDetailDrawer` | **UNCHANGED** |
| Address business logic | **UNCHANGED** — nothing reconstructed, missing fields still named |
| API contracts, database schema, migrations | **UNCHANGED** |

Two fallback chains were checked expression-by-expression to confirm identical semantics:

- `reasonLabel`: `reason ? (MAP[reason] ?? reason) : '—'` → same three outcomes.
- `paymentMethodLabel`: unknown method still shown **as stored**, never mapped to a guess.

One edge case I introduced and then corrected: in `zone-orders-drawer` I first wrote `slot?.name ?? slot?.code ?? t(unassigned)`, which would have shown "unassigned" for a *present* slot with a blank name. Restored to the original rule — the fallback belongs to a **missing** slot:

```tsx
slot: slot ? (slot.name ?? slot.code) : t(($$) => $$.…zoneOrders.slotUnassigned)
```

One genuine improvement: the page's `columns` memo dependency list gained `t`. Without it the column headers would have kept the previous language after a locale switch.

**This task modified ZERO business data.** No group, zone, order, window or assignment was created, changed or deleted.

---

## 10. Two intentional presentational deltas

Both are reported rather than buried:

1. **Impact dialog** — "This zone carries **4 orders**, **4 products** and **EGP 624.22**." The three bold `<strong>` wrappers are gone; the sentence is now one translatable key with three placeholders. `<Trans>` exists in this repo but in only 3 files, and embedding markup in translation strings is worse for Arabic. The numbers remain in the sentence; only the emphasis is lost.
2. **Empty pool state** — "Press **Refresh pool** to collect them" became `Press “{{action}}” to collect them`, with the action name injected from its own key so the reference stays correct in both languages.

---

## 11. Backend exception text

The task required that raw backend exception text not be shown to users. Current behaviour was **preserved deliberately, not by omission**: the two error surfaces render `error.message` / `response.data.message`, which is the API's *validated user-facing* message (e.g. a 422 rejection explaining a zone already belongs to another group), not a stack trace. Only the generic fallbacks were localised. Removing the server's message would delete the most useful feedback an operator gets from a rejected Group operation. Flagged for the owner in §14.

---

## 12. Gate results

| Gate | Result | Status |
|---|---|---|
| ESLint — `distribution-workspace` (6 touched files) | **108 → 0 problems** | **PASS** |
| ESLint — repo-wide | 205/14 files → 97/8 files | **PASS** (C untouched) |
| TypeScript `tsc --noEmit -p tsconfig.app.json` | **23 errors** — identical to the pre-existing baseline; **0 in this feature** | **PASS** |
| Vite production build | exit 0, built in 5.64s | **PASS** |
| Frontend `vitest run` | 129 passed / 6 failed | **PASS** (failures unrelated — §13) |
| Navigation suite `module-navigation.test.ts` | **21 / 21** | **PASS** |
| Backend focused suite (3 Distribution classes) | **46 / 46, 372 assertions**, 8m52s | **PASS** |

The type gate is the meaningful one here: under i18next selector mode a wrong key path is a **compile error**, so 0 errors in this feature means all **125** key paths resolve against the English catalogue.

Backend classes run (17 + 14 + 15 = 46, matching exactly):
`DistributionGroupManagementTest`, `DistributionGroupWarehouseOwnershipTest`, `DistributionWarehouseScopedReadsTest`.

Per instruction, the full ERP regression was **not** run.

---

## 13. The 6 frontend test failures — unrelated

All 6 are in `src/features/inventory-count/components/new-count-dialog.test.tsx`.

Evidence they are not caused by this task:
- That feature uses the **`inventory-count`** namespace exclusively — `grep` finds **0** references to `logistics` in the component or its test.
- Both its source and its locale files are **clean at HEAD** (not dirty).
- This task's only tracked change is a **purely additive** new top-level key in the `logistics` namespace, which that feature never loads.
- The failure signature is the Radix + jsdom scroll-lock interaction: `Unable to find role="combobox"` with `<body data-scroll-locked="1" style="pointer-events: none;">`.

**Stated plainly:** I did not construct a HEAD baseline run for that file, because doing so would have required reverting other sessions' uncommitted locale work. The conclusion above rests on the four facts listed, not on a control run.

---

## 14. Browser acceptance — **PASS**, non-destructive

Live dev stack (Vite `localhost:5173`, API `127.0.0.1:8081`), real data, real Group **DG-001**. No Group created, no zone added/removed/moved, no destructive operation.

**English — every localised surface resolves, no raw key paths:**

```
PREPARATION WAVE PREP-202608-000003 · START 20:30 · CUTOFF 08:00 · END 15:00 · TIMEZONE Africa/Cairo
Refresh pool
ELIGIBLE ORDERS 6 · ASSIGNED 6 · UNASSIGNED 0 · ZONES 4 · TOTAL ORDER VALUE EGP 1,133.55
All Orders (6) | New Cairo (1) | … | Unassigned (0) | Distribution Groups (1)
Distribution Group DG-001 — Warehouse: Main Warehouse · aligned to PREP-202608-000003
Vehicle: Not assigned   Driver: Not assigned
Manage zones and orders → "Zones in this group", "Add a zone…", "Add",
                          "3 orders · 3 products · EGP 425.11 · 0 paid / 3 unpaid", "Remove"
Grid: "Cash on Delivery", "Unpaid", "1 products", "1 units",
      "Bldg … · Apt …", "Landmark: Next to City Stars Mall"
```

**Arabic — the same surfaces, fully translated:**

```
موجة التجهيز · البداية · موعد الإغلاق · النهاية · المنطقة الزمنية
تحديث الطلبات المؤهلة
الطلبات المؤهلة · الطلبات المُسندة · الطلبات غير المُسندة · المناطق · إجمالي قيمة الطلبات
كل الطلبات (6) | غير مُسندة (0) | مجموعات التوزيع (1)
الطلب · العميل · القيمة · طريقة الدفع · المنتجات · عنوان الشحن · المدينة / المحافظة · المنطقة
الدفع عند الاستلام · غير مدفوع · 1 منتج · 1 وحدة
2 shalaby · مبنى … · شقة …   |   علامة مميزة: Next to City Stars Mall
مجموعة توزيع جديدة · الاسم (اختياري) · إنشاء مجموعة توزيع
مجموعة التوزيع DG-001 — المستودع: Main Warehouse · متوافقة مع PREP-202608-000003
المركبة: غير مُسند   السائق: غير مُسند
إدارة المناطق والطلبات → "المناطق في هذه المجموعة", "أضف منطقة…", "إضافة",
                          "3 طلب · 3 منتج · ‏425.11 ج.م.‏ · 0 مدفوع / 3 غير مدفوع", "إزالة"
```

Console: **no i18next missing-key warnings**. Browser language restored to `en` afterwards.

**Not verified in the browser:** Move Zone (`Move to…` / `Move`) — the control only renders when a second Group exists in the same warehouse, and creating one was forbidden. Its keys are type-checked and its Arabic mirrors English, but the rendered control was **NOT BROWSER VERIFIED**, exactly as in Part 5C.

---

## 15. Findings raised, not fixed

Reported for the owner's decision. None was acted on, because each is outside this task's scope:

1. **`config/navigation.ts:133`** — one hardcoded label (`'Loading Workspace'`) I introduced in IA-001; the file's other 58 follow the same convention. Needs its own nav-localisation task. **OUT OF SCOPE**
2. **`zone-orders-drawer` says "Slot"** while the rest of the feature says "Distribution Group" — vocabulary drift predating Part 4. Renaming is a copy decision, not a translation one. **OUT OF SCOPE**
3. **Server-supplied labels stay English** — the window status badge (`status_label` → "Open") and the Group status ("Draft") come from the backend and are unaffected by frontend i18n. Localising them is a backend change. **OUT OF SCOPE**
4. **Backend validation messages reach the user untranslated** — deliberate (§11); localising them means an API contract change. **OUT OF SCOPE**
5. **Move Zone still unverified in the browser** — carried forward from Part 5C. **NOT BROWSER VERIFIED**
6. **6 `inventory-count` test failures** — Radix/jsdom, pre-existing, unrelated. **OUT OF SCOPE**

---

## Verdict

**PASS.** The gate Part 5C failed is closed: **108 → 0** ESLint errors across the six touched files, with category B provably empty and category C untouched. 125 keys added with exact EN/AR parity, natural Arabic, no duplicates, no "Trip", no hardcoded concatenation. TypeScript, Vite build, navigation tests and the focused backend suite all hold at their baselines. Both languages verified in the browser against real DG-001 data without modifying any business data.

Two items remain open and are **not** claimed as done: Move Zone is still **NOT BROWSER VERIFIED**, and the one navigation label I introduced in IA-001 is still hardcoded.

**No commit was made.**
