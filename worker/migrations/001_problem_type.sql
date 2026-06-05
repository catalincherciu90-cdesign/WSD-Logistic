-- Migrare 001: tipul problemei la cerere (pana, baterie, tractare, etc.)
-- Aplica pe D1-ul EXISTENT (cu date), o singura data:
--   wrangler d1 execute depanauto --remote --file=./migrations/001_problem_type.sql
-- Pe o baza noua creata din schema.sql aceste coloane exista deja; rularea ar da
-- eroare "duplicate column" — atunci sari peste aceasta migrare.

ALTER TABLE requests ADD COLUMN problem_type TEXT;
ALTER TABLE requests ADD COLUMN problem_desc TEXT;
