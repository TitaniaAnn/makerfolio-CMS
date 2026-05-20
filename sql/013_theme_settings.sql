-- 013_theme_settings.sql
-- Seeds default theme settings rows. No schema changes — these all live in the
-- existing key/value `settings` table. Resolved by includes/Theme.php; admins
-- edit via /admin/settings/theme.php.
--
-- INSERT IGNORE means existing rows are preserved on re-application.

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
('theme_preset',              'terra-gold'),
('theme_override_primary',    ''),
('theme_override_accent',     ''),
('theme_override_background', ''),
('theme_override_text',       ''),
('theme_font_display',        'playfair-display'),
('theme_font_body',           'nunito'),
('theme_radius_scale',        'default'),
('theme_shadow_scale',        'default');
