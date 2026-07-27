-- Migrare 002: index compus pentru interogarea "depanatori online activi".
-- Sigur si idempotent (IF NOT EXISTS) - se poate rula oricand pe D1-ul existent:
--   wrangler d1 execute depanauto --remote --file=./migrations/002_perf_indexes.sql
-- Nu blocheaza si nu modifica date; doar accelereaza interogarile pe masura ce cresc.

CREATE INDEX IF NOT EXISTS idx_users_role_seen ON users(role, online, last_seen);
