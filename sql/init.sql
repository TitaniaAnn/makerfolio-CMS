-- ============================================================
-- pottery_portfolio — canonical schema
-- Run on a fresh database: mysql -u root -p < sql/init.sql
-- For an existing database, see sql/0*.sql migrations.
-- ============================================================

CREATE DATABASE IF NOT EXISTS pottery_portfolio CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE pottery_portfolio;

-- ------------------------------------------------------------
-- 1. ADMIN
-- ------------------------------------------------------------
-- Unified admin user table — supports local username/password, GitHub OAuth,
-- and Google OAuth in any combination per row (a row is valid if at least one
-- of username, github_id, google_sub is non-null). `provider_user_id` is kept
-- nullable for one release as a safety net for the 014 migration; it's unused
-- by application code.
CREATE TABLE IF NOT EXISTS admin_users (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    username             VARCHAR(64)  NULL UNIQUE,
    password_hash        VARCHAR(255) NULL,
    totp_secret          VARCHAR(64)  NULL,
    totp_enabled         TINYINT(1)   NOT NULL DEFAULT 0,
    recovery_codes_hash  TEXT         NULL,
    github_id            VARCHAR(64)  NULL UNIQUE,
    google_sub           VARCHAR(255) NULL UNIQUE,
    google_email         VARCHAR(255) NULL,
    provider_user_id     VARCHAR(255) NULL UNIQUE,
    email                VARCHAR(255) NULL UNIQUE,
    name                 VARCHAR(255),
    avatar_url           TEXT,
    created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login           TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 2. PORTFOLIO
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pottery (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    title       VARCHAR(255) NOT NULL,
    description TEXT,
    technique   VARCHAR(255),
    dimensions  VARCHAR(255),
    year        INT,
    image_path  TEXT NOT NULL,
    image_thumb TEXT,
    alt_text    VARCHAR(500) NULL,
    featured    TINYINT(1) DEFAULT 0,
    sort_order  INT DEFAULT 0,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_featured (featured),
    KEY idx_sort_order (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pottery_images (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    pottery_id  INT NOT NULL,
    image_path  TEXT NOT NULL,
    image_thumb TEXT,
    sort_order  INT DEFAULT 0,
    is_primary  TINYINT(1) DEFAULT 0,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (pottery_id) REFERENCES pottery(id) ON DELETE CASCADE,
    KEY idx_pottery_sort (pottery_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 3. EVENTS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS events (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    event_type       ENUM('pottery_show', 'pottery_sale', 'storefront_sale', 'class') NOT NULL,
    name             VARCHAR(255) NOT NULL,
    description      TEXT,
    location         VARCHAR(255),
    url              TEXT,
    start_date       DATE,
    end_date         DATE,
    publish_date     DATE,
    daily_open_times TEXT,
    class_type       ENUM('handbuilding', 'wheelthrowing', 'month_long', 'workshop'),
    class_age_range  VARCHAR(255),
    class_date_start DATE,
    class_date_end   DATE,
    class_time_start TIME,
    class_time_end   TIME,
    featured         TINYINT(1) DEFAULT 0,
    sort_order       INT DEFAULT 0,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_event_type (event_type),
    KEY idx_start_date (start_date),
    KEY idx_end_date (end_date),
    KEY idx_publish_date (publish_date),
    KEY idx_featured (featured),
    KEY idx_sort_order (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_pottery (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    event_id   INT NOT NULL,
    pottery_id INT NOT NULL,
    label      VARCHAR(255),
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (event_id)   REFERENCES events(id)  ON DELETE CASCADE,
    FOREIGN KEY (pottery_id) REFERENCES pottery(id) ON DELETE CASCADE,
    UNIQUE KEY unique_event_pottery (event_id, pottery_id),
    KEY idx_pottery_id (pottery_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 4. SHOP
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS shop_categories (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(255) NOT NULL,
    slug        VARCHAR(255) UNIQUE NOT NULL,
    type        ENUM('pot', 'merch') NOT NULL,
    description TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    category_id     INT,
    name            VARCHAR(255) NOT NULL,
    description     TEXT,
    price           DECIMAL(10,2),
    type            ENUM('pot', 'merch') NOT NULL DEFAULT 'pot',
    status          ENUM('available', 'sold', 'coming_soon') DEFAULT 'available',
    is_visible      TINYINT(1) NOT NULL DEFAULT 1,
    image_path      TEXT,
    alt_text        VARCHAR(500) NULL,
    dimensions      VARCHAR(255),
    technique       VARCHAR(255),
    quantity        INT DEFAULT 1,
    pod_provider    ENUM('printful', 'printify', 'redbubble', 'other') NULL,
    pod_product_url TEXT NULL,
    pod_product_id  VARCHAR(255) NULL,
    external_url    TEXT NULL,
    sort_order      INT DEFAULT 0,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES shop_categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_images (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    product_id  INT NOT NULL,
    image_path  TEXT NOT NULL,
    image_thumb TEXT NULL,
    sort_order  INT DEFAULT 0,
    is_primary  TINYINT(1) DEFAULT 0,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_product_sort (product_id, sort_order),
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 5. ORDERS (Stripe-backed)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS orders (
    id                    INT AUTO_INCREMENT PRIMARY KEY,
    stripe_session_id     VARCHAR(255) UNIQUE,
    stripe_payment_intent VARCHAR(255),
    product_id            INT,
    product_name          VARCHAR(255),
    product_price         DECIMAL(10,2),
    quantity              INT DEFAULT 1,
    status                ENUM('pending','paid','shipped','cancelled','refunded') DEFAULT 'pending',
    customer_name         VARCHAR(255),
    customer_email        VARCHAR(255),
    shipping_line1        VARCHAR(255),
    shipping_line2        VARCHAR(255),
    shipping_city         VARCHAR(255),
    shipping_state        VARCHAR(255),
    shipping_postal_code  VARCHAR(255),
    shipping_country      VARCHAR(10),
    tracking_number       VARCHAR(255),
    tracking_carrier      VARCHAR(100),
    shipped_at            TIMESTAMP NULL,
    notes                 TEXT,
    created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
    KEY idx_status (status),
    KEY idx_payment_intent (stripe_payment_intent),
    KEY idx_customer_email (customer_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stripe webhook idempotency ledger — every event_id is processed once.
-- Migration ledger written to by includes/MigrationRunner.php so the admin UI
-- can show which sql/NNN_*.sql files have already been applied. Auto-created
-- by the runner if missing — listed here so fresh installs already have it.
CREATE TABLE IF NOT EXISTS schema_migrations (
    version     VARCHAR(255) PRIMARY KEY,
    applied_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    applied_by  INT NULL,
    -- 'run'   = executed by the runner
    -- 'mark'  = pre-existing migration the admin marked as already applied
    source      ENUM('run','mark') NOT NULL DEFAULT 'run',
    notes       TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-IP rate limiting for local login attempts (see includes/Auth::loginLocal).
CREATE TABLE IF NOT EXISTS login_attempts (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    ip_address   VARCHAR(45)  NOT NULL,
    username     VARCHAR(64)  NULL,
    attempted_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Audit trail for admin actions (see includes/ActivityLog).
CREATE TABLE IF NOT EXISTS admin_activity (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    admin_id    INT          NULL,
    action      VARCHAR(64)  NOT NULL,
    target_type VARCHAR(32)  NULL,
    target_id   VARCHAR(64)  NULL,
    details     JSON         NULL,
    ip_address  VARCHAR(45)  NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_admin_time   (admin_id, created_at),
    KEY idx_action_time  (action,   created_at),
    KEY idx_created      (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Single-use tokens for the /admin/auth/forgot-password.php flow.
CREATE TABLE IF NOT EXISTS password_resets (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    admin_id    INT          NOT NULL,
    token_hash  CHAR(64)     NOT NULL UNIQUE,
    expires_at  TIMESTAMP    NOT NULL,
    used_at     TIMESTAMP    NULL,
    ip_address  VARCHAR(45)  NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_admin (admin_id),
    KEY idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Admin-toggleable section visibility + ordering for public pages
-- (see includes/PageSections.php for the CATALOG).
CREATE TABLE IF NOT EXISTS page_sections (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    page          VARCHAR(64)  NOT NULL,
    section_key   VARCHAR(64)  NOT NULL,
    is_visible    TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order    INT          NOT NULL DEFAULT 0,
    UNIQUE KEY uniq_page_section (page, section_key),
    KEY idx_page_visible (page, is_visible, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stripe_webhook_events (
    event_id     VARCHAR(255) PRIMARY KEY,
    type         VARCHAR(100) NOT NULL,
    received_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    -- NULL until the handler runs to completion. Lets a retry of an event
    -- whose handler crashed mid-flight re-execute, while a retry of a fully
    -- processed event short-circuits.
    processed_at TIMESTAMP NULL DEFAULT NULL,
    KEY idx_received_at (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 6. SOCIAL & SITE SETTINGS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS social_links (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    platform   VARCHAR(50) NOT NULL,
    url        TEXT NOT NULL,
    handle     VARCHAR(255),
    active     TINYINT(1) DEFAULT 1,
    sort_order INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS social_posts (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    platform      VARCHAR(50) NOT NULL,
    post_id       VARCHAR(255),
    embed_code    TEXT,
    post_url      TEXT,
    caption       TEXT,
    thumbnail_url TEXT,
    post_date     TIMESTAMP NULL,
    featured      TINYINT(1) DEFAULT 0,
    sort_order    INT DEFAULT 0,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_featured (featured)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    setting_key   VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 7. ANNOUNCEMENTS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS announcements (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    title        VARCHAR(255) NOT NULL,
    description  TEXT,
    publish_date DATETIME NOT NULL,
    image_path   TEXT,
    image_thumb  TEXT,
    image_alt    VARCHAR(500) NULL,
    created_by   INT,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES admin_users(id) ON DELETE SET NULL,
    KEY idx_publish_date (publish_date),
    KEY idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS announcement_links (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    announcement_id INT NOT NULL,
    entity_type     ENUM('event', 'pottery') NOT NULL,
    entity_id       INT NOT NULL,
    sort_order      INT DEFAULT 0,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (announcement_id) REFERENCES announcements(id) ON DELETE CASCADE,
    KEY idx_entity_lookup (entity_type, entity_id),
    KEY idx_announcement_id (announcement_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS announcement_social_posts (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    announcement_id  INT NOT NULL,
    platform         ENUM('instagram', 'tiktok') NOT NULL,
    posted_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    platform_post_id VARCHAR(255),
    status           ENUM('success', 'pending', 'failed') DEFAULT 'pending',
    error_message    TEXT,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (announcement_id) REFERENCES announcements(id) ON DELETE CASCADE,
    KEY idx_platform (platform),
    KEY idx_posted_at (posted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 8. TEMPLATES (downloadable artwork files)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pottery_templates (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    title          VARCHAR(255) NOT NULL,
    description    TEXT,
    category       VARCHAR(100) DEFAULT '',
    preview_path   VARCHAR(500) DEFAULT '',
    preview_thumb  VARCHAR(500) DEFAULT '',
    download_count INT          DEFAULT 0,
    sort_order     INT          DEFAULT 0,
    created_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pottery_template_files (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    template_id INT NOT NULL,
    file_path   VARCHAR(500) NOT NULL,
    file_name   VARCHAR(255) NOT NULL,
    file_size   INT          DEFAULT 0,
    file_ext    VARCHAR(10)  DEFAULT '',
    label       VARCHAR(255) DEFAULT '',
    sort_order  INT          DEFAULT 0,
    FOREIGN KEY (template_id) REFERENCES pottery_templates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 9. SEED DATA
-- ------------------------------------------------------------
-- Branding seeds — kept generic so a fresh `mysql < init.sql` install isn't
-- pre-branded with the template author's identity. The installer (web wizard
-- or bin/install.php) UPSERTs these via Installer::seedBranding using values
-- collected from the operator, so the placeholders below only show through
-- when someone bypasses the installer entirely (e.g. raw schema load).
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
('site_name',     'My Pottery'),
('tagline',       'Handcrafted ceramics'),
('hero_title',    'Shaped by Hand and Fire'),
('hero_subtitle', 'Functional ceramics, handmade with care'),
('shop_currency', 'CAD'),
-- Theme defaults (from migration 013_theme_settings.sql)
('theme_preset',              'terra-gold'),
('theme_override_primary',    ''),
('theme_override_accent',     ''),
('theme_override_background', ''),
('theme_override_text',       ''),
('theme_font_display',        'playfair-display'),
('theme_font_body',           'nunito'),
('theme_radius_scale',        'default'),
('theme_shadow_scale',        'default'),
-- Auth provider defaults (from migration 015_auth_settings.sql)
('auth_local_enabled',          '1'),
('auth_github_enabled',         '0'),
('auth_google_enabled',         '0'),
('auth_github_client_id',       ''),
('auth_github_client_secret',   ''),
('auth_github_allowed_users',   ''),
('auth_google_client_id',       ''),
('auth_google_client_secret',   ''),
('auth_google_allowed_emails',  ''),
-- Admin-editable page text (from migration 016_admin_editable_text.sql)
('privacy_policy_html', ''),
('privacy_updated',     ''),
('nav_external_url',    ''),
('nav_external_label',  'App'),
('event_type_labels',   '{"pottery_show":"Pottery Show","pottery_sale":"Pottery Sale","storefront_sale":"Storefront Sale","class":"Class"}');

INSERT IGNORE INTO shop_categories (name, slug, type) VALUES
('Original Pots', 'original-pots', 'pot'),
('Studio Merch',  'studio-merch',  'merch');

-- Page section visibility + ordering (from migration 017_page_sections.sql)
INSERT IGNORE INTO page_sections (page, section_key, is_visible, sort_order) VALUES
('home',      'hero',           1, 10),
('home',      'featured_work',  1, 20),
('home',      'announcements',  1, 30),
('home',      'events_preview', 1, 40),
('home',      'about_strip',    1, 50),
('home',      'social',         1, 60),
('home',      'shop_teaser',    1, 70),
('about',     'contact',        1, 10),
('about',     'social_links',   1, 20),
('portfolio', 'filters',        1, 10),
('templates', 'filters',        1, 10);

-- ------------------------------------------------------------
-- 10. MIGRATION LEDGER
-- ------------------------------------------------------------
-- init.sql is the consolidated snapshot — every migration up to and including
-- 015 is already represented in the table definitions and seed data above.
-- Pre-mark them as applied so the migration runner doesn't try to re-execute
-- ALTER statements against tables that already have the target columns.
-- Future migrations (016+) get applied normally by the runner.
INSERT IGNORE INTO schema_migrations (version, source, notes) VALUES
('001_pottery_images.sql',      'mark', 'pre-applied via init.sql'),
('005_templates.sql',           'mark', 'pre-applied via init.sql'),
('006_template_files.sql',      'mark', 'pre-applied via init.sql'),
('007_events.sql',              'mark', 'pre-applied via init.sql'),
('008_announcements.sql',       'mark', 'pre-applied via init.sql'),
('009_stripe_webhook_events.sql','mark','pre-applied via init.sql'),
('010_consolidation.sql',       'mark', 'pre-applied via init.sql'),
('011_webhook_processed_at.sql','mark', 'pre-applied via init.sql'),
('012_schema_migrations.sql',   'mark', 'pre-applied via init.sql'),
('013_theme_settings.sql',      'mark', 'pre-applied via init.sql'),
('014_admin_users_auth.sql',    'mark', 'pre-applied via init.sql'),
('015_auth_settings.sql',       'mark', 'pre-applied via init.sql'),
('016_admin_editable_text.sql', 'mark', 'pre-applied via init.sql'),
('017_page_sections.sql',       'mark', 'pre-applied via init.sql'),
('018_login_attempts.sql',      'mark', 'pre-applied via init.sql'),
('019_password_resets.sql',     'mark', 'pre-applied via init.sql'),
('020_admin_activity.sql',      'mark', 'pre-applied via init.sql'),
('021_image_alt_text.sql',      'mark', 'pre-applied via init.sql'),
('022_totp.sql',                'mark', 'pre-applied via init.sql');
