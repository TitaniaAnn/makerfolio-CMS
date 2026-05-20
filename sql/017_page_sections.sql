-- 017_page_sections.sql
-- Admin-toggleable section visibility + ordering for public pages.
--
-- One row per (page, section_key). is_visible gates whether the section
-- renders; sort_order controls display order on pages that use a dispatch
-- loop (currently just `home`). For inner pages (about, portfolio, templates)
-- only is_visible is consulted — sort_order is ignored since the sections
-- are inline-rendered.
--
-- The CATALOG of valid (page, section_key) pairs lives in
-- includes/PageSections.php — rows here only have meaning if a matching
-- entry exists there. Unknown rows are silently ignored by the runtime.

CREATE TABLE IF NOT EXISTS page_sections (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    page          VARCHAR(64)  NOT NULL,
    section_key   VARCHAR(64)  NOT NULL,
    is_visible    TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order    INT          NOT NULL DEFAULT 0,
    UNIQUE KEY uniq_page_section (page, section_key),
    KEY idx_page_visible (page, is_visible, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed the known sections at default sort_orders (gaps of 10 so admins can
-- insert between without renumbering). INSERT IGNORE so re-application is
-- safe.
INSERT IGNORE INTO page_sections (page, section_key, is_visible, sort_order) VALUES
-- Homepage (7 sections, dispatched via PageSections::enabled('home'))
('home',      'hero',           1, 10),
('home',      'featured_work',  1, 20),
('home',      'announcements',  1, 30),
('home',      'events_preview', 1, 40),
('home',      'about_strip',    1, 50),
('home',      'social',         1, 60),
('home',      'shop_teaser',    1, 70),
-- About page (inline visibility guards; sort_order ignored)
('about',     'contact',        1, 10),
('about',     'social_links',   1, 20),
-- Portfolio page
('portfolio', 'filters',        1, 10),
-- Templates page
('templates', 'filters',        1, 10);
