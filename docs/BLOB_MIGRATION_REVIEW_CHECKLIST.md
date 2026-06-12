# BLOB-to-File Migration — Review Checklist

Use this checklist to verify an **Oracle-based** codebase achieves the same **functionality and performance** as the reference Laravel/MySQL BLOB migration pipeline.

Mark each item **PASS**, **PARTIAL**, **FAIL**, or **N/A**. Cite file/class/SQL as evidence.

---

## Reference behavior

```
PENDING ──dispatch──► QUEUED ──worker claim──► PROCESSING ──success──► DONE
   ▲                      │                           │
   │                      │                           └──failure──► FAILED
   └── stale recovery ────┘
```

**Core rules:**

- One row = one async worker unit
- Manual retry only (re-dispatch `PENDING` / `FAILED`)
- Files on disk: `{base_path}/{uuid}` (uuid only, no extension)
- Source BLOB column is never modified or deleted
- Short DB transactions; file I/O outside transactions

---

## A. Schema & data model

| # | Check | PASS criteria |
|---|--------|---------------|
| A1 | Source table has blob column | Column exists (Oracle: `BLOB`; e.g. `attach_file`) |
| A2 | Identity columns present | `id` (PK), `uuid`, `ext` |
| A3 | Upload tracking | `is_uploaded` (boolean/flag, default not uploaded) |
| A4 | File metadata | `file_path`, `processed_at` |
| A5 | Migration state machine | `migration_status`: `PENDING`, `QUEUED`, `PROCESSING`, `DONE`, `FAILED` |
| A6 | Attempt counter | `migration_attempts` (incremented on each worker claim) |
| A7 | Lock metadata | `migration_locked_at`, optional `migration_worker_id` |
| A8 | Result metadata | `migration_file_size`, `migration_sha256` |
| A9 | Queue timestamp | `migration_queued_at` |
| A10 | Last error on row | `migration_last_error` |
| A11 | Error log table | Separate table: `attach_id`, `worker_id`, `error_message`, `error_trace`, `created_at` |
| A12 | Indexes for dispatcher | Index covering `(is_uploaded, migration_status)` or equivalent |
| A13 | Index for stale recovery | Index on `migration_locked_at` (alone or composite) |

---

## B. Configuration

| # | Check | PASS criteria |
|---|--------|---------------|
| B1 | Configurable base path | Single config/env for output directory |
| B2 | Configurable batch size | Dispatcher accepts batch limit (e.g. 100–500) |
| B3 | Dedicated worker queue | Jobs routed to isolated queue/channel (e.g. `blob-migration`) |
| B4 | Configurable stale timeout | Recovery accepts minutes threshold (e.g. `--stale-minutes=30`) |

---

## C. Dispatcher (batch claim + enqueue)

| # | Check | PASS criteria |
|---|--------|---------------|
| C1 | Entry point exists | CLI command, scheduled job, or API trigger |
| C2 | Eligibility filter | `is_uploaded = 0`, blob NOT NULL, `uuid` NOT NULL, `migration_status IN ('PENDING','FAILED')` |
| C3 | Batch limit | At most N rows per run |
| C4 | Stable ordering | `ORDER BY id` |
| C5 | Non-blocking row lock | Concurrent-safe locking (see Oracle note below) |
| C6 | Short transaction | SELECT + status update committed **before** enqueue |
| C7 | Mark as QUEUED | Sets `migration_status=QUEUED`, `migration_queued_at=now`, `migration_locked_at=now` |
| C8 | One job per row | One async task per claimed `id` |
| C9 | No blob loaded in dispatcher | Claim query selects `id` only |
| C10 | Dry-run mode | Counts eligible rows without updating or enqueueing |
| C11 | Idempotent dispatch | Does not re-queue `QUEUED` / `PROCESSING` / `DONE` rows |

**Oracle note (C5):** MySQL equivalent is `SELECT … FOR UPDATE SKIP LOCKED`.

- Oracle 12c+: `FOR UPDATE SKIP LOCKED`
- Older Oracle: document alternative (`FOR UPDATE NOWAIT` + retry, optimistic conditional UPDATE, etc.)

---

## D. Worker / queue job (process one row)

| # | Check | PASS criteria |
|---|--------|---------------|
| D1 | One row per job | Worker receives single attach ID |
| D2 | Conditional claim | Updates only if `migration_status='QUEUED'` AND `is_uploaded=0`; exits if 0 rows updated |
| D3 | Status on claim | Sets `PROCESSING`, `migration_locked_at=now`, increments `migration_attempts` |
| D4 | Minimal column load | Loads only `id`, `uuid`, `ext`, blob column |
| D5 | No automatic retry | Manual re-dispatch only; no queue retry storm |
| D6 | Duplicate-job guard | Per-ID overlap lock or equivalent |
| D7 | BLOB untouched | Never UPDATE/DELETE source blob column |
| D8 | File write delegated | Dedicated writer/service class |
| D9 | Success update | `is_uploaded=1`, `file_path`, `processed_at`, `DONE`, size, sha256; clears lock fields |
| D10 | Failure update | `FAILED`, `migration_last_error`; clears lock fields |
| D11 | Error log insert | Inserts into error table with message + stack trace |
| D12 | No exception re-throw | Failure recorded in DB; job completes without auto-retry |
| D13 | No long transaction around I/O | BLOB read + file write not inside one long DB transaction |

---

## E. File writer

| # | Check | PASS criteria |
|---|--------|---------------|
| E1 | Deterministic path | `{base_path}/{uuid}` — no file extension |
| E2 | `ext` not in filename | `ext` in DB only |
| E3 | Config-driven base path | Uses configured base path |
| E4 | Directory creation | Creates base directory if missing |
| E5 | Atomic write | Temp file in same directory, then rename to final path |
| E6 | Temp cleanup on failure | Deletes temp file on write/rename failure |
| E7 | Idempotent if exists | If final file exists, skip write and return metadata |
| E8 | Returns metadata | path, size, sha256, `alreadyExists` flag |
| E9 | Hash verification on reuse | Compares blob sha256 vs file sha256; fails on mismatch |
| E10 | Same filesystem rename | Temp and final path on same volume (atomic rename) |

---

## F. Stale recovery (separate command)

| # | Check | PASS criteria |
|---|--------|---------------|
| F1 | Separate entry point | Dedicated command/job, not mixed into dispatcher |
| F2 | Stale criteria | `migration_status IN ('QUEUED','PROCESSING')` AND `migration_locked_at < cutoff` |
| F3 | Reset to retryable | `migration_status=PENDING`; clears lock fields |
| F4 | Configurable cutoff | `--stale-minutes` or equivalent |
| F5 | Dry-run mode | Counts stale rows without updating |
| F6 | Does not delete files | Recovery resets DB state only |

---

## G. Concurrency & correctness

| # | Check | PASS criteria |
|---|--------|---------------|
| G1 | No double processing | Two workers cannot both mark same row `DONE` |
| G2 | Non-blocking locks | Multiple dispatchers run in parallel without blocking |
| G3 | Claim is atomic | `QUEUED → PROCESSING` is one conditional UPDATE |
| G4 | Crash during PROCESSING | Stale recovery makes row retryable |
| G5 | Crash after file write, before DB update | Existing file detected; row completed idempotently |
| G6 | Crash mid-file-write | Final path never contains partial file |
| G7 | DONE rows skipped | Dispatcher never selects `DONE` or `is_uploaded=1` |

---

## H. Performance (millions of rows)

| # | Check | PASS criteria |
|---|--------|---------------|
| H1 | No ORM row-by-row loops | Raw SQL / bulk operations in hot path |
| H2 | Dispatcher reads IDs only | Claim query does not SELECT BLOB |
| H3 | Bounded batch size | Each dispatch run capped (100–500 typical) |
| H4 | Index-backed filter | WHERE uses indexed columns |
| H5 | One blob per worker | Each worker loads one BLOB at a time |
| H6 | File I/O outside TX | No open transaction during blob read or disk write |
| H7 | Queue parallelism | Multiple workers on different IDs concurrently |
| H8 | No full-table scan per row | Worker fetches by primary key |
| H9 | Memory awareness (Oracle) | BLOB streaming for large LOBs (`DBMS_LOB`, stream APIs) |
| H10 | Horizontal scale | Multiple hosts, shared DB + shared filesystem |

---

## I. Operational & observability

| # | Check | PASS criteria |
|---|--------|---------------|
| I1 | Manual retry workflow | Re-run dispatcher to retry `FAILED` rows |
| I2 | Progress queryable | Count rows by `migration_status` |
| I3 | Failure diagnosable | `migration_last_error` + error log populated |
| I4 | Optional worker ID | `migration_worker_id` during dispatch/process |
| I5 | Timestamps for SLA | `migration_queued_at`, `migration_locked_at`, `processed_at` |

---

## J. Oracle-specific verification

| # | Check | PASS criteria |
|---|--------|---------------|
| J1 | BLOB column type | Uses `BLOB` or appropriate LOB type |
| J2 | Lock syntax | Oracle version and locking strategy documented |
| J3 | Boolean mapping | `is_uploaded` mapped correctly (`NUMBER(1)` / `CHAR(1)`) |
| J4 | Timestamp timezone | Consistent timezone for lock cutoff |
| J5 | VARCHAR2 limits | `file_path`, `migration_last_error` sizes adequate |
| J6 | Sequence / identity | PK compatible with `ORDER BY id` scanning |
| J7 | LOB storage | LOB tablespace/chunk settings won't block concurrent reads |
| J8 | Transaction isolation | Claim isolation level documented |

---

## K. End-to-end scenario tests

| # | Scenario | Expected outcome |
|---|----------|------------------|
| K1 | Happy path: pending row with blob | File at `{base}/{uuid}`, row `DONE`, `is_uploaded=1` |
| K2 | NULL blob | Never selected by dispatcher |
| K3 | NULL uuid | Never selected by dispatcher |
| K4 | File exists, row pending | Row `DONE` without rewrite; sha256 matches |
| K5 | File exists, sha256 mismatch | Row `FAILED`, error logged |
| K6 | Two dispatchers concurrently | No duplicate jobs for same ID |
| K7 | Two workers same ID | Only one completes |
| K8 | Crash after `PROCESSING` | Stale recovery → re-dispatch succeeds |
| K9 | Crash mid-write | No file at final path; retry writes cleanly |
| K10 | Failed row | Stays `FAILED` until manual re-dispatch |
| K11 | DONE row re-dispatched | Skipped by eligibility filter |
| K12 | Blob after success | Unchanged byte-for-byte |

---

## L. Scoring guide

| Result | Meaning |
|--------|---------|
| **PASS** | Requirement fully met |
| **PARTIAL** | Implemented with gaps (document gap) |
| **FAIL** | Missing or incorrect |
| **N/A** | Intentionally different (document alternative) |

**Functional parity:** All A–K items PASS or acceptable N/A.

**Performance parity:** All H items PASS.

**Oracle blockers (must not FAIL):** C5, D13, E5, G1, G6, H1, H2, H6, H9, J2.

---

## Oracle SQL equivalents

```sql
-- Claim (Oracle 12c+)
SELECT id
FROM dms_attach
WHERE is_uploaded = 0
  AND attach_file IS NOT NULL
  AND uuid IS NOT NULL
  AND migration_status IN ('PENDING', 'FAILED')
ORDER BY id
FETCH FIRST 250 ROWS ONLY
FOR UPDATE SKIP LOCKED;

-- Worker claim
UPDATE dms_attach
SET migration_status = 'PROCESSING',
    migration_locked_at = SYSTIMESTAMP,
    migration_attempts = migration_attempts + 1
WHERE id = :id
  AND is_uploaded = 0
  AND migration_status = 'QUEUED';
-- Expect 1 row updated, else exit
```

---

## Reference implementation (Laravel / MySQL)

| Component | Location |
|-----------|----------|
| Dispatcher | `app/Console/Commands/DispatchDmsAttachBlobMigrationCommand.php` |
| Stale recovery | `app/Console/Commands/RecoverStaleDmsAttachMigrationsCommand.php` |
| Worker job | `app/Jobs/ProcessDmsAttachBlobMigrationJob.php` |
| File writer | `app/Support/DmsAttachBlobFileWriter.php` |
| Status constants | `app/Support/DmsAttachMigrationStatus.php` |
| Config | `config/filesystems.php` → `blob_migration_base_path`, `blob_migration_queue` |

### Usage

```bash
# Recover stuck rows (optional)
php artisan dms:recover-stale-attach-migrations --stale-minutes=30

# Dispatch a batch
php artisan dms:dispatch-attach-blob-migration --batch-size=250

# Process the queue
php artisan queue:work --queue=blob-migration
```
