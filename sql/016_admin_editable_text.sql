-- 016_admin_editable_text.sql
-- Seeds settings rows for text that was previously hardcoded in public pages:
--   * privacy_policy_html — full body HTML for /privacy.php (admin-trusted)
--   * privacy_updated     — display string for the "Last updated" line
--   * nav_external_url    — optional external link in the nav (e.g. an app site)
--   * nav_external_label  — visible label for the external nav link
--   * event_type_labels   — JSON mapping of event_type ENUM values → display label
--
-- Defaults are intentionally empty for the privacy/nav rows so a fresh install
-- doesn't render placeholder copy from this codebase's prior author.

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
('privacy_policy_html', ''),
('privacy_updated',     ''),
('nav_external_url',    ''),
('nav_external_label',  'App'),
('event_type_labels',   '{"pottery_show":"Pottery Show","pottery_sale":"Pottery Sale","storefront_sale":"Storefront Sale","class":"Class"}');
