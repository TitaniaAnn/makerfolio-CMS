# 🏺 Pottery Portfolio Website

A full PHP + MySQL CMS for potters: admin-editable portfolio gallery, events, announcements, shop with optional Stripe checkout + print-on-demand links, and downloadable studio templates (PDF patterns, glaze recipes, etc.). Multi-provider admin login (local username/password, GitHub OAuth, Google OAuth — all opt-in), live-preview theme system, every public string editable from admin, downloadable site backups, and a one-click content reset for handing the CMS off to another potter.

> **License (TL;DR)** — free to use, including on commercial sites you sell services around, **as long as the "Site built by Cynthia Brown" byline in the footer stays visible and linked to [cynthia-brown.com](https://cynthia-brown.com)**. To remove the byline, [contact for a paid license](mailto:hi@cynthia-brown.com). Full terms in [LICENSE](LICENSE).

---

## ⚡ Quickstart

Have it running locally in three commands (Docker + the install wizard does the rest):

```bash
cp .env.docker.example .env.docker      # edit if you want; defaults work for local dev
docker compose up --build               # web on :8088, MySQL 8 on :3307
open http://localhost:8088/install/     # 7-step wizard — sets DB, admin user, branding
```

The wizard creates your local admin login, writes `.env`, seeds the schema, and auto-deletes itself on success. After it finishes, sign in at `/admin/login.php` — the dashboard's onboarding card walks you through customizing site name, contact email, About copy, your first pottery piece, and the theme.

**Want to see what a populated site looks like first?** Hit Settings → Sample Content and click "Load sample content" — drops in 5 pottery pieces, 3 events, 2 announcements, 4 shop products, and 3 social links with neutral SVG placeholder images. Wipe them later via Settings → Reset Content when you're ready to ship.

**No Docker?** Run `composer install`, create an empty MySQL DB, then `php -S localhost:8000 -t public` and visit `/install/`.

**Headless / scripted install?** See [bin/install.php](bin/install.php) — non-interactive flags for CI, Docker entrypoints, or shared-hosting one-shots.

**Handing the CMS to another potter?** `/admin/settings/reset-content.php` wipes content + branding without touching the schema or admin accounts.

**Want your own favicon?** Replace `public/favicon.svg`, `public/favicon.ico`, and the `favicon-{16,32,48,512}.png` files. The shipped defaults are a neutral pot-on-monitor mark — fine to leave in place until you're ready to brand.

**Clean URLs out of the box** — public/.htaccess rewrites map `/portfolio`, `/admin/dashboard`, `/admin/pottery/add` etc. to their underlying `.php` files. Legacy `.php` URLs keep working, so existing bookmarks and OAuth/Stripe integrations don't break. Requires Apache mod_rewrite (enabled by default on most shared hosts + the bundled Docker image).

---

## Project Structure

```
pottery/
├── bin/
│   ├── install.php             # CLI installer (mirror of /install/ wizard)
│   └── reset-content.php       # CLI starter wipe (hand off to another potter)
├── config/
│   └── config.php              # Reads .env; defines constants (DB, Stripe, social)
├── includes/
│   ├── bootstrap.php           # Loaded by every entry point; auto-requires every helper below
│   ├── Database.php            # PDO singleton + transaction helper
│   ├── Auth.php                # Multi-provider: local / GitHub / Google
│   ├── AuthProviders.php       # Settings-table-backed provider registry
│   ├── Installer.php           # Shared provisioning core (wizard + CLI)
│   ├── ImageUpload.php         # Single-image upload + GD thumbnails
│   ├── MultiFileUpload.php     # Reshapes parallel $_FILES arrays
│   ├── ImageDeleteHandler.php  # Shared "delete one gallery image" endpoint backend
│   ├── TemplateFileUploader.php# Uploads for the pottery_templates feature
│   ├── Stripe.php              # Checkout sessions + webhook signature verification
│   ├── Mailer.php              # mail() wrapper; subject/body from EmailTemplates
│   ├── EmailTemplates.php      # Admin-editable email subject + body with {var} substitution
│   ├── MigrationRunner.php     # Drives sql/NNN_*.sql + admin migrations UI
│   ├── AnnouncementSocialMedia.php # Instagram Graph API posting
│   ├── Theme.php               # Preset + override theme system; emits :root CSS variables
│   ├── EventTypes.php          # Single source of truth for the 4 event_type ENUM values
│   ├── PageText.php            # ~52 admin-editable display strings (text.* settings)
│   ├── PageSections.php        # Toggle + reorder public page sections
│   ├── ContentReset.php        # Template-starter wipe (DB + uploads + setting overrides)
│   └── Backup.php              # Pure-PHP SQL dump + zip download
├── templates/
│   ├── nav.php                 # Shared public nav (includes theme <style> + Google Fonts)
│   └── footer.php              # Shared public footer
├── public/                     # Web document root
│   ├── index.php               # Homepage — thin dispatcher into sections/home/*.php
│   ├── portfolio.php, shop.php, about.php, events.php,
│   │   announcement.php, templates.php, privacy.php
│   ├── sections/home/          # Per-section partials dispatched by PageSections::enabled('home')
│   │                           #   hero, featured-work, announcements, events-preview,
│   │                           #   about-strip, social, shop-teaser
│   ├── shop/                   # checkout.php, success.php, cancel.php, webhook.php
│   ├── install/index.php       # 7-step web installer; self-deletes after success
│   ├── templates/download.php  # Serves pottery_templates files (unrelated to layout)
│   ├── css/, js/
│   ├── uploads/                # Created automatically; pottery/, products/, hero/, profile/, templates/
│   └── admin/
│       ├── login.php, logout.php, dashboard.php
│       ├── auth/                # callback.php (GitHub), google-callback.php (Google)
│       ├── pottery/, shop/, events/, announcements/, templates/, social/, orders/, migrations/
│       ├── users/               # List / add / edit / delete admin_users
│       ├── backup/              # Single-button site snapshot download
│       ├── settings/            # index, theme, auth, page-text, page-sections,
│       │                        #   email-templates, reset-content, schema-health
│       ├── css/, js/
│       └── partials/            # Sidebar, topbar
├── sql/
│   ├── init.sql                # Canonical schema (fresh installs ONLY; pre-marks migrations as applied)
│   └── 001_*.sql … 022_*.sql   # Incremental migrations (002–004 intentionally skipped)
├── docker/                     # Apache vhost, PHP overrides, auto-migrate entrypoint
├── compose.yml, Dockerfile     # Dev stack (web + db + test runner)
├── tests/                      # PHPUnit 10 suite — 171 tests
├── composer.json, phpunit.xml.dist
└── CLAUDE.md                   # Architecture notes for AI assistants / new contributors
```

---

## Setup Instructions

The fastest path is the installer (web wizard or CLI) — it handles steps 1–3 below in one go. The manual steps remain for reference.

### Option A — Web installer (recommended)

Upload the repo to your server (or run `docker compose up` locally), then visit:

```
https://yourdomain.com/install/
```

The wizard walks through PHP/extension checks, database credentials, site URL, and GitHub OAuth setup, then writes `.env`, initializes the schema, applies migrations, and seeds branding. On success, the wizard **auto-deletes its own folder** (`public/install/`) so the URL 404s on next visit. If auto-delete fails (Windows hosts, locked-down permissions), the `.installed` marker file at the project root still blocks re-runs and the success page tells you to remove the folder manually.

The database and database user must already exist — the installer doesn't create them. On shared hosting (Bluehost-style), create both via cPanel → MySQL Databases before running the installer.

### Option B — CLI installer (for Docker / SSH / CI)

```bash
# Interactive prompts (local admin is required; OAuth providers are optional)
php bin/install.php

# Non-interactive — pass all values via flags
php bin/install.php --non-interactive \
    --db-host=localhost --db-name=pottery_portfolio \
    --db-user=DBUSER --db-pass=DBPASS \
    --site-url=https://yourdomain.com --site-name="My Pottery" \
    --admin-username=me --admin-password='supersecretpw12' \
    --github-client-id=XXX --github-client-secret=YYY --allowed-users=your-github-username \
    --google-client-id=XXX --google-client-secret=YYY --allowed-google-emails=you@example.com
```

`php bin/install.php --help` lists every flag. GitHub and Google OAuth are each independently optional — supply all three flags for a provider to enable it, or omit all three to skip and configure later from `/admin/settings/auth.php`.

### Resetting an install for handoff

To hand the CMS off to another potter as a clean slate (wipes pottery, products, events, announcements, social, orders, downloadable studio templates, branding, page-text + theme overrides — preserves the schema, your admin user, and login-provider settings):

```bash
php bin/reset-content.php                              # interactive
php bin/reset-content.php --non-interactive --force    # scripted
php bin/reset-content.php --dry-run                    # show what would happen
```

Admin UI: `/admin/settings/reset-content.php` (typed-`RESET CONTENT`-to-confirm guard). See `php bin/reset-content.php --help` for selective `--keep-*` flags.

### Option C — Manual setup

### 1. Database

**Fresh install:**
```bash
mysql -u root -p < sql/init.sql
```

**Existing install:** do **not** re-run `init.sql` (it uses `CREATE TABLE IF NOT EXISTS` and will silently skip stale tables). Apply pending `sql/NNN_*.sql` migrations through the admin UI at `/admin/migrations/`, which tracks them in the `schema_migrations` ledger. Each incremental migration is individually idempotent — `ADD COLUMN`, `ADD INDEX`, and data-migration `INSERT`s are guarded by `INFORMATION_SCHEMA` / `NOT EXISTS` checks — so re-applying a file is safe even if the ledger row was lost.

### 2. Config

Create `.env` at the project root with the **required** DB credentials:
```
DB_HOST=localhost
DB_NAME=pottery_portfolio
DB_USER=your_db_user
DB_PASS=your_db_password
SITE_URL=https://yourdomain.com
```

Optional:
```
STRIPE_PUBLISHABLE_KEY=pk_test_...     # Leave blank to disable Stripe checkout
STRIPE_SECRET_KEY=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
INSTAGRAM_BUSINESS_ACCOUNT_ID=         # Optional social
INSTAGRAM_ACCESS_TOKEN=
```

`config/config.php` reads these via `vlucas/phpdotenv` and fails fast only on missing DB credentials.

> **OAuth credentials no longer live in `.env`.** GitHub / Google client IDs and secrets are stored in the `settings` table now — set them via the install wizard or `/admin/settings/auth.php`. Upgraded installs that still have `GITHUB_CLIENT_ID` / `_SECRET` / `ALLOWED_GITHUB_USERS` in `.env` get auto-migrated into settings once on first boot.

### 3. Create the first admin user

Manual setup also needs at least one row in `admin_users`. Easiest path: run the CLI installer with `--non-interactive`, or `INSERT` a row by hand with a `password_hash` produced via `php -r "echo password_hash('your-password', PASSWORD_DEFAULT);"`. After first login, manage admins at `/admin/users/` and configure OAuth providers at `/admin/settings/auth.php`.

### 4. Web Server

**Apache** — Point document root to the project root, or set up a VirtualHost:
```apache
<VirtualHost *:80>
    DocumentRoot /var/www/pottery
    ServerName yourdomain.com
    <Directory /var/www/pottery>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

**Nginx:**
```nginx
server {
    listen 80;
    server_name yourdomain.com;
    root /var/www/pottery;
    index index.php;

    location / { try_files $uri $uri/ /index.php?$args; }
    location ~ \.php$ { fastcgi_pass unix:/run/php/php8.2-fpm.sock; include fastcgi_params; fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; }
    location ~* \.(jpg|jpeg|png|gif|webp|ico|css|js)$ { expires 30d; }
}
```

### 5. Uploads directory

```bash
mkdir -p public/uploads/pottery public/uploads/products
chmod -R 755 public/uploads
chown -R www-data:www-data public/uploads  # Linux
```

### 6. PHP Requirements
- PHP 8.0+
- Extensions: PDO, PDO_MySQL, GD (for thumbnails), cURL (for OAuth)

---

## Docker (alternative local setup)

Reproducible Apache + PHP 8.2 + MySQL 8 stack — no host installs needed beyond Docker.

```bash
cp .env.docker.example .env.docker   # edit ALLOWED_GITHUB_USERS at minimum
docker compose up --build            # web on http://localhost:8088, db on 3307
docker compose run --rm test         # run the PHPUnit suite in a clean container
```

Apache vhost and PHP overrides live in [`docker/`](docker/). The MySQL container seeds itself from `sql/init.sql` on first boot only; further migrations apply via `/admin/migrations/` once you're logged in. `vendor/` and `public/uploads/` live in named volumes so the bind-mounted source doesn't shadow them.

---

## Admin Access

Visit: `https://yourdomain.com/admin/login.php`

The login page surfaces whichever methods are enabled in the `settings` table (`auth_local_enabled`, `auth_github_enabled`, `auth_google_enabled`):

- **Local** — username + password set during install. Always enabled by default.
- **GitHub OAuth** — sign in with a GitHub account listed in the allowlist. Configure at `/admin/settings/auth.php`.
- **Google OAuth** — sign in with a Google account listed in the allowlist. Configure at `/admin/settings/auth.php`.

Any provider that isn't enabled + fully configured is hidden from the login form. A single admin row can carry any combination of identities (local password + GitHub link + Google link).

---

## Admin Features

**Content**
| Section | What you can do |
|---|---|
| **Portfolio** | Add/edit/delete pottery pieces with multi-image galleries, technique, dimensions, year. Mark as featured. |
| **Shop → Pots** | Individual pots for sale: price, availability, optional Stripe checkout. |
| **Shop → Merch** | Print-on-demand products with provider URL (Printful, Printify, Redbubble). |
| **Events** | Pottery shows, sales, storefronts, classes — with date ranges, location, description. |
| **Announcements** | Time-bounded news posts with optional links to events or pottery pieces. |
| **Templates** | Free downloadable patterns / guides — PDF, SVG, image, ZIP. |
| **Social Posts** / **Links** | Featured social posts on the homepage; profile links in footer/about. |
| **Orders** | Stripe order log + manual shipping notification trigger. |

**Settings**
| Section | What you can do |
|---|---|
| **Site Settings** | Branding (site name, tagline, hero, bio, about, contact email, profile photo, shop intro). |
| **Theme** | Pick a preset (Terra/Gold, Cool Sage, Monochrome, Coastal Blue) + override individual colors, fonts, radii, shadow depth. Live preview iframe. |
| **Login Providers** | Enable/disable + configure local, GitHub, and Google authentication. |
| **Page Text** | Override every public-facing string (~52 keys grouped by page). |
| **Page Sections** | Toggle visibility + reorder the homepage's 7 sections + a few inner-page bits. |
| **Email Templates** | Edit Mailer subject + body per template with `{variable}` substitution + live preview. |
| **Schema Health** / **Migrations** | DB structure check + apply pending SQL migrations. |
| **Reset Content** | Wipe author-specific content (starter mode — for handing the CMS to another potter) — typed-confirmation guard. |

**Administration**
| Section | What you can do |
|---|---|
| **Admin Users** | List / add / edit / delete `admin_users`. Per-row Unlink GitHub / Unlink Google. Self-delete + last-admin guards. |
| **2FA (Account → 2FA)** | Per-admin TOTP enrollment for local login. Authenticator-app deep link + manual secret entry. 10 single-use recovery codes shown once at enrollment; regenerate or disable any time with password confirmation. |
| **Activity Log** | Append-only audit trail: logins (success/failure/2FA challenges), settings saves, user changes, content reset, backup downloads. Filterable by admin + action. |
| **Backup** | Download a single zip containing a SQL dump, the uploads/ tree, and a manifest. Optionally include `.env`. |

---

## Print-on-Demand Setup

### Printful
1. Create your products at printful.com
2. Copy the product URL or your storefront URL
3. In Admin → Add Product → Type: Merch → Provider: Printful → Paste URL

### Printify
Same process — use your Printify store URL or individual product links.

### Redbubble
Add your Redbubble shop/product URLs. Customers click "Buy Now" and go to Redbubble.

---

## Social Media Posts

Since Instagram/TikTok restrict direct API access, the recommended workflow is:

1. **Thumbnail method**: Upload your post image somewhere (Cloudinary, your own server, etc.), paste the image URL + post URL in Admin → Social Posts.
2. **Embed method**: Paste the embed `<iframe>` code directly (works for TikTok, YouTube).

The homepage shows posts marked as "featured".

---

## Customisation

Most surface changes are admin-driven:

- **Colours / fonts / shapes** — `/admin/settings/theme.php` (preset + overrides + live preview).
- **Public text** (section titles, CTAs, empty states, page titles, ~52 strings) — `/admin/settings/page-text.php`.
- **Section visibility + order** on homepage and inner pages — `/admin/settings/page-sections.php`.
- **Email subject + body** (owner notification, customer receipt, shipping) — `/admin/settings/email-templates.php`.
- **Login providers** (which OAuth methods are exposed) — `/admin/settings/auth.php`.

Code changes:
- **Adding a new public page**: Create a new `.php` in `public/`, include `bootstrap.php` + the `templates/nav.php` and `templates/footer.php` partials.
- **Adding a new homepage section**: Drop a partial in `public/sections/home/`, then add a `CATALOG` entry + `DEFAULT_SORT_ORDER` row in [includes/PageSections.php](includes/PageSections.php) + a seed row in `sql/init.sql` + a new migration.
- **Adding new public-facing strings**: Add a key to `PageText::DEFAULTS` in [includes/PageText.php](includes/PageText.php), then render with `<?= e(PageText::get('group', 'key')) ?>`.
- **Shop categories**: managed directly in the database (`shop_categories` table); reseeded by `bin/reset-content.php`.

---

## Security Notes

- **Admin auth** is multi-provider — local username/password (bcrypt via PHP `password_hash`), GitHub OAuth, Google OAuth. Each provider is independently enabled + configured from `/admin/settings/auth.php`. OAuth providers fail closed when their `*_enabled` toggle is off or any credential is blank — the login page hides them. GitHub/Google logins only proceed for usernames/emails on the per-provider allowlist. Local logins additionally pass through a per-IP rate limit (5 failed attempts / 10-minute sliding window) and optional TOTP 2FA challenge.
- All admin POST handlers and GET-style delete endpoints validate a per-session CSRF token.
- Sessions regenerate their ID on login and run with `httponly` + `samesite=Lax`; the `Secure` flag honors `X-Forwarded-Proto` for proxy-terminated TLS.
- All user input is escaped with `e()` / `htmlspecialchars` or parameterised queries.
- Stripe webhooks are deduplicated by event id in `stripe_webhook_events` (PK serializes concurrent retries).
- The installer self-deletes its own folder (`public/install/`) after success; an `.installed` marker file at the project root is the fallback gate.
- Mail headers are CRLF-sanitized in `Mailer::sanitizeHeader`.
- The backup zip contains password hashes + OAuth credentials — treat it like a master password.
- `includes/`, `config/`, `sql/` carry `.htaccess` deny rules; the admin folder additionally blocks `*.log`, `*.sql`, `*.md`, `*.env`, `*.ini`, `*.sh`.
- Enable HTTPS at the web server level.
