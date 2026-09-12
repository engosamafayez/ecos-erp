# ECOS ERP — Release v1.0.0

**Release:** v1.0.0
**Certified SHA:** `4247efbf7281f95ef0da9b3614de00a2164df80d`
**Certification Task:** TASK-ECOS-V1-SECURITY-INTEGRATION-AND-FINAL-CERTIFICATION-039
**Certification Verdict:** PASSED
**Certification Date:** 2026-09-12
**Manual User Review:** DEFERRED BY USER DECISION

`main` and the `v1.0.0` tag both point at the certified SHA above — the
exact technically-certified application/runtime commit, deliberately not
including any later report-only `develop` commits.

## Known Post-V1 Debt

- **Full backend test harness / `RefreshDatabase` multi-hour bootstrap** —
  a fresh PHP process re-migrates the entire schema from scratch on first
  use, making isolated single-test verification impractical without a
  schema-caching/dump-based fix. Classified POST-V1 / V1.1 CI hygiene debt;
  does not block V1.
- **Historical workspace cleanup** — several old task worktrees/branches
  under `E:\ECOS\_*` remain from completed work (some retired, some left in
  place deliberately, e.g. uncommitted loose files blocking plain removal).
  Deliberately not cleaned up as part of this release; tracked as its own
  future housekeeping pass, not a release blocker.
- **Preserved, explicitly-deferred items surfaced during V1 remediation**
  (none block V1; each was investigated and consciously left open rather
  than silently dropped):
  - Collaboration unread-count anomaly (`CollaborationMessageTest` and
    related same-second tests observing `unread_count: 0` unexpectedly) —
    first flagged during Task 037's full-suite run, not yet root-caused.
  - `CollaborationPermissionCatalogTest`'s dependency on migration-seeded
    permission-catalog rows, which a schema-only test-database clone
    (used during Task 038's focused security verification) does not
    populate — a test-environment characteristic, not a product defect.
  - 035D Item 6 (Collaboration Task Activity Notification) — investigated
    exhaustively, classified as an environment issue with no product source
    change made; no reproducing evidence was ever recovered.

This file is intentionally not part of the certified v1.0.0 source — it
lives on `develop`, one commit past the report-only `9c36db0b`, per the
release task's own instruction not to add any commit on top of the frozen
release SHA.
