# TASK-ECOS-TEST-ENVIRONMENT-MIGRATION-BLOCKER-CLOSURE-001

**Engineering report — environment blocker closure**
Date: 2026-08-18

---

## Outcome in one line

The blocker was **already repaired by its owner** before this task began. **No file was modified by
this task.**

---

## 1. Exact migration

```
backend/Modules/Inventory/Products/Infrastructure/Database/Migrations/
  2026_08_18_100000_converge_products_unit_id_nullability.php
```

Untracked (`??`), written by a concurrent session.

## 2. Root cause (as originally observed)

`isNotNullAlready()` ran `SELECT is_nullable FROM information_schema.columns` and then read
`$column->is_nullable`. MySQL 8 returns information_schema labels upper-cased, so the property was
always undefined and the migration always raised:

```
ErrorException: Undefined property: stdClass::$is_nullable
```

Because it fires inside `RefreshDatabase`'s `migrate:fresh`, it aborted **every** test in the
repository before any test body ran. Documented evidence: 17 errors / 0 tests executed / 1h34m,
all identical, all from `migrate:fresh`.

## 3. Exact repair — made by the OWNER, not by this task

Ownership check performed first:

```
git status --porcelain  ->  ?? ...2026_08_18_100000_converge_products_unit_id_nullability.php
git diff --cached       ->  frontend/src/features/orders/components/order-reservation-cell.tsx
```

Confirmed: not part of Procurement, Orders or Logistics changes; the staged
`order-reservation-cell.tsx` is a different concurrent session's file.

On reading the file to apply the authorised fix, it was found **already repaired** (mtime moved
01:52 -> 02:48). The owner's fix, in place now:

```php
$row = DB::selectOne(
    'SELECT is_nullable AS nullable_flag FROM information_schema.columns
       WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
    ['products', 'unit_id'],
);

if ($row === null) {
    return false;
}

$values = array_change_key_case((array) $row, CASE_LOWER);

return strtoupper((string) ($values['nullable_flag'] ?? '')) === 'NO';
```

This is the prescribed approach and stronger than either half alone: an explicit alias **plus** a
case-normalised array read, so it holds regardless of server casing behaviour. Business behaviour,
schema intent and data are unchanged; no new migration was added.

**Because the repair was already correct and complete, this task modified nothing.** Editing
another session's file to re-apply a fix it already contains would have been gratuitous.

## 4. Verification performed

| Check | Result |
|---|---|
| Alias survives MySQL 8 casing (empirical, not assumed) | `SELECT is_nullable AS nullable_flag ...` -> `["nullable_flag"]=> "NO"` |
| `php -l` on the migration | No syntax errors detected |
| Runner holds the fixed version | `grep -c nullable_flag` = 2 in `ecos-dev-testrunner` |
| Runner occupancy | Free. An initial "BUSY" reading was a **self-match**: the `/proc` scan's own shell contains the string `vendor/bin/phpunit`, the exact false positive `scripts/test-gate.sh` documents. Re-checked by printing the matching cmdline |

No `migrate:fresh` was run by this task outside the gate, and `ecos_dev_test` was not reset.

## 5. Files changed by this task

**None.**

## 6. Files untouched / concurrent work preserved

- `2026_08_18_100000_converge_products_unit_id_nullability.php` — owner's, left exactly as found
- `order-reservation-cell.tsx` — staged by another session, untouched
- 9+ other untracked migrations (Commerce/Orders, CostManagement, Finance, Logistics, Inventory
  ReceiptLayers) — untouched
- No `git add`, `reset`, `clean`, `restore`, or commit was run

## 7. Did runtime become available

**Yes.** With the blocker closed and the runner free, the Procurement E2E suite was deployed and
launched through the gate.

### Count correction

The suite is **19 tests**, not 17. Run B's `17/17` was the pre-VAT-fix file; the two VAT tests
added afterwards bring it to 19. Earlier sections of the Procurement report label it "15" and then
"17" — both are wrong by two, and the correct current figure is **19**.

---

## Status

```
ENVIRONMENT BLOCKER            CLOSED (repaired by owner; verified, not modified)
PROCUREMENT                    IMPLEMENTATION COMPLETE
                               RUNTIME VERIFICATION IN PROGRESS (19-test suite launched)
CERTIFICATION                  DEFERRED
```

A successful migration is **not** certification, and none is claimed here. The only claim in this
report is that the blocker preventing PHPUnit from running is gone.
