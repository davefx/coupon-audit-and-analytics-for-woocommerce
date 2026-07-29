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

The project is pinned to **PHPUnit 9.6**, not 10 or 11. The WordPress core test
library still calls `PHPUnit\Util\Test::parseTestMethodAnnotations()`, removed in
PHPUnit 10. This is a constraint, not a preference — do not "upgrade" it.

## Commits

Commit messages explain *why*, not what — the diff already says what. Note
decisions that a reader would otherwise have to reverse-engineer, and defects
found along the way.

**Never add `Co-Authored-By` or `Claude-Session` trailers.** Commits are signed
only by David Marín Carreño.

## Where the build is

Milestones 1–6 of §15 are done: scaffolding, coupon domain, repository,
inventory screen, overlap detection, pre-publish warning. Next is cost adapters,
which is blocked on verifying WooCommerce 10.3+ native COGS meta keys against
source — the same discipline as above, and the last open question gating phase 1.
