-- 015_auth_settings.sql
-- Seed settings rows for the multi-provider auth system. All blank by default;
-- the installer / admin auth page populates them. AuthProviders considers a
-- provider enabled when its `*_enabled` row is '1' AND its credentials are
-- non-empty.

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
-- Master toggles
('auth_local_enabled',          '1'),
('auth_github_enabled',         '0'),
('auth_google_enabled',         '0'),

-- GitHub OAuth (mirrors legacy .env vars; bootstrapped from .env on first boot)
('auth_github_client_id',       ''),
('auth_github_client_secret',   ''),
('auth_github_allowed_users',   ''),

-- Google OAuth
('auth_google_client_id',       ''),
('auth_google_client_secret',   ''),
('auth_google_allowed_emails',  '');
