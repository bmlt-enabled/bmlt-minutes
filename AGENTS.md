# AGENTS.md

Guidance for AI coding agents (Claude Code, Cursor, Aider, etc.) working in this repository. Keep changes scoped, follow WordPress-Core PHPCS, and don't introduce dependencies beyond what's already in `composer.json`.

## Project Overview

**BMLT Minutes** is a WordPress plugin for service bodies and committees to publish meeting minutes — PDFs, DOCX, XLSX files, or external links (Google Docs, Dropbox, OneDrive). Originally built for the bmlt-enabled (NA Service Bodies) community, but useful for any organization that needs a tidy minutes archive. The plugin is intentionally small: one main PHP file, one CSS, one JS, no build step.

Sibling plugins to mirror in style and tone: `../crumb/`, `../fetch-meditation-wp/`.

## Layout

```
minutes.php                Main plugin file — singleton BMLT_Minutes class, all logic lives here
uninstall.php              Removes options + deletes all bmlt_minutes posts when the plugin is deleted
index.php                  Empty silence file
css/minutes.css            Frontend list styles (used by [minutes] shortcode)
js/admin.js                wp.media file-picker wiring for the Minutes Document meta box
assets/                    WordPress.org banner + icon + screenshots (gitattribute: export-ignore)
readme.txt                 WordPress.org readme (Markdown-ish, dot-org-flavored)
README.md                  GitHub README
composer.json              Dev deps only (phpcs + wpcs + phpunit + wp-phpunit + polyfills); zero runtime deps
Makefile                   dev / lint / fmt / build / test / install-wp-tests targets
Dockerfile                 wordpress:7.0-php8.3-apache base
docker-compose.yml         Local WP + MariaDB stack (port 8080, mounts parent dir as plugins/)
.phpcs.xml                 WordPress-Core ruleset, short arrays allowed; tests/ and bin/ excluded
phpunit.xml                PHPUnit config (bootstrap=tests/bootstrap.php, polyfills wired)
bin/install-wp-tests.sh    Downloads WordPress core + creates the test DB (called by CI + locally)
tests/bootstrap.php        Loads wp-phpunit, manually loads minutes.php, runs activate() once
tests/wp-tests-config.php  Test-suite wp-config (reads DB_* env vars, falls back to localhost)
tests/test-minutes.php     Unit tests (registration, sanitizers, resolve_document, shortcode, password)
.github/workflows/         pull-requests.yml, release.yml, latest.yml
.github/scripts/           deploy-wordpress.sh — pushes a tagged release to plugins.svn.wordpress.org
```

## Commands

```bash
make composer          # Install dev deps (phpcs, wpcs, phpunit, wp-phpunit, polyfills)
make lint              # phpcs against WordPress-Core
make fmt               # phpcbf auto-fix
make dev               # Boot WP + MariaDB in Docker on http://localhost:8080
make build             # git archive zip into build/
make clean             # rm -rf build
make test              # Run PHPUnit in Docker (self-contained — no local MySQL needed)
make test-clean        # Tear down test containers + images + volumes
php -l minutes.php     # quick syntax sanity check
```

`make test` is self-contained: it builds `Dockerfile.test` (PHP 8.3 + composer), boots a tmpfs MariaDB sidecar, runs `bin/install-wp-tests.sh` inside the container to download WP core, then executes `vendor/bin/phpunit`. No host-side database or `/tmp/wordpress` is required. CI bypasses this and runs phpunit directly against a service container — see `.github/workflows/`.

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

### Capabilities & roles

The CPT uses its **own** capability set (`capability_type => ['bmlt_minute','bmlt_minutes']`, `map_meta_cap => true`) rather than the generic `post` caps. This lets minutes access be granted independently of general post editing. The primitive caps (`edit_bmlt_minutes`, `publish_bmlt_minutes`, `delete_others_bmlt_minutes`, …) are listed in `minutes_capabilities()` — that method is the single source of truth, and `uninstall.php` keeps a hardcoded copy in sync (it can't load the class).

`add_capabilities()` runs on activation and:
- mirrors all minutes caps onto `administrator` + `editor` (so existing privileged users keep working);
- creates a `minutes_manager` role (constant `ROLE_MANAGER`) with the minutes caps plus `read` + `upload_files` — enough to reach wp-admin and use the Media Library, nothing more. It's removed-then-readded so it stays idempotent across re-activations.

`render_user_capability_field()` / `save_user_capability_field()` add a "Can manage minutes" checkbox to the user-edit screen (`show_user_profile` / `edit_user_profile`), gated on `current_user_can('promote_users')`. Ticking it grants the minutes caps + `upload_files` at the **user** level (via `WP_User::add_cap`), so an existing Author/Editor/Subscriber gets minutes access without changing their role. If the user's role already grants `PRIMARY_CAP` (`user_role_grants_minutes()`), the checkbox renders disabled and the save path no-ops — manage it via the role instead.

Gotchas for future changes:
- `PRIMARY_CAP` (`edit_bmlt_minutes`) is the "can touch minutes" gate. The `register_post_meta` `auth_callback`s check it (not the generic `edit_posts`), otherwise Minutes Managers couldn't save meta via REST/Gutenberg.
- The committee taxonomy sets `assign_terms => PRIMARY_CAP` but leaves `manage/edit/delete_terms => manage_categories`, so managers can categorize posts but not curate the committee list.
- `save_meta()` uses `current_user_can('edit_post', $post_id)`, which `map_meta_cap` correctly resolves to the CPT caps — no change needed there.
- The Settings page stays `manage_options` (admin-only); managers don't get to change the upload cap or server URL.
- If you add a new cap, update `minutes_capabilities()` **and** the hardcoded array in `uninstall.php`.

### Password protection

The plugin uses WordPress's native `wp_posts.post_password` column — no custom meta. Some NA service bodies redact PII before posting minutes, others share unredacted minutes with members only, so locking is per-post and **public-by-default** (empty password = unrestricted).

Three integration points:

1. **Meta box field** (`render_meta_box`) — adds a plain-text Password Protection input alongside the document fields. The native Publish → Visibility → Password protected control still works; both write to the same column.
2. **Save path** (`apply_password_field`) — hooks `wp_insert_post_data` so the password lands in `post_password` during the same insert that creates/updates the row. **Don't** push this through `wp_update_post` from `save_post`; that recurses.
3. **Display** — `render_item()` checks `post_password_required( $post )` and, when true, hides the document URL/type, swaps in `dashicons-lock`, and routes the link to the singular permalink (where WP renders its built-in password form). `append_document_link()` filters `the_content` on singular `bmlt_minutes` views to add a "View Document" button below the post body — but returns early when the post is still locked, so the button only appears after the password cookie is set.

The shortcode list intentionally still **shows** locked entries (titled, dated, padlocked) so members know which meetings exist; only the document URL itself is gated.

For protecting the **entire page** that hosts `[minutes]`, the plugin deliberately ships nothing — WordPress's native Visibility → Password protected on the containing page already does it, so there's no reason to reinvent it. If a future change reintroduces a shortcode-level gate, do it via a Settings-stored password (never a literal in the shortcode attribute, which leaks into page source and caches).

### Options

| Option key | Used for |
|---|---|
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

`[minutes]` — primary public-facing surface. Attributes: `committee`, `year`, `limit`, `order`, `group_by`, `show_excerpt`. Renders into `.bmlt-minutes` with dashicon-prefixed file links. Style hook: `.bmlt-minutes__*` BEM-ish classes. Locked entries get the `.bmlt-minutes__item--locked` modifier and a `.bmlt-minutes__type--locked` "Protected" badge.

The singular `bmlt_minutes` permalink is also public — used as the unlock surface for password-protected items, and as a fallback link target when neither attachment nor URL is set.

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

The compose file mounts the **parent directory** (`../`) as `wp-content/plugins`, so sibling bmlt-enabled plugins are also available. To activate the plugin during dev, log in to wp-admin and activate "Minutes" on the Plugins screen.

## Automated tests

PHPUnit runs against the WordPress test suite via the `wp-phpunit/wp-phpunit` composer package. The setup mirrors `../crumb/` exactly:

- `tests/bootstrap.php` loads wp-phpunit, then manually `require`s `minutes.php` and calls `BMLT_Minutes::activate()` so the CPT/taxonomy/default committees exist for every test run.
- `tests/wp-tests-config.php` is environment-driven — DB credentials come from `DB_HOST` / `DB_USER` / `DB_PASS` / `DB_NAME` (defaults: `localhost` / `root` / `root` / `wordpress_test`).
- `tests/test-minutes.php` covers registration, sanitizers, `resolve_document()` precedence, the `[minutes]` shortcode (incl. locked-post URL hiding), the `the_content` single-view filter, and the `apply_password_field` nonce-gated post_password write.

Local one-shot:

```bash
make test               # docker-compose: builds image, runs migrations + phpunit
```

If you want to run tests on the host (e.g. while debugging a specific failure), boot a MariaDB yourself, point env vars at it, run `bash bin/install-wp-tests.sh wordpress_test root root 127.0.0.1 latest` once, then `vendor/bin/phpunit`.

If you add public behavior, add a test. Prefer testing through the public API (`render_shortcode`, `resolve_document`, etc.) rather than reflecting into private helpers. New private helpers usually don't need direct coverage — exercise them via a public entrypoint.

## CI / Release

Three workflows in `.github/workflows/` (mirrors `../crumb/`):

| Workflow | Trigger | Jobs |
|---|---|---|
| `pull-requests.yml` | PR to `main` | `lint` (phpcs) + `test` (phpunit on 8.3 & 8.4) |
| `latest.yml` | push to `main` | `lint` + `test` + `deploy` — builds with `PROD=1 make build` (no dev deps), uploads to S3 (`archives.bmlt.app/minutes/…`), then calls `bmlt-enabled/wordpress-releases-github-action@v2`. Needs `AWS_ACCOUNT_ID` + the OIDC role `gh-ci-s3-artifact`. |
| `release.yml` | push of a tag (e.g. `1.1.0`) | `lint` + `test` + `package` — produces `minutes-<tag>.zip`, attaches it to a GitHub Release, then runs `.github/scripts/deploy-wordpress.sh` to push the build to the WordPress.org SVN (skipped for tags containing `beta`). Needs `WORDPRESS_USERNAME` + `WORDPRESS_PASSWORD` secrets. |

`deploy-wordpress.sh` enforces that the tag matches `Version:` in `minutes.php` and `Stable tag:` in `readme.txt`. **When bumping the version, update both** — the deploy will refuse to ship otherwise.

Release notes are auto-extracted from the matching `= X.Y.Z =` block in `readme.txt`'s changelog, so the changelog entry for the version is what the GitHub Release body shows.

## Testing manually

1. `make dev`
2. wp-admin → Plugins → activate BMLT Minutes
3. Minutes → Add New → set title, meeting date, upload a PDF or paste a Google Doc URL, assign a committee, optionally set a password
4. Create a page with `[minutes]` and view it — verify protected entries show a padlock, route to the singular permalink, and only reveal the doc after the password is entered
5. Verify Settings → Maximum Upload Size enforces correctly by attempting to upload a file over the cap
