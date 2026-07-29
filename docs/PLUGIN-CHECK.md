# Plugin Check, and the one finding that remains

Run against the built distribution, not the repository — the repository contains
a test harness that has no business inside a shipped plugin:

```bash
composer run dist
# copy build/coupon-audit-and-analytics-for-woocommerce into wp-content/plugins
wp plugin check coupon-audit-and-analytics-for-woocommerce
```

The directory must be named exactly as the slug will be. Plugin Check compares
the text domain and the trademark rules against the folder name, so checking a
copy called `…-dist` reports eighty-odd text-domain mismatches and a trademark
violation that do not exist.

## What the build removes

Tests, `bin/`, `docs/`, CI configuration, the PHPCS/PHPStan/PHPUnit
configuration, and the dotfiles. Without that, Plugin Check reports
`application_detected` and `hidden_files`, and it is right to: a shop does not
need this repository's test harness, and shipping it enlarges the attack surface
for nothing. `composer.json` does ship, because Plugin Check reasonably queries a
`vendor` directory that arrives without one.

## The remaining finding: `WordPress.Security.EscapeOutput.ExceptionNotEscaped`

Fourteen occurrences, all of the same shape:

```php
throw new InvalidArgumentException(
    sprintf( 'A coupon ID must be a positive integer, got %d.', $value )
);
```

**This is deliberate and will not be "fixed".** Two reasons.

Escaping it would be wrong on its own terms. `esc_html()` produces HTML entities;
an exception message goes to a log file or a stack trace, and encoding it there
turns `<` into `&lt;` in `debug.log` for no benefit to anyone. The sniff assumes
the message reaches a browser. These do not: nothing in this plugin catches one
of these exceptions and prints it, and WordPress does not display exception
messages to visitors with `WP_DEBUG` off.

And every one of them is thrown from the domain layer, where §5 forbids calling a
WordPress function at all. That rule is what keeps the unit suite free of a
bootstrap and running in half a second. Trading it away to satisfy a sniff about
output that never happens would be a poor bargain.

All of these exceptions signal a programming error — an ID that is not a
positive integer, two cost sources claiming one identifier, a service resolved
before it was registered. None of them can be triggered by a request.
