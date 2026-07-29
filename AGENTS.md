# Working on this project

A WooCommerce plugin that audits a store's coupon inventory and (later) measures
per-coupon profitability. PHP 8.1+, WordPress 6.4+, WooCommerce 10.3+.

The architectural half of the specification is in
[docs/SPECIFICATION.md](docs/SPECIFICATION.md). Source comments cite it by
section number (`§3.3`, `§5`, `§10.4`). **Its numbering is load-bearing** — the
gaps between sections are deliberate, and compacting them would break ten
citations across the source.

## Commands

```bash
composer run check            # lint + PHP 7 boundary check + PHPStan + unit tests
composer run test:unit        # pure domain, no WordPress, no database (~0.5s)
composer run test:integration # real WordPress + WooCommerce + database
composer run lint:fix         # PHPCBF auto-fixes
```

Run `composer run check` before every commit. It is what CI runs, minus the
integration job.

## The rules that are not negotiable

**Test first.** Strict red → green → refactor. The test must fail, and fail *for
the right reason*, before any production code is written. Bugs enter the same
way: a failing test that reproduces it, then the fix. If something is hard to
test, the answer is a missing seam, not a cleverer test.

**Never call `time()`, `date()` or `current_time()`.** Anything that needs to
know the time takes a `ClockInterface`. Without this, coupon status depends on
the day the suite runs and boundary cases cannot be written at all. `SystemClock`
takes its timezone as a constructor argument so that even it stays WordPress-free.

**The domain never sees WordPress.** Nothing under `src/Domain/` may call a
WordPress function, touch `$wpdb`, or use a WooCommerce class. This is what keeps
the unit suite in the tens of milliseconds. The layering (§5):

| Layer | May do | Must not |
|---|---|---|
| `Domain/` | pure logic on value objects | anything WordPress |
| `Repository/` | `$wpdb`, WooCommerce APIs | business rules |
| `Service/` | coordinate the two | calculate anything itself |
| `Admin/` | format what it is given | decide anything |

**Never invent a WooCommerce meta key or API detail.** Read it out of the
WooCommerce source in `~/proyectos/wordpress/wp-content/plugins/woocommerce`
first. This is how the auto-apply gap and the site-local `date_created` column
were found; guessing would have produced plausible, wrong code. Prefer going
through `WC_Coupon` and friends over reading meta directly — WooCommerce handles
its own legacy fallbacks.

**Warnings never block a save** (§9). The pre-publish check informs and lets the
user proceed. Do not reach for a validation hook that can veto. An analytics
plugin that prevents work gets uninstalled the first Tuesday.

**Money will be integers in the currency's minor unit.** No floats, ever. Mixed
currencies aggregate separately and are never summed.

**Two singletons, no more**: `Plugin` and `Container`. Both have *public*
constructors so tests build their own and never share static state. Everything
else is an ordinary class resolved through the container.

## Static analysis and standards

- PHPStan runs at level 8. **Do not add `@phpstan-ignore`, baseline entries,
  `assert()`, inline `@var` overrides, or type casts to silence it.** Fix the
  cause. Every finding so far has been a real defect.
- Every PHPCS exclusion in `phpcs.xml.dist` carries a comment explaining why the
  sniff is wrong for this codebase. A new exclusion without one is not acceptable.
- `phpcs` reports `N / N` as a *batch* counter under `parallel`, not a file
  count. Use `--parallel=1` if you want to see files.

## Naming

| Element | Form |
|---|---|
| Options, hooks, transients, tables | `dfxcaaw_` |
| Namespace | `DFX\CouponAAW` |
| Text domain | `coupon-audit-and-analytics-for-woocommerce` (must equal the slug) |
| Custom meta | `_dfxcaaw_` |

The `dfxcaaw` prefix is deliberately independent of the slug, so renaming the
product touches only the file header, the directory name and the text domain.

## Tests

`tests/Unit/` runs without WordPress; `tests/Integration/` runs inside the real
WordPress test suite. Fixtures live in `tests/Fixtures/` — prefer
`CouponSnapshotBuilder` and `FrozenClock` over constructing snapshots by hand.

**The integration suite drops and reinstalls the database it points at.** It
defaults to `/tmp/wordpress-tests-lib` + `/tmp/wordpress`; `/tmp` does not
survive a reboot, so re-run `bin/install-wp-tests.sh` rather than debugging the
bootstrap. Never point it at a database behind a site anyone cares about.

**The test suite makes tables temporary.** WordPress wraps each test in a
transaction and filters every query so `CREATE TABLE` becomes `CREATE TEMPORARY
TABLE`. Such a table is fully usable but appears in neither `SHOW TABLES` nor
`information_schema`, so "does this table exist" has to be asked by describing
it. This cost an afternoon once; it will not announce itself.

**CI runs MySQL 8; a dev machine may well run MariaDB.** They disagree about
integer display widths, zero-date defaults and more, so a schema change that
passes locally can still fail CI. To check against MySQL before pushing:

```bash
docker run -d --name dfxcaaw-mysql -e MYSQL_ROOT_PASSWORD=root \
	-e MYSQL_DATABASE=wordpress_test -p 3307:3306 mysql:8.0
cp -r /tmp/wordpress-tests-lib /tmp/wordpress-tests-mysql   # then point its
                                                            # wp-tests-config.php
                                                            # at 127.0.0.1:3307
WP_TESTS_DIR=/tmp/wordpress-tests-mysql composer run test:integration
```

The project is pinned to **PHPUnit 9.6**, not 10 or 11. The WordPress core test
library still calls `PHPUnit\Util\Test::parseTestMethodAnnotations()`, removed in
PHPUnit 10. This is a constraint, not a preference — do not "upgrade" it.

## Commits

Commit messages explain *why*, not what — the diff already says what. Note
decisions that a reader would otherwise have to reverse-engineer, and defects
found along the way.

**Never add `Co-Authored-By` or `Claude-Session` trailers.** Commits are signed
only by David Marín Carreño.

## Cost sources

Cost of goods is read through `CostSourceInterface`, one implementation per
source, resolved in priority order by `CostSourceRegistry` (§7).

**Native COGS is not enough on its own.** It arrived only in WooCommerce 10.3,
and stores that have been tracking cost for years are the ones with data worth
reporting on — their cost lives in whichever plugin they adopted before core had
an answer. A store that gets "no cost data" from this plugin while plainly having
cost data will not file a bug, it will uninstall. So v1 ships adapters for the
popular third-party sources alongside the native one, not just the native one.

The verified landscape — which plugin stores what, under which key, and which
of them record cost at the time of sale rather than only current cost — is in
[docs/COST-SOURCES.md](docs/COST-SOURCES.md). Read it before touching an
adapter. Every adapter is verified against that plugin's actual storage before
being written — never from documentation or memory. Where a source cannot be verified
(a paid extension whose code is not available), say so plainly rather than
shipping a guess: an adapter that reads the wrong meta key reports a confident,
wrong margin, which §6.3 is explicit is worse than reporting nothing.

Adding an adapter must stay cheap: register it in the container, and the registry
picks it up. That is the plugin's most likely extension point.

## Shipping

`composer run dist` builds `build/coupon-audit-and-analytics-for-woocommerce.zip`
with runtime dependencies only and everything in `.distignore` removed.

**Run Plugin Check against the build, in a directory named exactly as the slug.**
Checking the repository reports the test harness as a bundled application;
checking a copy named anything else reports eighty text-domain mismatches and a
trademark violation that do not exist, because both rules compare against the
folder name. See [docs/PLUGIN-CHECK.md](docs/PLUGIN-CHECK.md), which also records
the one finding that is deliberate and must not be "fixed".

## Where the build is

Phase 1 is complete — all eleven milestones of §15. Phase 2 is the Freemius SDK
and the paid features, and begins once the slug is approved. The swap is
`LocalFeatureGate` for a licensed implementation of `FeatureGateInterface`, and
touches nothing else.
