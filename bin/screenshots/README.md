# Regenerating the screenshots

`readme.txt` promises four screenshots, and they go stale: a changed column, a
reworded notice, a new finding. A stale screenshot is worse than none — it
advertises behaviour the plugin no longer has.

```bash
composer run screenshots
```

Three minutes, and the four PNGs in `.wordpress-org/` are rebuilt. **Look at them
before committing**: the script checks that they were written, not that they show
what you meant.

It touches no existing site. A disposable WordPress is installed against a
throwaway MySQL container, seeded with a shop that exercises every finding and all
three cost-coverage states, photographed, and then removed again — including when
it fails partway. The plugin it installs is the **built distribution**, not the
working tree: the pictures should show what a user installs.

Needs `docker`, `wp`, `node`, `npm` and a Chrome. Set `CHROME` if yours is not at
`/usr/bin/google-chrome`. `puppeteer-core` is installed with `--no-save`, because
this repository has neither a `package.json` nor a lock file and a screenshot run
is no reason to give it one; if the script installed it, the script removes it.

## What is in here

| File | |
|---|---|
| `regenerate.sh` | The whole thing. Owns the container, the demo shop and the server, and clears up after itself. |
| `seed.php` | Products, orders and nine coupons covering every status and finding. |
| `seed2.php` | The third cost-coverage state, added afterwards — see below. |
| `shoot.js` | Screenshots 1, 2 and 4: audit, margin, settings. |
| `shoot3.js` | Screenshot 3: the warnings on the coupon edit screen. |

`seed2.php` is separate because the third cost-coverage state — a coupon whose
orders carry no cost at all — needs an unrestricted coupon applied to a basket of
products that have no cost recorded. It is easier to add afterwards than to weave
into the first pass.

## Things that were learned the hard way

None of these announces itself, and each one looks like a different problem.

**The MySQL image answers a ping while it is still initialising.** WordPress then
connects to a server that closes the socket mid-greeting, which reports as "MySQL
server has gone away" and looks like anything except a container that is not ready
yet. Readiness is therefore a real query, not a ping.

**`wp server` is a wrapper around `php -S`.** Killing the pid you started leaves
the PHP process holding the port, so the next run cannot serve the site it just
built. The server is started with `setsid` and the whole process group is killed.

**The audit screen is captured wider than the others.** Findings is its last
column, and at the default width the labels are clipped off the right edge — the
one thing a reader of that screenshot is looking for.

**The coupon editor is shot with WordPress's own menu collapsed, not hidden.**
Hiding it widens the two-column layout past the viewport and clips the Publish box
away.

**`shoot3.js` finds its coupon by code.** It used to read an ID from a file that
nothing wrote, so that step existed only in whoever had done it last.

**Images are captured at a 2x device pixel ratio**, because the plugin directory
renders them on displays that would otherwise show blurred text.

## When they change

Screenshots 1, 2 and 4 are reproducible: an unchanged plugin gives byte-identical
files. Screenshot 3 always differs, because the coupon editor prints the moment
the demo coupon was published. A diff limited to `screenshot-3.png` means nothing
changed.

Twice now, regenerating these has found a real bug — the coupon terms columns in
0.2.0, and an absent spend limit read as zero in 0.2.1. Looking at the pictures is
part of the job, not a formality.
