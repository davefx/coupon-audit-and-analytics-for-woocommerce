# Working on this project

**This repository is published output, not where the plugin is written.** It
holds the plugin exactly as WordPress.org serves it: runtime dependencies only,
no test harness, no build tooling, no development configuration. Every release
replaces it wholesale.

So an edit made here is not a contribution — it is undone by the next release.
Only four things survive one, because they are maintained here rather than
generated:

| | |
|---|---|
| `.git/` | the history |
| `.github/` | the deploy workflow and the repository's README |
| `AGENTS.md` | this file |
| `CLAUDE.md` | which only points at this file |

Anything else you change here is gone at the next release, silently and without
a merge conflict to warn you. Fix the plugin where the plugin is developed.

## What is in here

| | |
|---|---|
| `coupon-audit-and-analytics-for-woocommerce.php` | the plugin header and bootstrap |
| `src/` | the plugin |
| `vendor/` | runtime dependencies, committed on purpose — this is a built tree |
| `freemius/` | the update and licensing SDK |
| `assets/` | the admin CSS and JS the plugin loads |
| `.wordpress-org/` | the directory listing's banner and icon, which are not part of what anybody downloads |
| `readme.txt` | what the directory page shows |
| `*.po` | translations pulled in at build time |

`.gitignore` is deliberately absent. In a tree that is meant to carry its own
`vendor/`, it would only be an invitation to leave something out.

## Reading it

The architecture is described in the specification, and source comments cite it
by section number (`§3.3`, `§5`, `§10.4`). That document does not ship in the
build, so those citations point outside this repository.

Two conventions explain most of what you will see. Everything user-facing is
prefixed `dfxcaaw_` — options, hooks, transients, tables — and custom meta with
`_dfxcaaw_`; the namespace is `DFX\CouponAAW` and the text domain is the slug.
And nothing under `src/Domain/` may call a WordPress function, touch `$wpdb` or
use a WooCommerce class: that layer is pure logic over value objects, which is
why it can be tested in milliseconds.

## Publishing

**Pushing a version tag publishes the plugin.** `.github/workflows/deploy.yml`
runs on a pushed `0.0.0`-shaped tag and nothing else — not a push to a branch,
not a GitHub release — and sends trunk, the tag and the listing assets to
WordPress.org. Tags are the bare version number, `0.1.0` and not `v0.1.0`, so a
git tag and the wp.org SVN tag for one release are spelled the same way.

**That workflow is switched off.** It is disabled rather than deleted so it can
be switched back on; until then a pushed tag publishes nothing by itself, and
releases are published from a workstation instead.

Before it publishes, the workflow checks that the tag, the plugin header and the
readme's `Stable tag` all say the same number — a release where they disagree
publishes one version's code under another's.

**The WordPress.org username is `DaveFX`, and the casing is load-bearing.** The
profile URL says `profiles.wordpress.org/davefx/` because those URLs are
lowercased slugs; the account is not. Authentication accepts either, so the wrong
case gets all the way past the login and is then refused by the pre-commit hook
with `Access denied: user 'davefx' cannot modify:` followed by every path in the
commit. That reads exactly like an account without commit rights, and it is not:
it is a typo. The real spelling is in the author column of `svn log` on any
repository the account has committed to.

## Nothing may be locked

There is no licensing layer, and none may be added. The plugin once carried a
`FeatureGateInterface` that answered "no" to `FULL_HISTORY` until a licence said
otherwise, capping the margin screen at thirty days while the code could do a
year. WordPress.org calls that locked functionality and does not permit it,
however cleanly it is abstracted — the pre-review pended the submission over it.

So: no gate, no `Feature` enum naming things a shop cannot have, and no string
anywhere that frames what is installed as a limited version of itself. A constant
named `FREE_WINDOW_DAYS` is the sort of thing that gets a submission pended, and
"intentionally restricting included functionality" is on the approval email's
list of things that get a plugin permanently removed.

Where behaviour should be changeable, use a filter. Thirty days is the margin
screen's *default*, and `dfxcaaw_margin_window_days` changes it, applied in
`AdminServiceProvider` so that `MarginService` keeps no opinion about WordPress;
a window under a day is refused, because a filter is allowed to be wrong without
taking the screen down. Thirty days is genuinely what this plugin does, not a
crippled version of what it could do.

That filter has deliberately no readme entry — the readme describes what the
screens do, not every hook there is. It is public and callable from any
`functions.php` all the same.
