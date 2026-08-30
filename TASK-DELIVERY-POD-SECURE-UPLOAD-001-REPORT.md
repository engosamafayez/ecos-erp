# TASK-DELIVERY-POD-SECURE-UPLOAD-001 — Secure Delivery Proof-of-Delivery Upload — AUDIT & DESIGN REPORT

**Parent:** TASK-DRIVER-04 decision **B** (owner-authorized separate backend task).
**Mode:** audit-first; owner: *"If the safe implementation requires a migration, new proof table, or domain change, document it explicitly before proceeding."*
**Date:** 2026-08-26
**Status:** ✅ **IMPLEMENTED + VERIFIED** (driver-first additive). Owner chose "Driver-first, additive"; the documented additive migration + secure upload + retrieval are built and tested. The dispatcher endpoint is untouched and recorded as a systemic follow-up. See §7.

> **Audit-first note (retained):** §1–§6 are the audit + design that the owner approved. §7 records what was built.

---

## 1. The current (unsafe) contract — confirmed

- **Driver ingestion:** `POST /api/driver/stops/{stopId}/proof` → `DriverRuntimeController::proof` (`:336-350`). Validates `signature_path` / `photos.*` as **plain strings (max 500)** and `notes` — **no `file`, no `mimes`, no size**.
- **Dispatcher ingestion (SHARED weakness):** `POST /trips/{tripId}/stops/{stopId}/proof` → `DeliveryController::captureProof` (`:129-145`) — **byte-identical** string rules, gated `permission:logistics.distribution.update`.
- **Persistence:** `DeliveryService::captureProof` (`:116-122`) writes the client strings verbatim — no `Storage::`, no disk write, no existence check. Every field is nullable, so **an empty POST creates a valid "proof" with no evidence.**
- **No retrieval:** there is no download/serve endpoint for a Distribution delivery proof anywhere (grep for `Storage::`/`download` in the module → none). The proof is only echoed as raw strings via `DeliveryProofResource` inside stop reads.
- **Tenancy:** `distribution_delivery_proofs` has **no `company_id`**; tenancy is transitive `proof → stop → trip.company_id`, enforced at the controller (`ownedStop`/`resolveTrip`).

## 2. The safe pattern to mirror (payment-proof) — confirmed reusable

`UploadPaymentProofAction` (`Modules\Commerce\Orders\Application\Actions`) is the proven safe upload: `required|file|max:10240|mimes:jpg,jpeg,png,pdf`; stores to the **private `local` disk** (no public URL); **server-minted ULID path** `…/{company_id}/{ULID}.{ext}`; **content-sniffed MIME** (`getMimeType()`); tenant-stamped `company_id`; retrieval via an authenticated, tenant-scoped **`StreamedResponse`** download gated by a permission. All of this is generic and directly reusable.

## 3. Owner constraint alignment

- **#9 "do NOT reuse `PaymentProof` as the POD record"** — satisfied. The codebase already mandates a separate record: a CTO boundary asserts *"Distribution keeps DeliveryStop, DeliveryProof, DeliveryException and TripReturn; Delivery OS must never write a `distribution_*` table"* (`tests\Feature\Logistics\DeliveryModuleTest.php:797-826`), and the `delivery_pods` migration comment states *"Distribution's DeliveryProof remains its own trip-operational record."* → **Keep `DeliveryProof` as the POD record; replace only its ingestion.**
- **#10 "do NOT create a second generic upload system if the existing infra can be reused"** — satisfied by a new `UploadDeliveryProofAction extends BaseAction` reusing the payment-proof building blocks (private disk, ULID path `delivery-proofs/{company_id}/{ULID}.{ext}`, MIME sniff, `Storage::put/download`), **without** touching `payment_proofs`.

## 4. Required migration / domain / contract changes — DOCUMENTED (per owner "document before proceeding")

A secure version **cannot** be done by validation-tightening alone. It forces:

1. **Migration (new).** `distribution_delivery_proofs` currently stores client path strings. Add server-artifact identity: `storage_disk`, and treat `signature_path` as a server-generated path; for photos, either keep the JSON column but populate it with **server-generated paths**, or (cleaner, per-file audit) a child table `distribution_delivery_proof_photos` (`proof_id`, `disk`, `path`, `original_filename`, `mime_type`, `size_bytes`). The existing migration early-returns on `hasTable`, so a **new** migration is required. Tenancy path should embed `stop→trip→company_id` (the table has no `company_id`).
2. **Domain change.** `DeliveryService::captureProof` (or a new `UploadDeliveryProofAction`) must accept `UploadedFile`/`UploadedFile[]`, `Storage::disk('local')->put(...)` with a server path + MIME sniff, and **reject empty proof** (require a signature or ≥1 photo).
3. **Breaking API contract.** Both endpoints move from JSON string bodies to `multipart/form-data`. This is the crux decision below.
4. **New retrieval endpoint (recommended).** A tenant-scoped, permission-gated `StreamedResponse` download (none exists today), mirroring `orders/{order}/payment-proofs/{proof}/download`.

## 5. The scope decision — driver-only vs the shared dispatcher endpoint

`DeliveryService::captureProof` has **two callers** with the identical weak contract:
- **Driver:** `DriverRuntimeController::proof` — its UI was already **de-exposed** in TASK-DRIVER-04 Part C (the driver POD button was removed), so changing this endpoint breaks **no live driver UI**.
- **Dispatcher:** `DeliveryController::captureProof` — this **is** wired to a live UI: `frontend/src/features/logistics/trips` (`trip-execution-service.ts:142`, `trip-stops-tab.tsx` `useCaptureProof`). Making it multipart is a **breaking change to a live operator surface**.

Tests coupled to the current contract that must change: `DistributionModuleTest.php:578` (posts string paths) and the CTO-boundary invariant `DeliveryModuleTest.php:797-826` (must stay green — the secure record is still `distribution_delivery_proofs`).

**Owner decision needed:** (a) **Driver-first, additive** — add a NEW secure multipart POD upload path used by the driver only, leave the legacy dispatcher endpoint untouched for now (smaller blast radius; the dispatcher stays weak but unchanged); or (b) **Systemic** — upgrade `captureProof` for **both** callers to multipart (fixes the weakness everywhere, but requires updating the dispatcher's live frontend in lockstep — out of the driver-focused scope).

## 6. Recommended plan (pending §5)

Recommend **(a) driver-first, additive**: new `UploadDeliveryProofAction` + new migration (server-artifact columns / photos child table) + secure multipart driver route + tenant-scoped download endpoint + the focused security tests the owner listed (empty rejected, invalid MIME rejected, oversized rejected, valid multipart, server-controlled path, tenant isolation, driver ownership, unauthorized access, retrieval access control). Then the dispatcher can be migrated in a follow-up with its frontend. Driver POD UI stays **unwired** until this is verified (owner #14). STOP after the backend task + this report.

**(Superseded by §7 — the owner chose "Driver-first, additive" and authorised the migration.)**

## 7. Implemented + verified (driver-first, additive)

**Migration (additive + backward-compatible)** — `2026_08_26_140000_add_secure_storage_to_distribution_delivery_proofs`: adds nullable `storage_disk`, `signature_mime`, `signature_size` to `distribution_delivery_proofs`. Legacy rows (`storage_disk = null`) stay valid and are not treated as secure. Photos are stored in the existing `photos` JSON column as structured entries `{disk, path, mime_type, size_bytes, original_filename}` — no new table needed. Guarded with `hasColumn` so it is safe to re-run. `DeliveryProof` remains Distribution's own POD record (CTO boundary); `payment_proofs` is NOT reused.

**Secure upload** — `Modules\Logistics\Distribution\Application\Actions\UploadDeliveryProofAction`: real `UploadedFile` inputs only; stored on the **private `local`** disk under a **server-generated** path `delivery-proofs/{company_id}/{ULID}.{ext}` (company from `stop → trip → company_id`); MIME sniffed from real content; never accepts a client path.

**Endpoints** (driver group, `loading.driver.operate`, fail-closed via `ownedStop`):
- `POST /driver/stops/{stopId}/delivery-proof` → `uploadDeliveryProof`: validates `signature`/`photos.*` as `file|max:10240|mimes:...`; **refuses empty proof** (no signature and no photo); stores via the action.
- `GET /driver/stops/{stopId}/delivery-proof/{kind}/{index?}` (`kind` = signature|photo) → `downloadDeliveryProof`: streams from the private disk **only paths RECORDED on this stop's own proof** — never a client path; 404 for a missing artifact.

**Not touched (per the decision):** the legacy `proof()` / `DeliveryService::captureProof` and the shared **dispatcher** endpoint are unchanged; both the route and the method are annotated **FOLLOW-UP — SYSTEMIC DELIVERY POD SECURITY**. No payment/inventory/custody/delivery-quantity behaviour added. The old unsafe endpoint is not re-wired to any driver UI.

**Tests (all green, via the pinned gate) — `DriverDeliveryProofSecureUploadTest`:** valid multipart upload (server path + private disk + files exist), signature-only accepted, empty rejected, arbitrary client path-string rejected, invalid MIME rejected, oversized rejected, cross-driver upload refused (404), non-driver 403, unauthenticated 401, secure retrieval (owner streams signature+photo; bad index 404; other driver 404), the legacy string endpoint still functions (no dispatcher regression), and migration-columns-exist. Plus `DeliveryModuleTest` (CTO boundary, `distribution_delivery_proofs` row-count invariance under Delivery-OS) — 35/35 green, unaffected by the added columns.

**Follow-up (NOT started, per owner):** **SYSTEMIC DELIVERY POD SECURITY** — migrate the shared dispatcher endpoint (`DeliveryController::captureProof`) and its live logistics/trips UI to the same secure multipart contract. Deliberately deferred; the legacy endpoint is not declared secure.
