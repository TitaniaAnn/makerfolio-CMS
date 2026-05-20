-- 014_admin_users_auth.sql
-- Evolve admin_users from "single OAuth provider per user" to a unified table
-- that supports: local username/password, GitHub OAuth, and Google OAuth — any
-- combination per row (a row is valid if at least one of username, github_id,
-- google_sub is non-null).
--
-- Notes:
--   * MySQL allows multiple NULLs in a UNIQUE column, so we can keep the unique
--     constraints on the new identifier columns without losing rows.
--   * Existing rows store the GitHub user id in `provider_user_id` (despite
--     the column comment in init.sql saying "was google_id"). We copy them
--     into `github_id`. `provider_user_id` is kept for one release as a safety
--     net, then dropped in a future migration.
--   * `email` becomes nullable because local-auth users may not provide one.

ALTER TABLE admin_users
    ADD COLUMN username       VARCHAR(64)  NULL UNIQUE  AFTER id,
    ADD COLUMN password_hash  VARCHAR(255) NULL         AFTER username,
    ADD COLUMN github_id      VARCHAR(64)  NULL UNIQUE  AFTER password_hash,
    ADD COLUMN google_sub     VARCHAR(255) NULL UNIQUE  AFTER github_id,
    ADD COLUMN google_email   VARCHAR(255) NULL         AFTER google_sub;

-- Migrate existing rows: provider_user_id was the GitHub numeric user id.
UPDATE admin_users SET github_id = provider_user_id WHERE provider_user_id IS NOT NULL;

-- Allow nullable email so OAuth-only or local-only users can exist without one.
ALTER TABLE admin_users MODIFY email VARCHAR(255) NULL;
