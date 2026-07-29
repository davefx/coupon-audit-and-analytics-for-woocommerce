# Coupon Audit and Analytics for WooCommerce

Audits your WooCommerce coupon inventory — what is live, what it really applies
to, what overlaps, what is an orphan — and measures what each coupon actually
earns.

The plugin rests on two pillars of equal standing. **Audit** needs no cost data
and works on day one in every store. **Analytics** resolves revenue, cost of
goods and discount into a per-coupon margin, degrading gracefully wherever cost
data is missing rather than presenting an unreliable number as a reliable one.

## Requirements

| Component   | Minimum |
|-------------|---------|
| PHP         | 8.1     |
| WordPress   | 6.4     |
| WooCommerce | 10.3    |

HPOS and cart/checkout blocks compatibility are declared explicitly.

## What it does so far

**WooCommerce → Coupon Audit** lists every coupon in the store with its real
status, what it actually applies to, and what is wrong with it:

- **Never expires** — live with nothing scheduled to ever turn it off.
- **Dormant** — live but unredeemed for longer than the threshold.
- **Dead campaign** — live while every other code from its campaign has expired.
- **Applies to everything** — live with no product or category restriction at all.
- **Overlaps** — two live coupons that can both apply to the same product, graded
  by how likely the collision is to actually happen. Nothing in WooCommerce
  compares one coupon against another, so this finding is not reachable any
  other way.

Status is derived, never stored, so a coupon that expired overnight says so the
next morning. None of this needs cost data.

## Design

The architectural half of the technical specification is published as
[docs/SPECIFICATION.md](docs/SPECIFICATION.md). Comments throughout the source
cite it by section number — `§3.3` for the singleton policy, `§5` for the
layering rule, `§10.4` for the seams that are always injected. Its numbering is
inherited from the full document, so the gaps between sections are expected.

## Development

```bash
composer install
composer run check      # coding standards, static analysis, unit tests
```

Individual tasks:

| Command                        | What it does                                      |
|--------------------------------|---------------------------------------------------|
| `composer run test:unit`       | Pure-domain suite — no WordPress, no database     |
| `composer run test:integration`| Repositories against a real WordPress + WooCommerce|
| `composer run lint`            | PHPCS (WordPress + WooCommerce Marketplace rules) |
| `composer run lint:fix`        | PHPCBF auto-fixes                                 |
| `composer run analyse`         | PHPStan level 8                                   |

### Running the integration suite

It needs a WordPress install, the WordPress core test library, a database and
WooCommerce. To build all four from scratch:

```bash
bin/install-wp-tests.sh wordpress_test <db-user> <db-pass> localhost
composer run test:integration
```

If you already have a working setup, point the suite at it instead — nothing is
downloaded and nothing is overwritten:

```bash
export WP_TESTS_DIR=/path/to/wordpress-tests-lib   # default /tmp/wordpress-tests-lib
export WP_CORE_DIR=/path/to/wordpress              # default /tmp/wordpress
export WC_PLUGIN_DIR=/path/to/woocommerce          # default inside WP_CORE_DIR
```

**The database you point it at gets dropped and reinstalled on every run.** That
is how the WordPress test suite works; give it a database of its own and never
the one behind a site you care about.

The suite runs on PHPUnit 9.6 rather than 10 or 11. That is not a preference:
the WordPress core test library still calls `PHPUnit\Util\Test::parseTestMethodAnnotations()`,
which PHPUnit removed in 10. One PHPUnit for both suites beats two.

The `*.dist` configuration files are committed; drop an un-suffixed copy
(`phpunit.xml`, `phpcs.xml`, `phpstan.neon`) beside them to override locally
without touching the repository.

### How the code is laid out

```
src/
  Plugin.php        entry point — holds the container, runs the two-phase boot
  Container/        service container and provider contract
  Providers/        wiring, one provider per slice of the plugin
  Support/          values the main file alone knows (paths, version, slug)
  Domain/
    Clock/          the only way anything learns what time it is
    Coupon/         status, scope, orphans — pure logic, no WordPress
    Overlap/        colliding coupon pairs, and the index that finds them
  Repository/       the only layer that talks to $wpdb and WooCommerce
  Service/          orchestration; coordinates, calculates nothing
  Admin/            screens; formats what it is given, decides nothing
```

The cost-adapter and REST layers land in later milestones. The rule that shapes
all of them: the domain never sees WordPress, so its tests need neither a
database nor a bootstrap and the full unit suite stays under two seconds.

`Domain/` is the part to read first. `StatusResolver` derives a coupon's status
rather than storing it, `CouponScope` resolves what a coupon really applies to,
and `OrphanDetector` finds coupons that are still live but shouldn't be. None of
them can be constructed without a `ClockInterface`, which is deliberate: `time()`
and `current_time()` are never called anywhere in the codebase, so "expires
today" is a test you can actually write.

### Test-driven development

Strict red → green → refactor. A test is written first, it must fail for the
right reason, and no work moves on while anything is red. Bugs enter the same
way: a failing test that reproduces it, then the fix.

Four things are always injected behind an interface, without exception: time,
the database, licensing, and cost sources. When something proves hard to test,
the answer is a missing seam, not a cleverer test.

### Naming

The internal prefix is `dfxcaaw` (`dfxcaaw_` for options, hooks, transients and
tables; `DFX\CouponAAW` for the namespace). It is deliberately independent of
the plugin slug, so renaming the product touches only the file header, the
directory name and the text domain.

## Extending it

`dfxcaaw_coupon_is_auto_applied` — WooCommerce has no concept of a coupon that
applies without the customer entering it, but several plugins add one. An
overlap between two auto-applied coupons is the most serious finding the audit
produces, and without this filter that grade can never occur:

```php
add_filter(
	'dfxcaaw_coupon_is_auto_applied',
	fn ( bool $auto, int $coupon_id ): bool => my_plugin_applies_automatically( $coupon_id ),
	10,
	2
);
```

## Licence

GPL-3.0-or-later. See [LICENSE](LICENSE).
