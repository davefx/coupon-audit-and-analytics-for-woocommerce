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

| Command                       | What it does                                      |
|-------------------------------|---------------------------------------------------|
| `composer run test:unit`      | Pure-domain suite — no WordPress, no database     |
| `composer run lint`           | PHPCS (WordPress + WooCommerce Marketplace rules) |
| `composer run lint:fix`       | PHPCBF auto-fixes                                 |
| `composer run analyse`        | PHPStan level 8                                   |

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
```

The domain, repository, cost-adapter, admin and REST layers land in later
milestones. The rule that shapes all of them: the domain never sees WordPress,
so its tests need neither a database nor a bootstrap and the full unit suite
stays under two seconds.

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

## Licence

GPL-3.0-or-later. See [LICENSE](LICENSE).
