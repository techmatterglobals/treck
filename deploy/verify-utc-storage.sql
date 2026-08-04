-- =============================================================================
-- Treck — UTC storage verification (run BEFORE deploying the UtcDateTime cast)
-- =============================================================================
--
-- The cast assumes every agent-sourced instant column is stored as UTC
-- wall-clock digits. These queries PROVE that against your live data and flag
-- any row that was stored in local (Asia/Karachi) time instead.
--
-- How the detection works
-- -----------------------
-- Each agent-sourced column has a sibling column stamped by the server with
-- now() (Asia/Karachi):
--     agent_events.occurred_at        vs  agent_events.received_at
--     computer_presence.last_event_at vs  computer_presence.last_synced_at
--     application_usage.used_at        vs  application_usage.created_at
--     screenshots.captured_at         vs  screenshots.created_at
--     file_downloads.downloaded_at    vs  file_downloads.created_at
--
-- If the agent column is UTC and the sibling is Karachi (+05:00), the digit gap
-- (sibling - agent) is the real latency PLUS 5 hours (18000s). So:
--
--     gap ≈ 18000s  -> UTC storage confirmed (real-time events)
--     gap  > 18000s -> UTC + buffered/offline delivery (still correct)
--     gap  < ~16200s (4.5h) on a RECENT row -> RED FLAG: the agent column was
--                     stored in local time. Investigate before deploying.
--
-- Pakistan does not observe DST, so the offset is a constant +5h. (MySQL
-- syntax; Postgres equivalents noted at the bottom.)
-- =============================================================================

-- 1) agent_events — newest rows, eyeball the gap (expect ~18000 on recent ones)
SELECT id, kind, occurred_at, received_at,
       TIMESTAMPDIFF(SECOND, occurred_at, received_at) AS gap_seconds
FROM agent_events
ORDER BY id DESC
LIMIT 20;

-- 1a) agent_events — aggregate red-flag count over the last 7 days
SELECT
    COUNT(*)                                                                AS rows_checked,
    MIN(TIMESTAMPDIFF(SECOND, occurred_at, received_at))                    AS min_gap_s,
    MAX(TIMESTAMPDIFF(SECOND, occurred_at, received_at))                    AS max_gap_s,
    SUM(TIMESTAMPDIFF(SECOND, occurred_at, received_at) < 16200)            AS suspicious_rows
FROM agent_events
WHERE received_at >= NOW() - INTERVAL 7 DAY;
-- Expect: suspicious_rows = 0, min_gap_s ≈ 18000.

-- 2) computer_presence — every current row (expect gap ≈ 18000)
SELECT computer_id, last_event_at, last_heartbeat_at, last_activity_at, last_synced_at,
       TIMESTAMPDIFF(SECOND, last_event_at, last_synced_at) AS gap_seconds
FROM computer_presence
ORDER BY computer_id;

-- 3) application_usage — newest rows (gap >= ~18000; larger is fine for long sessions)
SELECT id, used_at, ended_at, created_at,
       TIMESTAMPDIFF(SECOND, used_at, created_at) AS gap_seconds
FROM application_usage
ORDER BY id DESC
LIMIT 20;

-- 3a) application_usage — red-flag count (last 7 days)
SELECT COUNT(*) AS rows_checked,
       SUM(TIMESTAMPDIFF(SECOND, used_at, created_at) < 16200) AS suspicious_rows
FROM application_usage
WHERE created_at >= NOW() - INTERVAL 7 DAY;

-- 4) screenshots — newest rows (gap ≈ 18000)
SELECT id, captured_at, created_at,
       TIMESTAMPDIFF(SECOND, captured_at, created_at) AS gap_seconds
FROM screenshots
ORDER BY id DESC
LIMIT 20;

-- 4a) screenshots — red-flag count (last 7 days)
SELECT COUNT(*) AS rows_checked,
       SUM(TIMESTAMPDIFF(SECOND, captured_at, created_at) < 16200) AS suspicious_rows
FROM screenshots
WHERE created_at >= NOW() - INTERVAL 7 DAY;

-- 5) file_downloads — newest rows (gap ≈ 18000)
SELECT id, downloaded_at, created_at,
       TIMESTAMPDIFF(SECOND, downloaded_at, created_at) AS gap_seconds
FROM file_downloads
ORDER BY id DESC
LIMIT 20;

-- 5a) file_downloads — red-flag count (last 7 days)
SELECT COUNT(*) AS rows_checked,
       SUM(TIMESTAMPDIFF(SECOND, downloaded_at, created_at) < 16200) AS suspicious_rows
FROM file_downloads
WHERE created_at >= NOW() - INTERVAL 7 DAY;

-- =============================================================================
-- Interpreting the result
-- =============================================================================
-- All suspicious_rows = 0  ->  every column is UTC. Deploy the cast; no
--                             migration, no shift, fully backward-compatible.
--
-- Any suspicious_rows > 0  ->  send the offending rows (queries 1–5 with a
--                             WHERE on the small gap) before deploying; those
--                             specific rows would need a one-off correction.
-- =============================================================================
--
-- Postgres equivalents:
--   TIMESTAMPDIFF(SECOND, a, b)  ->  EXTRACT(EPOCH FROM (b - a))
--   NOW() - INTERVAL 7 DAY       ->  NOW() - INTERVAL '7 days'
--   SUM(cond)                    ->  COUNT(*) FILTER (WHERE cond)
-- =============================================================================
