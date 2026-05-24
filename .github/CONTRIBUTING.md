# Contributing to Minutes

Thanks for helping out. Minutes is a small WordPress plugin built by the [bmlt-enabled](https://bmlt.app) community to help service bodies and committees publish meeting minutes.

## How to Contribute

1. Fork the repository.
2. Create a branch for your change (`feature/short-description` or `fix/short-description`).
3. Make your changes — keep them focused; one logical change per pull request.
4. Run lint and tests locally (see below).
5. Open a pull request against `main`.

Check the [issues list](https://github.com/bmlt-enabled/minutes/issues) for things that need help. Once your PR is merged it ships in the next release.

## Local Development

The Docker setup spins up WordPress + MariaDB with the plugin mounted live:

```bash
make dev
```

Open <http://localhost:8080>, finish the WP install, log in, and activate **Minutes** on the Plugins screen. Edits to `minutes.php` (or the CSS / JS) take effect on reload.

To shell into the WordPress container:

```bash
make bash
```

## Code Standards

The project follows the **WordPress-Core** PHP_CodeSniffer ruleset configured in `.phpcs.xml`. Short array syntax (`[]`) is allowed; everything else is core WordPress style.

```bash
make composer   # install dev deps (one-time)
make lint       # phpcs — must pass before PR
make fmt        # phpcbf — auto-fix style issues
```

The plugin targets PHP 8.3+. Use typed properties, return types, `match` expressions, etc. — don't add shims for older PHP.

See [AGENTS.md](../AGENTS.md) for deeper architecture notes (CPT/taxonomy/meta layout, the three-layer upload cap, password protection model, shortcode conventions).

## Testing

Tests use [PHPUnit](https://phpunit.de/) with the [WordPress test suite](https://github.com/wp-phpunit/wp-phpunit). Everything runs in Docker — no local PHP or MySQL required.

```bash
make test         # builds the test image, boots MariaDB, runs phpunit
make test-clean   # tears down test containers, images, volumes
```

Tests live in `tests/` with a `test-` prefix and extend `WP_UnitTestCase`. If you add public behavior (a new shortcode attribute, a new sanitizer, a new filter hook), add a test that exercises it.

```php
class Test_My_Feature extends WP_UnitTestCase {
    public function test_something() {
        $this->assertTrue( shortcode_exists( 'minutes' ) );
    }
}
```

Prefer testing through the public API (`render_shortcode`, `resolve_document`, etc.) rather than reflecting into private helpers.

## CI

Every pull request runs:

- `make lint` (phpcs)
- `make test` (phpunit on PHP 8.3 and 8.4)

Both must pass before review. The same checks run on every push to `main`, plus a build artifact gets uploaded to S3 for the "latest" channel.

## Release Tagging

Releases are cut by pushing a git tag that matches the `Version:` header in `minutes.php` and `Stable tag:` in `readme.txt` — the deploy script enforces this and will refuse to ship otherwise.

- `1.x.y` — stable. Triggers a GitHub Release with the zip attached **and** pushes to the WordPress.org SVN.
- `1.x.y-beta.N` — prerelease. Creates the GitHub Release marked as prerelease; skips the WordPress.org push.

Release notes are auto-extracted from the matching `= X.Y.Z =` block in `readme.txt`'s changelog, so write the changelog entry before tagging.

## Reporting Bugs

- **Security issues**: see [SECURITY.md](SECURITY.md). Don't open a public issue.
- **Everything else**: open an issue with reproduction steps, WordPress version, PHP version, and what you expected to happen.
