=== Coupon Audit and Analytics for WooCommerce ===
Contributors: davefx
Tags: woocommerce, coupons, analytics, discounts, profit
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.4.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Find the coupons quietly costing you money: what is still live, what it really applies to, what overlaps, and what each one actually earns.

== Description ==

Most shops accumulate coupons. A launch code from two years ago, a partner
discount nobody switched off, three variations of the same campaign where only
one was meant to survive. WooCommerce will happily keep honouring all of them,
and it will not tell you.

This plugin reads your coupons and says what is wrong with them.

= The audit =

Works on day one, in every shop, with no setup and no cost data:

* **Never expires** — live, with nothing scheduled to ever turn it off.
* **Dormant** — live, but nobody has redeemed it in months.
* **Dead campaign** — live, while every other code from its campaign has expired.
* **Applies to everything** — live, with no product or category restriction at all.
* **Overlaps** — two live coupons that can both apply to the same product.
  Nothing in WooCommerce compares one coupon against another, so this is not a
  finding you can reach any other way.

Status is worked out when you look, never stored, so a coupon that expired
overnight says so the next morning.

Filter the list by discount type, or by whether a coupon expires at all — a shop
with four hundred coupons cannot be read top to bottom, and "show me everything
that never expires" is the question worth asking first.

= The warning where it matters =

The same findings appear on the coupon edit screen, against the coupon in front
of you. They never block a save. You are told, and you decide.

= The margin =

For each coupon: revenue, what it gave away, the cost of the goods it moved, and
the gross margin left over. Over the last 30 days by default, which you can
change with a filter — see the FAQ.

This half needs cost data, and it is honest about not having it. WooCommerce's
own cost-of-goods feature is off by default, so the plugin also reads the cost
plugins shops actually use:

* WooCommerce's built-in Cost of Goods (10.3 and later)
* Cost of Goods for WooCommerce, by WPFactory
* WooCommerce Cost of Goods
* Booster for WooCommerce

Figures come from **one** of these, never a mixture — a margin blended from two
sets of books reconciles with neither. If several are installed you choose which
one to believe.

Where cost is only partly known, the margin says so and states how much of it is
real. Where no cost is known at all, no margin is shown. A wrong number in a
financial dashboard destroys trust far faster than a missing number builds it.

= What it does not do =

No tracking. No external requests of any kind. No account. It reads your shop's
own database and shows you what is in it.

== Installation ==

1. Install and activate the plugin.
2. Go to **WooCommerce → Coupon Audit**. The audit works immediately.
3. For margins, make sure your shop records product costs — either through
   WooCommerce's own Cost of Goods feature or one of the supported plugins —
   then look at **WooCommerce → Coupon Margin**.

Past orders are read in the background after activation, so margins for older
periods fill in over a few minutes rather than all at once.

== Frequently Asked Questions ==

= Do I need a cost-of-goods plugin? =

Only for margins. The audit half — expiry, overlaps, orphans, scope — needs no
cost data at all and works the moment you activate.

= Why does a coupon show no margin? =

Because none of its orders has a cost recorded against it. The plugin will not
guess, and it will not quietly treat an unknown cost as zero, because that would
report your revenue as your profit.

= Why are some margins marked as an estimate? =

Either some order lines have no cost recorded, in which case the covered share
is stated alongside; or your cost plugin only stores what a product costs today
rather than what it cost when each order was placed. Both are useful. Neither is
exact, and the screen says so.

= I use two cost plugins. Which one does it read? =

One of them, chosen by you under **WooCommerce → Coupon Audit Settings**. It
never mixes them.

= Does it slow my shop down? =

It does not run on the storefront at all. Aggregation happens in the background
through Action Scheduler, and the admin screens read precomputed figures.

= Can I report on more than 30 days? =

Yes. Thirty days is the default window, not a limit. Change it with the
`dfxcaaw_margin_window_days` filter, from a snippet in your theme's
functions.php or from another plugin:

`add_filter( 'dfxcaaw_margin_window_days', function () { return 365; } );`

The figures are aggregated daily and the backfill walks your whole order
history, so a longer window has the data behind it.

= Does it send my data anywhere? =

No. The plugin makes no external requests.

== Screenshots ==

1. The coupon audit: every coupon, its real status, what it applies to, and what is wrong with it — expired, exhausted, dormant, dead campaign, no expiry date, and overlapping. Filter by discount type or by whether it expires.
2. Per-coupon margin. Cost coverage is stated rather than assumed: one coupon's margin is exact, one is an estimate over half its lines, and one has no cost recorded at all and so shows no margin.
3. Warnings on the coupon edit screen, against the coupon in front of you. They never block a save.
4. Settings: which cost-of-goods system to read, and whether uninstalling should take the data with it.

== Changelog ==

= 0.4.0 =
* Filter the audit by discount type, and by whether a coupon expires at all.
  The summary tiles keep describing the whole store, so the counts do not move
  as you narrow the list.

= 0.3.1 =
* Tested against WordPress 7.1 and WooCommerce 11. No code changes.

= 0.3.0 =
* The margin window is now a documented filter, `dfxcaaw_margin_window_days`,
  rather than a fixed cap. Thirty days remains the default; anything can change
  it, including a snippet in your own theme.
* Removed the internal feature-gate layer. Nothing in this plugin is withheld.

= 0.2.1 =
* Fixed the basket column, which read "0.00 to 0.00" against every coupon that
  has no spend limits. It now says "any", which is what it means.
* Fixed a warning that never appeared: a fixed discount with no minimum spend
  was not reported, because every coupon looked as though it required a basket
  of at least zero.

= 0.2.0 =
* The overview now shows what each coupon actually is: the discount, the basket
  it requires, free shipping, individual use, and the usage caps per customer
  and per basket.
* "Applies to" names the products and categories, prices them, and strikes
  through anything a customer cannot currently buy.
* New finding: a fixed discount worth more than the minimum spend it demands, or
  more than something it applies to. WooCommerce applies these without comment.
* Support for WooCommerce Extended Coupon Features, including coupons it applies
  automatically — which is what makes a high-severity overlap possible at all.
* Support for YITH WooCommerce Points and Rewards: its generated reward coupons
  are kept out of the audit, where tens of thousands of them would bury the
  coupons you chose.
* Filters for other plugins to add columns, fill them, and shape the coupon query.
* Much faster on shops with a lot of coupons. The overview used to read every
  coupon's settings one at a time and weigh each coupon against every other by
  searching the list afresh; both are now done once. On a test shop of 500
  coupons and 300 products the screen went from 737 database queries to 27, and
  from roughly 3.7 seconds to 0.6.

= 0.1.0 =
* First release: coupon audit, overlap detection, pre-publish warnings and
  30-day gross margin.

== Upgrade Notice ==

= 0.4.0 =
Adds filtering to the coupon audit, by discount type and by expiry.

= 0.3.1 =
Compatibility only: tested against WordPress 7.1 and WooCommerce 11.

= 0.3.0 =
The margin window is now filterable. Nothing else changes for existing installs.

= 0.2.1 =
Fixes the basket column, and a warning about fixed discounts with no minimum
spend that never appeared.

= 0.2.0 =
Adds the coupon terms to the overview, names what each coupon applies to, and
reports fixed discounts that give away more than they ask for.

= 0.1.0 =
First release.
