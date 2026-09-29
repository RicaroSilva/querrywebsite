-- Per-analysis statement timeout (seconds). NULL = QUERY_TIMEOUT_SECONDS.
ALTER TABLE analyses ADD COLUMN timeout_seconds INT NULL;
