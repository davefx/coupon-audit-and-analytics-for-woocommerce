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
composer run screenshots      # rebuild the readme screenshots (~3 min, needs docker)
composer run assets           # render the wp.org banner and icon
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

**Coverage is 94.6%, and the gap is deliberate.** Measure it with pcov enabled
explicitly — `php -d pcov.enabled=1 -d pcov.directory=. vendor/bin/phpunit` —
and merge the two suites, or the half each covers reads as the half the other
does not. What is knowingly left uncovered: the markup inside the list tables and
the service providers' inner closures, which are exercised through the screens
rather than directly; and defensive guards that cannot be reached, such as
`DiscountAmount`'s check that a discount is not both fixed and a percentage,
which its private constructor makes unreachable. Do not chase those numbers by
asserting on markup — a test that pins the exact HTML of a cell fails on every
wording change and catches nothing.

**Where a rule has a batch and a single-item entry point, test that they agree.**
`OrphanDetector::reasons_for_all()` against `reasons()`, and the overlap index
against comparing every pair. The batch versions exist only because the others
are too slow in a loop, so a divergence would be invisible in the fast path that
production actually uses.

**The integration suite drops and reinstalls the database it points at.** It
defaults to `/tmp/wordpress-tests-lib` + `/tmp/wordpress`; `/tmp` does not
survive a reboot, so re-run `bin/install-wp-tests.sh` rather than debugging the
bootstrap. Never point it at a database behind a site anyone cares about.

**The test suite makes tables temporary.** WordPress wraps each test in a
transaction and filters every query so `CREATE TABLE` becomes `CREATE TEMPORARY
TABLE`. Such a table is fully usable but appears in neither `SHOW TABLES` nor
`information_schema`, so "does this table exist" has to be asked by describing
it. This cost an afternoon once; it will not announce itself.

**`Tested up to` must name a version somebody actually tested against.** Pass a
version to the installer to do it — `rc` resolves whatever release candidate
WordPress is currently offering:

```bash
WP_TESTS_DIR=/tmp/wp-tests-rc WP_CORE_DIR=/tmp/wp-rc \
	bin/install-wp-tests.sh wp_tests wp wp12345 localhost rc
WP_TESTS_DIR=/tmp/wp-tests-rc WP_CORE_DIR=/tmp/wp-rc composer run test:integration
```

Separate directories, so the pinned environment survives alongside it. Note that
`wordpress-develop` tags final releases only — a release candidate's test library
comes from the branch for its major.minor, which the installer falls back to. CI
runs this as a non-blocking job, because an RC is allowed to have bugs of its own
and one must not redden a merge.

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

`composer run dist` builds
`build/coupon-audit-and-analytics-for-woocommerce-<version>.zip` with runtime
dependencies only and everything in `.distignore` removed. The version comes
from the plugin header, and the build fails if the readme's `Stable tag`
disagrees with it.

Releases are tagged with the bare version number — `0.1.0`, not `v0.1.0` — so a
git tag and the wp.org SVN tag for the same release are spelled the same way.

**Run Plugin Check against the build, in a directory named exactly as the slug.**
Checking the repository reports the test harness as a bundled application;
checking a copy named anything else reports eighty text-domain mismatches and a
trademark violation that do not exist, because both rules compare against the
folder name. Plugin Check currently reports **nothing** across every category. Keep it that
way. [docs/PLUGIN-CHECK.md](docs/PLUGIN-CHECK.md) records what that cost —
exception messages carry no interpolated values, because the escaping sniff
rejects any variable reaching an exception constructor.

**Regenerate the screenshots when a screen changes**, with `composer run
screenshots` — it builds a throwaway shop, photographs it and removes it, in about
three minutes. Then *look at the pictures*. Twice now they have shown a defect that
no test had thought to ask about: the terms columns in 0.2.0, and an absent spend
limit read as zero in 0.2.1. A diff touching only `screenshot-3.png` means nothing
changed, since the coupon editor prints the moment the demo coupon was published.
[bin/screenshots/README.md](bin/screenshots/README.md) has the traps.

**The listing banner and icon are generated too**, from HTML in
`bin/wporg-assets/`, so the palette stays the plugin's own and a change of wording
is a diff. They are not shipped in the zip — wp.org reads them from `assets/` in
SVN, and `.distignore` keeps `.wordpress-org` out of the build.
[bin/wporg-assets/README.md](bin/wporg-assets/README.md) records why the small
icon is reduced rather than rendered: headless Chrome will not paint into a window
that small and hands back a tile of the background colour, which looks like a
working file until it is opened.

## Ask the database once, not once per coupon

The inventory screen reads every coupon on every load, so anything done *per
coupon* is done hundreds of times. Two rules follow, and
[docs/PERFORMANCE.md](docs/PERFORMANCE.md) has the measurements behind them.

**No query inside a per-coupon loop.** Fetch what the whole inventory needs
first, then judge each coupon from what is in hand. `ScopePricing` is the shape
to copy: the catalogue is asked in bulk, once, before anything is judged.
`WcCatalogRepository` therefore takes lists and returns maps — resist adding a
convenient single-item method, because it will end up in a loop.

**No scan of the inventory inside a per-coupon loop either.** The dead-campaign
rule did that and was quadratic. Count once into an index, judge against the
tally. Where a rule has both a batch and a single-coupon entry point, a test must
assert the two agree.

`WpCouponRepository` fills WooCommerce's own coupon meta cache from one query
before building any `WC_Coupon`. Those cached rows carry real `meta_id`s
deliberately — WooCommerce tells existing meta from new by that ID, and cached
rows without them would make the next `save()` re-insert every meta value. Do not
rebuild that cache from `update_meta_cache()`, which drops the IDs.

Two integration tests hold this down: listing nine coupons must cost exactly what
listing three costs, and saving a coupon after listing must not duplicate its
meta. If either starts failing, the screen has quietly gone back to one query per
coupon — or worse.

## Nothing in the directory version may be locked

There is no licensing layer, and none may be added back. The plugin once carried
a `FeatureGateInterface` that answered "no" to `FULL_HISTORY` until a licence said
otherwise, capping the margin screen at thirty days while the code could do a
year. WordPress.org calls that locked functionality and it is not permitted,
however cleanly it is abstracted — the pre-review pended the submission over it.

**Paid features are a separate plugin that adds code, never a key that switches
on code already installed.** So: no `Feature` enum naming things a shop cannot
have, no gate, no "free tier" in any comment, name or string. A constant named
`FREE_WINDOW_DAYS` is the sort of thing that gets a submission pended.

Where behaviour should be changeable, use a documented filter. Thirty days is the
margin screen's *default*, and `dfxcaaw_margin_window_days` changes it — public,
free to use, and documented in the readme's FAQ, which is what makes it an
extension point rather than a paywall with a hook in it. The filter is applied in
`AdminServiceProvider`, so `MarginService` keeps no opinion about WordPress and
stays unit-testable; a window under a day is refused, because a filter is allowed
to be wrong without taking the screen down.

## Where the build is

Phase 1 is complete — all eleven milestones of §15. Phase 2 is the paid features,
and begins once the slug is approved. They ship as a separate plugin that hooks
what is already here.
