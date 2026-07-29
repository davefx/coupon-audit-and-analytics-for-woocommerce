# Performance

The inventory screen judges every coupon against every other one, so it reads
the whole inventory on every load. That is not negotiable — the dead-campaign
rule and overlap detection both need the full set before any single row can be
decided — which makes the cost of reading the inventory the cost of the screen.

Measured on a disposable shop of 300 products in 20 categories, half the coupons
fixed-sum and half percentage, a third product-restricted and a fifth
category-restricted.

| Coupons | Before | After |
| --- | --- | --- |
| 500 | 3670 ms, 737 queries | 599 ms, 27 queries |
| 2000 | not measured | 2400 ms, 57 queries |

Memory was never the problem: of the 150 MB the first measurement reported, 146
MB was WordPress and WooCommerce loading. Building the inventory of 500 coupons
costs about 7 MB.

## What the queries were

**One query per coupon, for its meta.** `WC_Coupon` reads through
`WC_Data_Store_WP::read_meta()`, which fetches one coupon at a time. Five hundred
coupons, five hundred round trips. `WC_Data` checks the object cache first, so
`WpCouponRepository` now fills that cache from a single query before building any
coupon.

The rows are stored in the shape `read_meta()` returns, real `meta_id`s
included. That is the part to be careful with: WooCommerce tells existing meta
from new by that ID, so meta cached without them would be re-inserted the next
time anything saved the coupon — and with a persistent object cache, long after
the request that seeded it. `update_meta_cache()` would have been the tidier
source but it discards the IDs, which is why there is a direct query here at all.
`WpCouponRepositoryTest` guards both halves: that listing costs the same for nine
coupons as for three, and that saving a coupon after listing does not duplicate
its meta.

**One query per coupon, for the cheapest thing it reaches.** Only fixed-sum
coupons need this — a percentage cannot exceed what it is applied to — but that
was still half of them. `ScopePricing` now fetches everything any coupon could
need up front: one query for the price of every named product, one search per
named category, one for the shop's cheapest. Each coupon's answer is then
arithmetic.

Those category searches are the one cost that still grows with the shop rather
than being flat, at roughly three queries per **distinct category named by a
fixed-sum coupon** — not per category in the shop. Two of those three come from
inside `wc_get_products` itself. The searches match on term ID rather than slug,
which removed two further queries each; both forms include products filed under a
category's children, so a coupon restricted to a parent category still reaches
everything it actually reaches.

## What the time was

**Dead-campaign detection was quadratic.** It asks whether every other code in a
coupon's campaign has expired, and it answered by walking the whole inventory
once per coupon. `OrphanDetector::reasons_for_all()` counts the campaigns once
into a `CampaignIndex` and judges each coupon against the tally. At 500 coupons
that pass went from 329 ms to under 25 ms; at 2000 it is still 22 ms, where the
old shape would have been several seconds.

`reasons()` still exists and still works one coupon at a time, and a test asserts
the two routes agree. They are the same rule, and the batch one exists only
because the other is too slow to call in a loop.

**What remains is WooCommerce.** At 2000 coupons the build is 2.4 s, of which 2.0
s is `WC_Coupon` construction — about a millisecond each, in PHP, across seven
queries. Reading the coupons ourselves from the meta already in hand would remove
it, and would also discard every `woocommerce_coupon_get_*` filter that other
plugins hook, along with the guarantee that a coupon means what WooCommerce says
it means. That trade has not been made.

## Where prices are not reported

`ScopePricing` declines to price a coupon that restricts by category *and*
excludes products. The excluded product might be the category's cheapest, and
what would be next cheapest is not knowable from what was prefetched. The finding
this feeds is advisory, so it says nothing rather than a number that might be
wrong.

## Reproducing

The benchmark shop is disposable and is not kept. It was a WordPress install
against a scratch MySQL container, seeded with the shape described above, driven
through `wp eval-file` — one script to build the inventory and report elapsed
time, queries and peak memory, another to group the queries by shape so the
repeated ones stand out. Grouping by shape is what made both problems obvious;
a total count only says the number is large.
