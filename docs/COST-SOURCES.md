# Cost of goods: where stores actually keep it

Verified against plugin source, July 2026. This closes §16's first open question.

Every key below was read out of the relevant plugin's own code. Nothing here
comes from documentation or recollection, because an adapter that reads the
wrong key reports a confident, wrong margin — which §6.3 is explicit is worse
than reporting nothing at all.

## The landscape

| Source | Installs | Product cost | Per-line cost at order time | Verified from |
|---|---|---|---|---|
| **WooCommerce native** (10.3+) | core, **off by default** | `_cogs_total_value` | `_cogs_value` | WooCommerce 10.7 source |
| **Booster** (`woocommerce-jetpack`) | 30,000 | `_wcj_purchase_price` | — none — | plugin source |
| **WPFactory Cost of Goods** (`cost-of-goods-for-woocommerce`) | 9,000 | `_alg_wc_cog_cost` | `_alg_wc_cog_item_cost` | plugin source |
| **WooCommerce Cost of Goods** (paid, GoDaddy/SkyVerge) | unknown | `_wc_cog_cost` | **unverified** | two third-party import tools |
| Ni Cost of Goods | 200 | `_ni_cost_goods` | — none — | plugin source |
| `cost-of-goods` | 300 | `cost_of_goods` | — none — | plugin source |

## Native COGS: use the API, not the key

WooCommerce exposes a stable public API, so `NativeCogsSource` never needs to
touch a meta key:

```php
FeaturesUtil::feature_is_enabled( 'cost_of_goods_sold' );  // availability
$order_item->has_cogs();                                   // is there a cost?
$order_item->get_cogs_value();                             // float
$product->get_cogs_value();                                // ?float
```

**Native COGS is `enabled_by_default => false`.** Confirmed in
`CostOfGoodsSoldController::add_feature_definition()`, and confirmed disabled on
a real WooCommerce 10.7 install. This is the single most important fact in this
document: a plugin that supported only native COGS would report "no cost data"
in the overwhelming majority of stores, including every store that has been
tracking cost for years through one of the plugins above. That is the reasoning
behind shipping several adapters in v1 rather than one.

## The paid extension: what is and is not known

`_wc_cog_cost` is confirmed as the product-level key of the paid *WooCommerce
Cost of Goods* extension, from two independent sources that read it in order to
import from it:

- WPFactory's import tool defaults its source key to `_wc_cog_cost`.
- Booster's import screen, labelled "WooCommerce Cost of Goods (source)", reads
  `_wc_cog_cost`.

Its **per-line-item key is not known**. An earlier count appeared to find
`_wc_cog_item_cost` eleven times, which was a substring artefact:
`_alg_wc_cog_item_cost` contains it. Matching on the quoted literal returns zero
occurrences anywhere. The extension's own source is not publicly available, so
the honest position is that this adapter reads product cost only until the key
can be verified against the extension itself.

## What this means for coverage

Only **native COGS** and **WPFactory** record the cost of a line at the moment
the order was placed. Every other source stores a product's *current* cost.

Applying today's cost to a two-year-old order is not the same measurement, and
§6.3 exists precisely to stop this plugin presenting a number as more reliable
than it is. A product-level source is genuinely useful — it is far better than
nothing, and for a store whose costs are stable it is close to exact — but a
margin built from it is an estimate, and must be labelled as one.

## One source per store, never a blend

A store uses one method to manage cost of goods. The plugin reads whatever the
database happens to hold, and its only hard obligation is that a single report
never mixes values from two different sources.

That rules out the per-line fallback §7 describes — walking the adapters in
priority order and taking the first that yields a value *for each line* would
happily read WPFactory's cost for one line and Booster's for the next, producing
a margin that is a blend of two bookkeeping systems and reconciles with neither.

Instead the registry resolves exactly **one active source** for the whole
report:

1. the source the user chose, if it is installed and available; otherwise
2. the highest-priority available source; otherwise
3. none, and no margin is shown.

A line with no cost in the active source is missing cost — it is never filled in
from a different plugin. That is what makes the coverage figure in §6.3 mean
something: it measures one bookkeeping system's completeness, not the union of
several.

The active source is recorded alongside the figures, so a report can say which
system produced it, and so a store that switches plugins can see why its numbers
changed.

## Cost at the time of sale, or cost today

Only native COGS and WPFactory record what a line cost when the order was placed.
The others store the product's current cost, so a margin built from them applies
today's cost to an old order.

This is a property of the source rather than of the store, so each adapter
declares it. A margin computed from a source that does not record cost as of the
sale is an estimate, and the screen that shows it says so.
