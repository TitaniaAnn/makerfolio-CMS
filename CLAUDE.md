# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
# Install runtime + dev dependencies
composer install

# Run local dev server (visit http://localhost:8000 — set SITE_URL in .env to match)
php -S localhost:8000 -t public

# PHP syntax check (Bash)
find . -path ./vendor -prune -o -name "*.php" -print | xargs -n1 php -l

# PHP syntax check (PowerShell, Windows dev environment)
Get-ChildItem -Recurse -Filter *.php -Exclude vendor | ForEach-Object { php -l $_.FullName }

# Run the full test suite (84 tests; 2 skip cleanly when GD isn't loaded)
composer test
# or directly:
vendor/bin/phpunit

# Run a single test class or method
vendor/bin/phpunit --filter MigrationRunnerTest
vendor/bin/phpunit --filter test_strips_block_comments_including_multiline

# Initialize database (canonical schema for fresh installs only)
mysql -u root -p < sql/init.sql
```

### Docker (alternative to host PHP + MySQL)

```bash
# First-time setup: copy the env template and edit ALLOWED_GITHUB_USERS at minimum
cp .env.docker.example .env.docker

# Bring up web (Apache + PHP 8.2) on :8088 and MySQL 8 on :3307
docker compose up --build

# Run the full PHPUnit suite in a clean container (no MySQL needed)
docker compose run --rm test

# Run a single test class or method through the same runner
docker compose run --rm test vendor/bin/phpunit --filter MigrationRunnerTest

# One-off shell in the web container
docker compose exec web bash
```

The web service bind-mounts the repo for hot-reload, but keeps `vendor/` and `public/uploads/` in named volumes so the bind mount doesn't shadow them. The `db` service auto-runs `sql/init.sql` on first boot (when the `dbdata` volume is empty); for an existing volume, use the admin migrations UI as on prod. Apache config + PHP overrides live in [docker/](docker/).

### Fresh-install workflow

The repo ships an installer that provisions a new deployment end-to-end (writes `.env`, runs `sql/init.sql`, applies migrations, seeds branding, writes a `.installed` marker that self-disables the installer).

```bash
# Web wizard — visit in a browser on a freshly-uploaded copy of the repo:
#   {SITE_URL}/install/  (e.g. http://localhost:8088/install/)

# CLI installer — interactive prompts:
php bin/install.php

# CLI installer — non-interactive (Docker entrypoint, CI, scripted provisioning).
# Local admin is REQUIRED; OAuth providers are optional.
php bin/install.php --non-interactive \
    --db-host=db --db-name=pottery_portfolio --db-user=pottery --db-pass=potterypw \
    --site-url=http://localhost:8088 \
    --site-name="My Pottery" \
    --admin-username=me --admin-password='supersecretpw12'

# Same install + GitHub OAuth + Google OAuth (each provider needs all three of
# its flags supplied together, or omit all three to skip):
php bin/install.php --non-interactive ... \
    --github-client-id=ID --github-client-secret=SEC --allowed-users="me,friend" \
    --google-client-id=ID --google-client-secret=SEC --allowed-google-emails="me@x.com"

# Re-run after a botched install (requires either deleting .installed or --force):
rm .installed && php bin/install.php
```

Both entrypoints share [includes/Installer.php](includes/Installer.php) — the wizard and CLI are thin I/O layers around the same provisioning core. The DB + user must already exist; the installer doesn't create them (shared hosting users: create both via cPanel first). On success, the web wizard auto-deletes its own folder (`public/install/`) so the URL 404s; if auto-delete fails (e.g. Windows host, locked-down perms) the `.installed` marker at the project root still blocks re-runs.

### Starter mode / content reset

For handing the CMS off to another potter as a clean slate. Wipes content (DB rows + upload files) and resets branding/text/design overrides; never touches schema, admin accounts, auth settings, the migrations ledger, or `shop_currency`.

```bash
# CLI — interactive (asks for typed confirmation)
php bin/reset-content.php

# CLI — scripted (Docker / CI)
php bin/reset-content.php --non-interactive --force

# Selective: keep some partitions
php bin/reset-content.php --non-interactive --force --keep-uploads --keep-design

# Show what would happen, change nothing
php bin/reset-content.php --dry-run
```

Admin UI: `/admin/settings/reset-content.php` — per-partition checkboxes + a typed-`RESET CONTENT`-to-confirm guard. Both paths route through [includes/ContentReset.php](includes/ContentReset.php).

### Sample content seeder

One-click demo-data drop for fresh adopters who want to see the site populated before they've added their own work. Pure additive — never deletes existing rows — and idempotent (a settings marker prevents double-seeding).

```bash
# CLI — interactive (asks to confirm)
php bin/seed-sample-content.php

# CLI — scripted (Docker entrypoints, CI)
php bin/seed-sample-content.php --force
```

Admin UI: `/admin/settings/sample-content.php`. Drops in 5 pottery / 3 events / 2 announcements / 4 products / 3 social links with generated earthy-toned SVG placeholder images written into `public/uploads/{pottery,products,announcements}/`. To re-seed, wipe the demo rows first via `bin/reset-content.php` (or the admin Reset Content page) — that clears the `sample_content_seeded` marker.

Deployment is automatic: GitHub Actions FTP-syncs to Bluehost on push to `main`. The deploy excludes `vendor/**`, `.env`, and `.git*`. Production composer deps must already be on the server — only application files sync.

## Architecture

PHP 8+ MySQL server-rendered app, no build step. Vanilla JS/CSS on the front end. Stripe is **opt-in** for payments. Admin auth supports any combination of local username/password + GitHub OAuth + Google OAuth.

**Entry points** are PHP files under [public/](public/) (public site) and [public/admin/](public/admin/) (admin). Public pages share layout via `include __DIR__ . '/../templates/nav.php'` and `footer.php` from the project-root [templates/](templates/) directory — note this is **not** `public/templates/`, which is a separate feature that serves `pottery_templates` file downloads via `/templates/download.php`. The homepage is composed from per-section partials under [public/sections/home/](public/sections/home/) (`hero.php`, `featured-work.php`, `announcements.php`, `events-preview.php`, `about-strip.php`, `social.php`, `shop-teaser.php`) — `public/index.php` is a thin data-fetch + dispatch loop that includes each visible partial in admin-defined order (see `PageSections` below). Every page begins by requiring [includes/bootstrap.php](includes/bootstrap.php), which:
- Loads `vendor/autoload.php` then `.env` *before* `config/config.php` (config reads `$_ENV` at constant-definition time, so order matters)
- Auto-requires the core classes: `Database`, `Auth`, `AuthProviders`, `ImageUpload`, `MultiFileUpload`, `Stripe`, `Mailer`, `Theme`, `EventTypes`, `PageText`, `PageSections`, `SampleContent`, `ListReorder`, `ListQuery`
- Starts the session via `Auth::start()` (httponly + samesite=Lax cookie; Secure flag honors `X-Forwarded-Proto` for proxy-terminated TLS)
- Defines global helpers: `e()` (HTML escape), `redirect()`, `flash()`/`getFlash()`, `setting()` (reads from `settings` DB table with in-memory cache), `csrf_token()` / `csrf_field()` / `csrf_verify()`, `getSocialIcon()`

**Core class boundaries** in [includes/](includes/):
- `Database.php` — PDO singleton; use `query()`, `fetchOne()`, `fetchAll()`, `insert()`, `update()`, `delete()` — always parameterized. `transaction(callable $work)` runs `$work` inside a `BEGIN/COMMIT` and rolls back on throw.
- `Auth.php` — multi-provider admin auth: local username/password (`loginLocal`), GitHub OAuth (`handleGitHubCallback`), Google OAuth (`handleGoogleCallback`). Session is keyed by `admin_users.id`; `session_regenerate_id(true)` on login. Call `Auth::requireLogin()` near the top of every admin page. `Auth::isSecureRequest()` is the proxy-aware HTTPS check used for the session cookie. **Local-login rate limit**: 5 failed attempts per IP in a 10-minute sliding window → lockout (`login_attempts` table). Successful login clears the IP's rows; OAuth flows bypass (provider rate-limits). **Password reset flow** at `/admin/auth/forgot-password.php` → `/admin/auth/reset-password.php` (single-use SHA-256-hashed tokens in `password_resets`, 1-hour TTL; always returns the same generic response on the request page to avoid account enumeration). **TOTP 2FA** for local-login: `loginLocal` now returns a 3-state status (`LOGIN_OK` / `LOGIN_NEEDS_2FA` / `LOGIN_FAILED`); when an admin has `totp_enabled = 1`, the password handler stashes a `pending_2fa_admin_id` marker and redirects to `/admin/auth/2fa-challenge.php` rather than completing the session. The challenge handler verifies a 6-digit TOTP code OR a 10-character recovery code, then calls `Auth::completeLocalLogin()`. OAuth flows bypass 2FA (the provider handles it).
- `Totp.php` — pure-PHP RFC 6238 TOTP implementation. No composer deps. Methods: `generateSecret()`, `computeCode()`, `verifyCode()` (±1 timestep drift), `otpauthUri()`, `generateRecoveryCodes()` / `hashRecoveryCodes()` / `findRecoveryCode()`. Self-contained base32 encode/decode. Admins enroll at `/admin/account/2fa.php` (each admin manages their own; no UI for managing another admin's 2FA).
- `AuthProviders.php` — registry that resolves which login methods are enabled and supplies their config from the `settings` table (`auth_local_enabled`, `auth_github_*`, `auth_google_*`). A provider is "enabled" only when its master toggle is `1` AND all its credential rows are non-empty. The admin login page hides any provider that fails this check.
- `Installer.php` — provisioning core shared by [public/install/index.php](public/install/index.php) (web wizard) and [bin/install.php](bin/install.php) (CLI): prereq checks, DB connection test, schema init, migration apply, branding/admin/auth seeding, atomic `.env` writes, `bootstrapAuthFromEnv()` (one-time .env→DB migration of OAuth creds), `removeInstallerDir()` (post-success self-delete of the install folder).
- `ImageUpload.php` — single-image upload: validates MIME type (JPEG/PNG/WebP only — GIF deliberately excluded; `createThumbnail` has no GIF branch) + size (10MB max), auto-resizes originals down to `MAX_ORIGINAL_DIMENSION` (1600px on the longer edge) via `resizeOriginalIfLarger()`, then generates 600×600 thumbnails with GD.
- `MultiFileUpload.php` — reshapes PHP's parallel `$_FILES['name'][i]` arrays into per-index records; use whenever a form posts multiple files under one input name.
- `ImageDeleteHandler.php` — shared backend for the pottery/shop "delete one image from a multi-image gallery" JSON endpoints. Deletes the file + row, promotes the next image to primary, syncs the parent row's `image_path` (and optional `image_thumb`).
- `TemplateFileUploader.php` — validates + saves uploads for `pottery_templates` (PDF/SVG/PNG/JPG/WEBP/ZIP, 50MB max).
- `Stripe.php` (`StripeHelper`) — checkout sessions and webhook signature verification. Only loaded when `STRIPE_ENABLED` is true.
- `Mailer.php` — wraps `mail()` for order/shipping notifications; `sanitizeHeader` strips CRLF and control chars from anything reaching mail headers. Subject + body text is sourced from `EmailTemplates` (admin-editable); this class just assembles the variable bag and dispatches.
- `EmailTemplates.php` — admin-editable subject + body for outbound mail (3 templates: `owner_new_order`, `customer_receipt`, `customer_shipped`). Same override pattern as `PageText` — settings rows keyed `email.{template_key}.{subject|body}`. `render($template, $field, $vars)` does `{variable}` substitution; unknown variables are left in the output verbatim so missing data is visible during admin preview.
- `MigrationRunner.php` — discovers `sql/NNN_*.sql`, tracks them in the `schema_migrations` ledger, splits + runs SQL files. Drives the admin migrations UI.
- `AnnouncementSocialMedia.php` — Instagram Graph API posting (TikTok intentionally disabled — see file header for the why); logs results to `announcement_social_posts`.
- `ContentReset.php` — starter mode: wipe author-specific content (pottery/products/events/announcements/social/orders/pottery_templates + upload subdirs + branding/text/design/email-template settings) while preserving schema, `admin_users`, `auth_*` settings, `schema_migrations`, and `shop_currency`. Used to hand the CMS off to another potter as a clean slate. Driven by [bin/reset-content.php](bin/reset-content.php) (CLI) and [public/admin/settings/reset-content.php](public/admin/settings/reset-content.php) (admin UI behind a typed-confirmation guard). The `content` partition also clears `CONTENT_SETTING_KEYS` (currently just `sample_content_seeded`) so post-reset state is internally consistent.
- `SampleContent.php` — one-click demo-data seeder for fresh adopters. `seed($uploadPath)` inserts 5 pottery / 3 events / 2 announcements / 4 products / 3 social links (all consts on the class) and writes earthy-toned SVG placeholders into `uploads/{pottery,products,announcements}/`. Idempotent via a `sample_content_seeded` settings marker — re-running short-circuits unless the marker is cleared by `ContentReset` (content partition). Strictly additive: never deletes existing rows. Driven by [bin/seed-sample-content.php](bin/seed-sample-content.php) (CLI) and [public/admin/settings/sample-content.php](public/admin/settings/sample-content.php) (admin UI).
- `ListReorder.php` — shared backend for the admin drag-to-reorder lists (pottery, products, events, page_sections). `update($kind, $ids)` rewrites `sort_order` for the listed IDs in one transaction at gaps of 10; `updatePageSections($page, $sectionKeys)` does the same for `page_sections` rows scoped to one page. Both validate against the `ALLOWED` const + `PageSections::CATALOG` allowlist before touching SQL. Driven by [public/admin/reorder.php](public/admin/reorder.php) (single shared JSON endpoint) and the vanilla-JS [public/admin/js/reorder.js](public/admin/js/reorder.js) wired to a vendored [SortableJS 1.15.6](public/admin/js/sortable.min.js).
- `ListQuery.php` — small builder for the admin list pages that need search + pagination (pottery / shop / events). `fromRequest($_GET)` returns `[q, page, perPage, offset]` with clamping; `buildSearchClause($q, $allowedColumns)` returns a parameterized `WHERE col LIKE ? OR col2 LIKE ?` fragment (LIKE wildcards in user input are escaped); `pagination($total, $page, $perPage)` returns the nav metadata. Column allowlist is enforced — `buildSearchClause` throws on anything outside `[A-Za-z0-9_]`. The shared [public/admin/partials/list-toolbar.php](public/admin/partials/list-toolbar.php) renders the search box + page nav. **Drag-to-reorder is hidden when search is active or when results paginate** — `$canReorder = ($q === '' && $totalPages === 1)` — because dragging a partial result would mis-renumber `sort_order` based on a window that doesn't include all rows.
- `Backup.php` — pure-PHP site snapshot zipped for download. Streams the SQL dump (one row at a time) to a temp file, packs `uploads/` + an optional `.env` + `manifest.json` via `ZipArchive`, then streams the zip to the browser with a `register_shutdown_function` cleanup. No mysqldump dependency (works on locked-down shared hosts). Only admin can download. Restore is intentionally out of scope (unzip + `mysql < database.sql` + rsync uploads manually).
- `ActivityLog.php` — append-only audit trail (`admin_activity` table). `ActivityLog::log('group.verb', $targetType, $targetId, $details)` resolves the current admin + IP from session and inserts a row. Fails silently on DB errors so logging never breaks the caller. `ACTIONS` const lists the known action names (auth, users, settings, content, backup); the admin filter UI iterates this. Viewed at `/admin/activity/`.
- `PageMeta.php` — emits the SEO + social-share meta block (`description`, OpenGraph, Twitter card) for public pages. Each public page calls `PageMeta::renderHead([...])` inside `<head>`; defaults derive from `site_name` + `tagline` + `hero_image`. `og:image` and `twitter:image` are absolute URLs (PageMeta normalizes relative paths against `SITE_URL`). Transactional pages (shop/success.php, shop/cancel.php) use `noindex,nofollow` instead.
- `Theme.php` — admin-configurable theme system. 4 named presets (`terra-gold`, `cool-sage`, `monochrome`, `coastal-blue`) plus optional per-role color overrides, font choice (display + body), and radius/shadow scales. `styleBlock()` returns a `<style>:root{…}</style>` block emitted by [templates/nav.php](templates/nav.php); `previewFromRequest()` decodes `?_theme_preview=<base64-json>` for the admin live-preview iframe (admin-only — anonymous visitors silently fall back).
- `EventTypes.php` — single source of truth for the 4 `event_type` ENUM values. `label($type)` reads admin-editable labels from the `event_type_labels` setting (JSON) with code fallback; `cssClass($type)` stays in code (bound to stylesheet rules).
- `PageText.php` — admin-editable display strings for public pages. `DEFAULTS` const holds ~52 strings in 10 groups (nav, footer, home, portfolio, shop, about, events, templates, announcement, order). `PageText::get('group', 'key')` returns the override from the `settings` table (key prefix `text.{group}.{key}`) or the default. Throws on unknown keys. `HIGH_IMPACT` const + `isHighImpact($group, $key)` flag the ~13 strings adopters most want to customize first (page CTAs, post-purchase copy); the admin editor stars them and links to them from a "Top priorities" panel.
- `PageSections.php` — admin-toggleable visibility + ordering for public-page sections. `CATALOG` lists the 11 known (page, section_key) pairs with human labels. `enabled('home')` returns visible section keys in sort order (used by the homepage dispatch loop); `isVisible('about', 'social_links')` is the inline guard used on inner pages.

**CSRF**: every admin POST handler and GET-style delete endpoint must call `csrf_verify()`; forms must include `<?= csrf_field() ?>` (and links must include `?csrf=<?= csrf_token() ?>` for GET deletes). On verification failure, `csrf_verify()` redirects back to the request's `HTTP_REFERER` **only if it's same-origin with `SITE_URL`** (otherwise falls back to `/admin/dashboard.php`) — closes an open-redirect against a logged-in admin.

**Hardening headers** — `includes/bootstrap.php` emits `Referrer-Policy: strict-origin-when-cross-origin` (kills CSRF-token-in-referer-log leak from `?csrf=` GET-style admin links), `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN` (the latter allows the admin theme-preview iframe but denies third-party embedding), and a moderate `Content-Security-Policy`. The CSP allows `'unsafe-inline'` for script-src + style-src because the codebase uses inline event handlers, inline `<script>` blocks, inline `<style>` blocks, and the admin-trusted `social_posts.embed_code` field accepts Instagram/TikTok blockquote markup. Even with `'unsafe-inline'`, the CSP still blocks remote-script injection, object/embed injection, form hijacking to attacker domains, mixed-content downgrades, `<base>` injection, and third-party framing. Adding a new CDN dependency requires extending the relevant -src directive in `bootstrap.php`.

**Admin no-cache headers** — `Auth::requireLogin()` emits `Cache-Control: private, no-store, no-cache, must-revalidate` + `Pragma: no-cache` for every authenticated admin page. Prevents browser back-button cache from showing admin pages to a subsequent user on a shared device, and defends against a misconfigured CDN caching `/admin/` responses.

**Uploads directory PHP-execution lockdown** — [public/uploads/.htaccess](public/uploads/.htaccess) denies Apache execution of `.php`/`.phtml`/`.phar`/`.pht`/`.php[3-8]` plus disables the PHP handler entirely in that tree. Defense-in-depth against an upload-validation bypass landing a script in `/uploads/`. In Docker, the `uploads` named volume shadows the bind-mounted repo copy, so the file is also bundled at `/usr/local/share/pottery-uploads-htaccess` in the image; the entrypoint seeds it into the volume if missing. `.dockerignore` whitelists `!public/uploads/.htaccess` so the build context picks it up despite `public/uploads` being otherwise excluded.

**Trusted proxies for rate-limit IP**: `Auth::clientIp()` honors `X-Forwarded-For` ONLY when `REMOTE_ADDR` is loopback or matches an IP in the `TRUSTED_PROXIES` env var. Untrusted XFF is ignored — otherwise an attacker on a non-stripping host could spoof per-request to bypass the `login_attempts` rate limit. Covered by [tests/AuthClientIpTest.php](tests/AuthClientIpTest.php).

**Configuration** is in [config/config.php](config/config.php), which reads `.env` via `vlucas/phpdotenv`.
- **Required** env vars: `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`.
- **Optional**: `SITE_URL` (defaults to `http://localhost:8088` for first-boot local dev; set to your real domain in production so OAuth callbacks / mailer links / Stripe redirect URIs resolve correctly). The install wizard writes this for you.
- **Auth provider credentials are no longer required in `.env`** — they live in the `settings` table now (`auth_github_*`, `auth_google_*`), managed via the install wizard or `/admin/settings/auth.php`. Existing installs that still keep them in `.env` get auto-migrated into settings by the Docker auto-migrate entrypoint (`Installer::bootstrapAuthFromEnv()`), one-time, on first boot.
- **Stripe is opt-in**: `STRIPE_PUBLISHABLE_KEY`, `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`. `STRIPE_ENABLED` constant is `true` only when all three are present *and* pass the placeholder check. When false: storefront hides Buy Now → Enquire, `/shop/checkout.php` redirects with a friendly message, `/shop/webhook.php` returns 503.
- **Optional social**: `INSTAGRAM_BUSINESS_ACCOUNT_ID`, `INSTAGRAM_ACCESS_TOKEN` (managed via `/admin/social/tokens.php`). TikTok vars exist for parity but are unused — see `AnnouncementSocialMedia::postToTikTok`.

**Database migrations** are SQL-first.
- [sql/init.sql](sql/init.sql) — canonical full schema for **fresh installs only**. Do not run against an existing DB. Includes a `schema_migrations` seed that pre-marks every shipped migration as applied, so the runner on a fresh DB has nothing to do.
- `sql/NNN_*.sql` (currently 001, 005–022; the 002–004 slots are intentionally skipped) — incremental migrations. The recommended path on a live DB is the admin UI at **`/admin/migrations/`**, which uses `MigrationRunner` to apply pending files, track applied ones in `schema_migrations`, and let you "Mark applied" migrations that were run manually before the runner existed. Manual `mysql < file.sql` still works but bypasses the ledger. **Docker auto-applies pending migrations on web container boot** via `docker/entrypoint.sh` → `docker/auto-migrate.php` (safe to re-run; only applies what's missing from the ledger).
- **Migrations are individually idempotent.** Every `ALTER TABLE ADD/DROP COLUMN`, `CHANGE COLUMN`, `ADD INDEX`, and data-migration `INSERT` is guarded by an `INFORMATION_SCHEMA` (or `NOT EXISTS`) check, so a file can be re-applied without error even if the ledger row was lost. The pattern looks like:

  ```sql
  SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'foo' AND COLUMN_NAME = 'bar');
  SET @sql = IF(@c = 0, 'ALTER TABLE foo ADD COLUMN bar VARCHAR(64) NULL', 'DO 0');
  PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
  ```

  When adding a new migration that touches existing tables, follow this pattern (or use `CREATE TABLE IF NOT EXISTS` / `INSERT IGNORE`). The splitter in `MigrationRunner::splitStatements()` splits on `;` followed by a newline — every statement above ends on its own line.
- `sql/.htaccess`, `includes/.htaccess`, `config/.htaccess`, and `public/admin/.htaccess` deny direct web access (the admin one also blocks `error_log`, `*.log`, `*.sql`, `*.md`, `*.env`, `*.ini`, `*.sh`).

**Stripe webhook idempotency contract** ([public/shop/webhook.php](public/shop/webhook.php)): every event is INSERTed into `stripe_webhook_events` first to claim it (PK serializes concurrent retries), then the handler runs inside `Database::transaction()`, then `processed_at` is stamped. A retry of an event whose handler crashed mid-flight (row exists but `processed_at IS NULL`) re-runs the handler; a retry of a fully-processed event short-circuits with 200. Mail dispatch happens *after* the transaction so `mail()` slowness can't push the webhook past Stripe's 10s deadline.

## Conventions

- Use `e($val)` for all public-facing HTML output; use raw HTML only when intentional.
- Use `flash()` / `getFlash()` for user feedback — don't set ad-hoc session keys.
- Admin pages must call `Auth::requireLogin()` near the top; admin write/delete endpoints must call `csrf_verify()`.
- Admin URL/script filenames are kebab-case (`delete-image.php`, `add-product.php`, `delete-file.php`) — match this when adding new endpoints.
- Follow existing upload path constants (`UPLOAD_PATH`, `MAX_IMAGE_SIZE`, thumbnail size constants) — don't hardcode paths.
- For multi-file uploads, use `MultiFileUpload::parse($_FILES['key'])` instead of writing a fresh parallel-array loop.
- For "delete one image from a gallery" admin endpoints, delegate to `ImageDeleteHandler::delete([...])` — it handles file removal, primary-image promotion, and parent-row sync.
- Prefer existing helpers (`setting()`, `redirect()`, `e()`) over duplicating logic.
- **Public-facing display text goes through `PageText::get('group', 'key')`** — do not hardcode user-facing strings in public PHP files. Add new keys to `PageText::DEFAULTS` in [includes/PageText.php](includes/PageText.php); admin overrides are managed via `/admin/settings/page-text.php`.
- **Homepage sections live as individual partials** under [public/sections/home/](public/sections/home/). To add a new homepage section: (1) create the partial, (2) add an entry to `PageSections::CATALOG['home']` and `DEFAULT_SORT_ORDER['home']`, (3) add a seed row to `sql/init.sql` + a new migration. The dispatcher converts snake_case section keys to kebab-case filenames.
- **Inner-page section visibility** uses `PageSections::isVisible('about', 'social_links')` inline guards combined with the existing data-presence checks (`!empty($foo)`).
- **Event-type labels** must route through `EventTypes::label($type)` rather than local lookup arrays — adding a new type also requires a schema change to the `events.event_type` ENUM.
- Never concatenate untrusted values into SQL; always use parameterized queries.
- Code style is procedural page controllers + static helper classes.
- Don't edit `vendor/` files.

## Testing

- PHPUnit 10 lives in `require-dev`; the suite is in [tests/](tests/). [tests/bootstrap.php](tests/bootstrap.php) defines just the constants the tested classes need and `require_once`s only those classes — no `.env`, no DB, no Stripe SDK.
- Write tests for pure helpers (parsers, validators, header-sanitization) and for any logic you can extract from a stateful class. DB-touching paths in `MigrationRunner`, `Database`, and `Auth::handleGitHubCallback` are not unit-tested.
- Tests that need GD (real image bytes for `ImageUpload` MIME validation) call a `requireGd()` helper that `markTestSkipped` cleanly when the extension isn't loaded — keep that pattern for any new GD-dependent test.
- CI runs the suite automatically: [.github/workflows/test.yml](.github/workflows/test.yml) lints every `*.php` file (php -l) on push/PR, then runs the PHPUnit suite in the Docker test image. Both jobs must pass for the workflow to go green.

## Admin pages

Settings is the central hub at [public/admin/settings/index.php](public/admin/settings/index.php). The dedicated sub-pages are:
- `/admin/settings/index.php` — branding (site name, tagline, hero copy, profile photo, about text, contact email, shop intro)
- `/admin/settings/theme.php` — preset + overrides + fonts + radii/shadows, with a live-preview iframe
- `/admin/settings/auth.php` — enable/disable + configure GitHub & Google OAuth providers (and the local-login toggle)
- `/admin/settings/page-text.php` — admin overrides for every `PageText::DEFAULTS` string, grouped by page
- `/admin/settings/page-sections.php` — toggle/reorder homepage sections + toggle inner-page bits
- `/admin/settings/email-templates.php` — edit Mailer subject + body per template, with available-variables panel + live preview pane
- `/admin/settings/health.php` — read-only system-health diagnostics (DB ping, migration ledger, Stripe state, Instagram token expiry, mail() availability, HTTPS, uploads writable, `.env` placeholders, sample-content marker, `.installed` + leftover `public/install/` checks). Complements `schema-health.php` which focuses on table/column existence with fix-SQL.
- `/admin/settings/sample-content.php` — one-click demo-data seeder (5 pottery, 3 events, 2 announcements, 4 products, 3 social links + SVG placeholders). Idempotent via the `sample_content_seeded` settings marker. See [includes/SampleContent.php](includes/SampleContent.php).
- `/admin/settings/reset-content.php` — starter wipe (typed-confirmation guard; see [includes/ContentReset.php](includes/ContentReset.php))
- `/admin/settings/schema-health.php` — DB schema check
- `/admin/migrations/` — apply pending SQL migrations + mark-applied for manually-run ones

Plus per-resource admin sections under `/admin/{pottery,shop,events,announcements,social,templates,orders}/`.

**Drag-to-reorder** is wired on the admin lists for pottery, products, events, and the homepage's `page_sections`. Each row exposes a `⋮⋮` handle (`.reorder-handle`); SortableJS captures the drop and `reorder.js` POSTs the new order to `/admin/reorder.php` with the page's CSRF token (from the `<meta name="csrf-token">` tag). The endpoint validates `kind` against `ListReorder::ALLOWED` (or `PageSections::CATALOG` for page_sections), then rewrites `sort_order` in a single transaction at gaps of 10. To add reorder to a new resource: add an entry to `ListReorder::ALLOWED`, add `data-reorder-kind="…"` to the tbody/list, give each row `data-id="…"`, and include the two scripts at page bottom. The admin list pages intentionally ORDER BY pure `sort_order` (not `featured DESC, sort_order`) so the drag result is what the user sees.

**Admin list search + pagination** uses `ListQuery` + the shared [partials/list-toolbar.php](public/admin/partials/list-toolbar.php) on pottery, shop, and events index pages. Per-page is 25 (capped at 100), search columns are per-page allowlisted (e.g. pottery searches `title`/`description`/`technique`), and search state is bookmarkable via GET. Drag handles are conditionally rendered — disabled whenever the list is paginated or filtered (would mis-renumber a partial result).

**Friendly config error page** — `config/config.php` defines `_config_error_page()` (self-contained, no class deps since it runs before bootstrap) that replaces the old plain-text `exit('Configuration error...')` with a styled HTML page listing the missing env keys and linking to `/install/` when the wizard is still present. Triggers on missing required env vars (DB_*) or Stripe keys that look like placeholders.

**Custom 404 / 500 pages** — [public/.htaccess](public/.htaccess) wires Apache `ErrorDocument 404 /404.php` and `ErrorDocument 500 /500.php`. [public/404.php](public/404.php) uses the full theme + nav (looks like the rest of the site, links back home + to the portfolio). [public/500.php](public/500.php) is deliberately minimal — no bootstrap, no DB, no class includes — because if you've landed there the rest of the stack is already broken and we don't want a cascading "error page can't render an error" failure.

**Clean URLs** ([public/.htaccess](public/.htaccess)) — mod_rewrite maps `/portfolio` → `/portfolio.php`, `/admin/pottery/add` → `/admin/pottery/add.php`, etc. **Both URLs work** (no canonicalization redirect): clean URLs are used in internal links + nav, but the legacy `.php` URLs remain valid so external integrations don't break. The Stripe webhook (`/shop/webhook.php`) and OAuth callbacks (`/admin/auth/callback.php`, `/admin/auth/google-callback.php`) are intentionally referenced with `.php` everywhere because those URLs are registered with external providers — changing them would require every adopter to update their Stripe/GitHub/Google dashboard config. `DirectorySlash Off` + a second rewrite rule handles the dir-vs-sibling-php ambiguity for `/shop` and `/templates` (the `.php` storefront wins; the `/shop/checkout` subpath still resolves via the directory).

**Atomicity in delete handlers** — pottery / shop product / announcement delete endpoints stash all file paths first (parent + cascading children via FK), then `DELETE` the DB row, then `unlink()` the files. If the unlink fails after DB succeeds we leak a file; if DB fails we leak nothing. Reverse order (the old code) could leave a row pointing at a deleted file, which is harder to recover from.

**Dashboard onboarding card** at `/admin/dashboard.php` — dismissible per-install checklist (5 items: site name + tagline, contact email, About copy, first pottery upload, theme customized). Each item auto-ticks based on detectable state. "Dismiss" POSTs to `/admin/onboarding-dismiss.php` which sets `onboarding_dismissed = 1` in `settings`. Hidden automatically once all 5 are done OR the marker is set. Re-enable by deleting the settings row directly.

**Backup** lives at `/admin/backup/` — single button + two checkboxes (include uploads, include `.env`) that streams a `.zip` snapshot to the browser. Contains `database.sql` (pure-PHP dump of every table), `uploads/`, and a `manifest.json` describing what's inside. The `.env` checkbox is opt-in because the file holds DB credentials; the SQL dump always contains `admin_users.password_hash` + `auth_*` rows, so the zip should be treated as a master password regardless.

**Activity log** lives at `/admin/activity/` — paginated table of auth + settings + user-management + backup + content-reset events with admin / action filters. Rows are append-only (no edit / delete UI); wipe the `admin_activity` table directly if you need to reset.

**Account / 2FA** lives at `/admin/account/2fa.php` — each admin manages their own TOTP secret + recovery codes. Three states (not-enrolled → enrolling → enabled) with a typed-password confirmation guard on disable + regenerate-recovery-codes. Recovery codes are shown exactly once and stored as bcrypt hashes; used codes are blanked out in-place so the array length stays stable for index-based slot reuse on regeneration.

**Admin user management** lives at `/admin/users/`:
- `index.php` lists every `admin_users` row with username, name/email, login-method badges (local password / GitHub / Google), last login, and created date. The currently signed-in row is marked `YOU`.
- `add.php` creates a new local admin (username + password ×2, optional name/email). Reuses `Installer::isValidUsername` / `isValidPassword` / `isValidEmail`.
- `edit.php` renames/email/name and optionally changes the password. Also exposes per-row "Unlink GitHub" / "Unlink Google" buttons; unlinking refuses when it would leave the row with zero login methods.
- `delete.php` POST handler refuses to delete the currently-signed-in row (self-lockout guard) or the last remaining admin (last-admin guard).
- To onboard someone via OAuth without using this UI, just add them to `auth_github_allowed_users` / `auth_google_allowed_emails` at `/admin/settings/auth.php` — the row is auto-created on their first successful OAuth login.

## Pitfalls

- Missing required `.env` values cause early failures with a clear error message — populate `.env` before running locally. Required vars are now just `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` (auth-provider creds moved to the settings table — see Configuration above).
- **Stale .env auth creds on upgraded installs**: existing deployments may still have `GITHUB_CLIENT_ID` / `GITHUB_CLIENT_SECRET` / `ALLOWED_GITHUB_USERS` in `.env` from before the auth refactor. The DB settings rows are authoritative now; the `.env` values are inert (kept for one release as a one-time bootstrap source via `Installer::bootstrapAuthFromEnv()`). Deleting the DB rows expecting `.env` to "take over" will lock you out — manage providers through `/admin/settings/auth.php` instead.
- `config.php` reads `$_ENV` at the moment its constants are defined, so `.env` must be loaded *before* `config/config.php` is required. `bootstrap.php` already does this; standalone scripts (one-off CLI utilities) must load `vendor/autoload.php` and call `Dotenv::createImmutable(ROOT_PATH)->safeLoad()` before requiring `config/config.php`.
- Deployment runs only on push to `main`; the active development branch is `dev` (changes don't ship until merged to `main`).
- Don't run `sql/init.sql` against a database that already has any pottery-app tables — it uses `CREATE TABLE IF NOT EXISTS` and will silently skip existing tables that may have a stale schema. Use the migrations UI on existing DBs.
- PHP error logs (`error_log` files) are denied by `public/admin/.htaccess` and gitignored, but watch for them appearing in directories without an `.htaccess`. Set `error_log` in `php.ini` to a path outside the document root in production.
- **`public/install/` is in the deploy path** — if CI is re-enabled, a `git push` would re-deploy a fresh installer folder onto a live site (the `.installed` marker still gates it, but a deploy-exclude is defense-in-depth).
