---
description: "Write and apply a DB migration SQL to the live database. Covers table creation, column additions, index changes. Invoke: /db-migrate"
name: "DB Migration"
argument-hint: "What to migrate (e.g. 'add whatsapp_opt_in column to guests table')"
agent: "agent"
model: "Claude Sonnet 4.5 (copilot)"
---

Write and apply a database migration for this project.

**Input**: $args

## Rules

- Target: live DB at `promanaged-it.com` — credentials from `.env` only
- **Never** run SQL against old dump files
- All migrations must be **idempotent** (safe to run twice):
  - Use `IF NOT EXISTS` for CREATE TABLE / ADD COLUMN
  - Use `IF EXISTS` for DROP
  - Use `INSERT ... ON DUPLICATE KEY UPDATE` for data inserts
- New tables always include: `id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY`, `created_at DATETIME DEFAULT CURRENT_TIMESTAMP`, `updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`
- snake_case table/column names
- Boolean columns: `is_*` or `has_*` prefix, `TINYINT(1)` type

## What to do

1. Write the migration SQL
2. Show it to the user and confirm before running
3. Run it against the live DB using credentials from `.env`
4. Verify by querying `SHOW COLUMNS FROM table` or `DESCRIBE table`
5. If it adds a new setting, insert a row into `site_settings` with a sensible default
6. One sentence: what changed in the DB.
