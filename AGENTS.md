# AGENTS.md

Guidance for AI coding agents (Claude Code, Cursor, Aider, etc.) working in this repository. Keep changes scoped, follow WordPress-Core PHPCS, and don't introduce dependencies beyond what's already in `composer.json`.

## Project Overview

**BMLT Minutes** is a WordPress plugin for NA (Narcotics Anonymous) Service Bodies (Areas, Regions, Zonal Forums) to publish committee meeting minutes — PDFs, DOCX, XLSX files, or external links (Google Docs, Dropbox, OneDrive). The plugin is intentionally small: one main PHP file, one CSS, one JS, no build step.

Sibling plugins to mirror in style and tone: `../crumb/`, `../fetch-meditation-wp/`.

## Layout

```
minutes.php          Main plugin file — singleton BMLT_Minutes class, all logic lives here
uninstall.php        Removes options + deletes all bmlt_minutes posts when the plugin is deleted
index.php            Empty silence file
css/minutes.css      Frontend list styles (used by [minutes] shortcode)
js/admin.js          wp.media file-picker wiring for the Minutes Document meta box
assets/              WordPress.org banner + icon + screenshots (gitattribute: export-ignore)
readme.txt           WordPress.org readme (Markdown-ish, dot-org-flavored)
README.md            GitHub README
composer.json        Dev deps only (phpcs + wpcs); plugin ships with zero runtime composer deps
Makefile             dev / lint / fmt / build targets
Dockerfile           wordpress:7.0-php8.3-apache base
docker-compose.yml   Local WP + MariaDB stack (port 8080, mounts parent dir as plugins/)
.phpcs.xml           WordPress-Core ruleset, short arrays allowed
```

## Commands

```bash
make composer   # Install dev deps (phpcs, wpcs)
make lint       # phpcs against WordPress-Core
make fmt        # phpcbf auto-fix
make dev        # Boot WP + MariaDB in Docker on http://localhost:8080
make build      # git archive zip into build/
make clean      # rm -rf build
php -l minutes.php   # quick syntax sanity check
```

## Architecture

All plugin logic is in `minutes.php` in a single `BMLT_Minutes` class (singleton via `get_instance()`). Hooks are wired in `__construct`; all handlers are `public static` so they can be referenced as `[ static::class, 'method' ]` callables and unit-tested without instantiation.

### Data model

- **CPT** `bmlt_minutes` — top-level admin menu "Minutes". `show_in_rest` enabled (Gutenberg-compatible). Slug: `minutes`.
- **Taxonomy** `bmlt_committee` — hierarchical (Category-like). Seeded on activation with NA defaults (ASC, RSC, H&I, PR, Activities, Outreach, Literature, Policy) **only if the taxonomy is empty**. Slug: `committee`.
- **Post meta** (all registered via `register_post_meta` so they appear in the REST API):
  - `_bmlt_minutes_date` — `Y-m-d` meeting date
  - `_bmlt_minutes_url` — external URL (Google Doc / Dropbox / etc.)
  - `_bmlt_minutes_attachment_id` — WP attachment ID

  **Precedence**: when both `_bmlt_minutes_attachment_id` and `_bmlt_minutes_url` are set, the uploaded attachment wins. This is intentional — see `resolve_document()`.

### Options

| Option key | Used for |
|---|---|
| `bmlt_minutes_server` | BMLT root server URL (informational; no calls made currently) |
| `bmlt_minutes_service_body` | Service body ID (informational) |
| `bmlt_minutes_sort_order` | Default `[minutes]` sort (`desc` / `asc`) |
| `bmlt_minutes_max_upload_mb` | Per-file upload cap in MB. Default 10. Clamped to `wp_max_upload_size()` on save. |

If you add a new option, also add it to the `$minutes_options` array in `uninstall.php`.

### Upload size cap

Three layers, all reading from `BMLT_Minutes::max_upload_bytes()`:

1. `upload_size_limit` filter — reports the cap to the Media Library UI.
2. `wp_handle_upload_prefilter` — server-side rejection on POST.
3. `js/admin.js` `frame.on('select')` — pre-flight alert when picking an already-uploaded oversize attachment.

All three are scoped via `is_minutes_upload_context()` — uploads outside the Minutes editor are not affected. The context detection covers three cases: `get_current_screen()` (regular admin), `$_REQUEST['post_id']` (async-upload.php), and `HTTP_REFERER` parsing (Media frame popups).

### Shortcode

`[minutes]` — only public-facing surface. Attributes: `committee`, `year`, `limit`, `order`, `group_by`, `show_excerpt`. Renders into `.bmlt-minutes` with dashicon-prefixed file links. Style hook: `.bmlt-minutes__*` BEM-ish classes.

Type → dashicon mapping lives in `dashicon_for_type()`. To add a new file type:

1. Add extension to `ALLOWED_EXTENSIONS`.
2. Add a case to `dashicon_for_type()`.
3. Mention it in the meta box description and `readme.txt`.

## Conventions

- **No build step.** No webpack/rollup, no JS framework, no SCSS. Vanilla CSS + jQuery-flavored JS (jQuery is already loaded by WP admin).
- **WordPress-Core PHPCS.** Short arrays (`[]`) are allowed; everything else follows core. Run `make fmt` before commit.
- **PHP 8.3.** Use typed properties, return types, `match` expressions, `str_contains`. Don't shim for older PHP.
- **Singleton + static handlers.** Don't add instance state to `BMLT_Minutes` — keep it stateless so hook callbacks can be `[ static::class, 'method' ]`.
- **Escape on output, sanitize on input.** Every echoed value goes through `esc_html` / `esc_attr` / `esc_url` / `esc_textarea` as appropriate. Don't trust `$_POST`, `$_GET`, or `$_REQUEST` — `wp_unslash` then sanitize.
- **Nonces on writes.** `save_meta()` checks `self::NONCE_FIELD` before touching meta. If you add new write paths, follow the same pattern.
- **`register_post_meta` over raw `update_post_meta`.** Keeps REST API and Gutenberg in sync. Always include `auth_callback`.
- **No comments that restate the code.** Comments should explain *why* — see `resolve_document()` or `is_minutes_upload_context()` for the kind of context-explaining comments that are welcome.

## Don't

- Don't add a `wp_remote_get()` call without caching via transient — see how `crumb.php` does `COUNTS_CACHE_TTL`.
- Don't hand-roll HTML escaping with `htmlspecialchars` — use the WordPress `esc_*` family.
- Don't introduce composer runtime dependencies. Dev-only is fine.
- Don't break the `[minutes]` shortcode API without bumping a major version in `BMLT_MINUTES_VERSION` and `readme.txt`'s Stable tag.
- Don't add a new menu page without `current_user_can( 'manage_options' )` or stricter.

## Local Development

```bash
make dev           # WordPress at http://localhost:8080
make bash          # Shell into the wordpress container
```

The compose file mounts the **parent directory** (`../`) as `wp-content/plugins`, so sibling bmlt-enabled plugins are also available. To activate the plugin during dev, log in to wp-admin and activate "BMLT Minutes" on the Plugins screen.

## Testing manually

1. `make dev`
2. wp-admin → Plugins → activate BMLT Minutes
3. Minutes → Add New → set title, meeting date, upload a PDF or paste a Google Doc URL, assign a committee
4. Create a page with `[minutes]` and view it
5. Verify Settings → Maximum Upload Size enforces correctly by attempting to upload a file over the cap

There is no PHPUnit suite yet. If you add one, follow `../crumb/tests/` for the wp-phpunit bootstrap pattern.
