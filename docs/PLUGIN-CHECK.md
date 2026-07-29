# Plugin Check, and the one finding that remains

Run against the built distribution, not the repository — the repository contains
a test harness that has no business inside a shipped plugin:

```bash
composer run dist   # writes build/coupon-audit-and-analytics-for-woocommerce-<version>.zip
# copy build/coupon-audit-and-analytics-for-woocommerce into wp-content/plugins
wp plugin check coupon-audit-and-analytics-for-woocommerce
```

The zip is named for the version in the plugin header, and the build refuses to
run if that header and the readme's `Stable tag` disagree — WordPress serves
whatever `Stable tag` points at, so a mismatch publishes one version's code under
another version's number, silently.

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

## Exception messages carry no context

Plugin Check reports nothing at all, which took giving something up.

`WordPress.Security.EscapeOutput` treats anything reaching an exception
constructor as output, and it means anything: a `sprintf()`, a bare variable, a
class constant. Only two forms satisfy it — a literal string, or assembling the
exception into a variable and throwing that. The second changes nothing about
safety and exists purely to defeat the heuristic, so it was not used.

Exception messages are therefore fixed strings, and the values that used to be
in them are gone:

```php
// before
throw new InvalidArgumentException(
    sprintf( 'A coupon ID must be a positive integer, got %d.', $value )
);

// now
throw new InvalidArgumentException( 'A coupon ID must be a positive integer.' );
```

The container's two exceptions carry their own message in their constructor, so
the throw site passes no argument at all.

**This is a real loss and worth knowing about.** A container error no longer
names the service it could not resolve, and a circular dependency no longer
spells out the chain. Both are still one frame away in the stack trace — the
caller and its arguments are right there — but the message alone no longer tells
you. Two container tests used to assert exactly that detail and now assert only
the exception type; their docblocks say why.

The escaping guidance exists to stop unescaped values reaching a browser, and
none of these ever did: nothing here catches one of these exceptions and prints
it, and every one signals a programming error no request can trigger. Escaping
them would also have been wrong on its own terms, since `esc_html()` on a message
bound for `debug.log` just puts entities in the log. Given the choice between
arguing that in a review and giving up some diagnostic text, the text went.
