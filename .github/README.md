# This repository is generated

Everything here except `.github/` is built by a machine and replaced wholesale on
every release. Editing a file in it is editing something that will be overwritten
without anybody noticing — including, quietly, by the release that overwrites it.

**The source lives in a private repository**, together with the paid add-on's
code. This is the free plugin exactly as WordPress.org serves it: dependencies
installed without the development ones, the test harness removed, and every paid
file stripped out by Freemius.

## Where a release comes from

```
private source  ──►  Freemius  ──►  this repository  ──►  WordPress.org SVN
                     strips the      pushed and             deployed by the
                     paid files      tagged                 workflow here
```

Pushing a version tag here is what publishes to the plugin directory, and the
workflow in `.github/workflows/` is the only file in this repository that is not
generated. The tag is pushed by the release job upstream, after it has built the
package, had Freemius generate this build from it, and run the test suite against
*this* build rather than against the source — because the free plugin is a
different program from the one the source describes, and until that point nobody
has run it.

## Reporting something

Issues and pull requests are welcome here, and the code in them is read. A pull
request cannot be merged into this repository, though — the next release would
erase it. Say what should change and it gets made upstream, where it will survive.

## The plugin

Audits a WooCommerce store's coupon inventory and reports per-coupon
profitability. See `readme.txt`, or the listing at
<https://wordpress.org/plugins/coupon-audit-and-analytics-for-woocommerce/>.
