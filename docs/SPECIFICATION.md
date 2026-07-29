# Coupon Audit and Analytics for WooCommerce
## Technical specification — architectural extract

Version 0.1 · July 2026 · Working document

---

### About this extract

This is the architectural half of the project's technical specification, published
alongside the code that cites it. Source comments reference these sections by
number (`§3.3`, `§10.4`, and so on), so **the original numbering is preserved and
the gaps are deliberate** — a missing §6 does not mean a missing section, it means
that section is not part of this extract.

Included here: §2, §3, §4, §5, §7, §10, §12, §14. Every cross-reference inside
them resolves within this document.

Omitted: the product and commercial sections — scope and positioning, the data
model, the free/premium split, licensing of the product itself, the build order,
and the register of open questions. They live in the full specification.

---

## 2. Platform requirements

| Component | Minimum version | Reason |
|---|---|---|
| PHP | 8.1 | Enums, readonly properties, `never` return type |
| WordPress | 6.4 | Reasonable compatibility baseline |
| WooCommerce | 10.3 | Version in which COGS entered core with a stable API |
| HPOS | Compatibility must be declared | De facto Marketplace requirement |

Explicit compatibility is declared for HPOS and for cart and checkout blocks, even
though the plugin does not touch the storefront.

---

## 3. Architectural principles

### 3.1 Object orientation

- One class, one responsibility. No grab-bag classes holding loose functions.
- Dependencies declared in the constructor, never resolved inside a method.
- Interfaces for anything with more than one possible implementation, or anything
  touching the outside world (database, options, network, time).
- Immutable domain objects (`readonly`) for calculated values.
- No global state outside the points declared in §3.3.

### 3.2 Test-driven development

Strict red → green → refactor cycle. No line of production code is written without
a failing test first. Operational detail in §10.

### 3.3 Singleton policy — and an honest warning

**The warning first, because it matters:** the singleton pattern and test-driven
development pull in opposite directions. A singleton is global state with good
manners: it survives between tests, cannot be replaced with a double, and forces
every test to clean up whatever the previous one left behind. A codebase littered
with `Class::get_instance()` is a codebase where TDD becomes slow and brittle, and
that friction eventually kills the testing discipline itself.

**The proposal, which honours the requirement where it makes sense:**

Singletons, exactly two, both at the plugin boundary:

1. **`Plugin`** — single entry point. This is the idiomatic WordPress pattern: the
   main file instantiates once and hooks the bootstrap. No business logic — it only
   boots the container and registers providers.
2. **`Container`** — service container. Dependency registration and resolution, with
   the ability to override any binding (essential for testing).

Everything else — repositories, services, calculators, adapters, controllers — are
ordinary classes that receive dependencies via constructor and are resolved through
the container. In practice they are instantiated once, because the container caches
them; the difference is that they remain **replaceable**, which is what TDD needs.

This yields the single point of access that was requested, without paying the price
of untestable code.

> **Implementation note.** Both singletons ship with a *public* constructor.
> `get_instance()` provides the single point of access the plugin boundary needs,
> while tests build their own instance and never touch the static one. That is what
> keeps the unit suite in the tens of milliseconds.

---

## 4. Directory structure

```
coupon-audit-and-analytics-for-woocommerce/
├── coupon-audit-and-analytics-for-woocommerce.php   # Header, guards, bootstrap
├── uninstall.php
├── composer.json
├── phpunit.xml.dist
├── src/
│   ├── Plugin.php                  # Singleton 1
│   ├── Container/
│   │   ├── Container.php           # Singleton 2
│   │   ├── ContainerInterface.php
│   │   └── ServiceProviderInterface.php
│   ├── Providers/
│   │   ├── CoreServiceProvider.php
│   │   ├── AdminServiceProvider.php
│   │   └── RestServiceProvider.php
│   ├── Domain/
│   │   ├── Coupon/
│   │   │   ├── CouponId.php
│   │   │   ├── CouponSnapshot.php
│   │   │   ├── CouponScope.php
│   │   │   ├── CouponStatus.php        # enum
│   │   │   └── OrphanReason.php        # enum
│   │   ├── Overlap/
│   │   │   ├── OverlapDetector.php
│   │   │   ├── Overlap.php
│   │   │   └── OverlapSeverity.php     # enum
│   │   ├── Profit/
│   │   │   ├── MarginCalculator.php
│   │   │   ├── CouponMargin.php
│   │   │   ├── Money.php
│   │   │   └── CostCoverage.php
│   │   └── Clock/
│   │       ├── ClockInterface.php
│   │       └── SystemClock.php
│   ├── Cost/
│   │   ├── CostSourceInterface.php
│   │   ├── CostSourceRegistry.php
│   │   ├── NativeCogsSource.php
│   │   ├── SkyvergeCogsSource.php
│   │   └── WpFactoryCogsSource.php
│   ├── Repository/
│   │   ├── CouponRepositoryInterface.php
│   │   ├── WpCouponRepository.php
│   │   ├── OrderStatsRepositoryInterface.php
│   │   └── WcOrderStatsRepository.php
│   ├── Service/
│   │   ├── InventoryService.php
│   │   ├── MarginService.php
│   │   └── PrePublishValidator.php
│   ├── Admin/
│   │   ├── MenuRegistrar.php
│   │   ├── InventoryPage.php
│   │   ├── MarginPage.php
│   │   ├── CouponEditorNotices.php
│   │   └── AssetLoader.php
│   ├── Rest/
│   │   ├── InventoryController.php
│   │   └── MarginController.php
│   ├── Licensing/
│   │   ├── FeatureGateInterface.php
│   │   ├── LocalFeatureGate.php
│   │   ├── FreemiusFeatureGate.php
│   │   └── Feature.php                 # enum
│   └── Install/
│       ├── Activator.php
│       └── SchemaMigrator.php
├── assets/
└── tests/
    ├── Unit/
    ├── Integration/
    ├── Fixtures/
    └── bootstrap.php
```

> **Implementation note.** `src/Support/` was added to the layout above. It carries
> `PluginContext` — the version, paths and slug that only the main plugin file
> knows — so that nothing below the boundary calls `plugin_dir_url()` or reads a
> global constant.

---

## 5. Layers and responsibilities

**Domain** — Pure logic. No WordPress, no database, no global functions. Takes data
structures, returns value objects. This is where 80% of unit tests live and where
TDD is fast and comfortable.

**Repositories** — The only layer that talks to `$wpdb` and the WooCommerce APIs.
Each behind an interface, so the domain never learns where data came from.

**Cost adapters** — Interchangeable strategies, one per COGS source. Detail in §7.

**Services** — Orchestration. They coordinate repositories and domain objects; they
calculate nothing themselves.

**Admin and REST** — Entry boundary. No logic: validate the request, delegate to a
service, format the response.

**Licensing** — Feature checks behind a dedicated interface. Business code asks
`$gate->allows( Feature::HISTORY )` and never calls a licensing SDK directly. This
allows both paths to be tested without loading any SDK.

---

## 7. Cost adapters

```php
interface CostSourceInterface {
    public function is_available(): bool;
    public function get_identifier(): string;
    public function get_label(): string;
    public function get_line_cost( int $order_id, int $line_item_id ): ?Money;
    public function get_priority(): int;
}
```

`CostSourceRegistry` walks available adapters in priority order and returns the
first that yields a value. If a store has two cost systems installed, the user
chooses which one wins — WooCommerce explicitly warns that its native feature and
third-party extensions coexist showing duplicate totals without establishing
precedence.

A new adapter is added without touching anything else: register it in the container
and the registry picks it up. This is the plugin's most likely extension point and
should stay cheap.

---

## 10. Testing strategy

### 10.1 Pyramid

| Level | Share | Tooling | Target speed |
|---|---|---|---|
| Unit (pure domain) | ~70% | PHPUnit without WordPress | Full suite < 2 s |
| Integration (repositories, adapters) | ~25% | PHPUnit on wp-env with a real database | < 60 s |
| End-to-end (admin flows) | ~5% | Playwright | CI only |

The speed of the first tier is not a vanity target: if the red-green-refactor cycle
takes more than a few seconds, it stops being practised. The layering in §5 exists
largely to protect that speed.

### 10.2 Tooling

- **PHPUnit** with Brain Monkey to stub WordPress functions at the unit level.
- **wp-env** for the integration environment.
- **PHPStan** at level 8, with WooCommerce stubs.
- **PHPCS** with WordPress standards plus the additional Marketplace ruleset.
- **CI** on every push: unit, integration, static analysis, coding standards. Main
  branch blocked on any failure.

### 10.3 Discipline

1. Write the test expressing the desired behaviour. It must fail, and it must fail
   **for the right reason**.
2. Write the minimum code that makes it pass. Ugly is explicitly permitted.
3. Refactor with tests green.
4. Never move to the next test with anything red.

Bugs enter through the same door: first a test reproducing it, then the fix. That
way the bug never comes back.

### 10.4 Mandatory test seams

Each of these is always injected via interface, without exception:

- **Time** → `ClockInterface`
- **Database** → repository interfaces
- **Licensing** → `FeatureGateInterface`
- **Cost sources** → `CostSourceInterface`
- **Options** → dedicated wrapper, never `get_option()` directly

When something proves hard to test, the right answer is almost never a cleverer
test: it is that the design is asking for a seam that does not exist yet.

### 10.5 First cycle example

```php
// tests/Unit/Domain/Coupon/CouponStatusTest.php
public function test_coupon_with_past_expiry_is_expired(): void {
    $clock  = new FrozenClock( new DateTimeImmutable( '2026-07-28' ) );
    $coupon = CouponSnapshotBuilder::make()
        ->published()
        ->expiring( '2026-07-01' )
        ->build();

    $this->assertSame(
        CouponStatus::EXPIRED,
        ( new StatusResolver( $clock ) )->resolve( $coupon )
    );
}
```

Note that the test touches no WordPress, no database, and does not depend on the
real execution date. Nearly all tests should look like this.

---

## 12. Naming conventions

### Internal prefix: `dfxcaaw`

Seven letters — *DFX Coupon Audit and Analytics for WooCommerce* — comfortably above
the repository minimum and unlikely to collide. Applied to:

| Element | Form | Example |
|---|---|---|
| Options | `dfxcaaw_` | `dfxcaaw_settings`, `dfxcaaw_db_version` |
| Custom hooks | `dfxcaaw_` | `dfxcaaw_cost_sources`, `dfxcaaw_overlap_detected` |
| Transients | `dfxcaaw_` | `dfxcaaw_overlap_cache` |
| Global functions | `dfxcaaw_` | (avoid; only where unavoidable) |
| Script and style handles | `dfxcaaw-` | `dfxcaaw-inventory-page` |
| Custom meta | `_dfxcaaw_` | `_dfxcaaw_campaign_tag` |
| Aggregates table | `{$wpdb->prefix}dfxcaaw_` | `wp_dfxcaaw_coupon_stats` |
| Action Scheduler hooks | `dfxcaaw_` | `dfxcaaw_aggregate_orders` |
| Capabilities, if added | `dfxcaaw_` | `dfxcaaw_view_margin` |

### PHP namespace

`DFX\CouponAAW`, with sub-namespaces following §4.

### Text domain — tied to the slug

The text domain **cannot** be an internal identifier. WordPress.org requires it to
match the plugin slug exactly in order to serve language packs. It is therefore the
approved slug — `coupon-audit-and-analytics-for-woocommerce` — and not `dfxcaaw`.

### What is tied to the slug and what is not

A deliberate split, so that a last-minute rename costs an afternoon rather than a
week:

| Tied to the slug (changes on rename) | Independent (never touched) |
|---|---|
| Text domain | The `dfxcaaw_` prefix in all its forms |
| Plugin directory name | `DFX\CouponAAW` namespace |
| Main file name | Aggregates table name |
| `Plugin Name` and `Text Domain` headers | Option, hook and transient names |

Practical consequence: the public name appears only in the header, the file name and
translation calls. The rest of the codebase is indifferent to what the product ends
up being called in the storefront.

---

## 14. Security and compliance

- `manage_woocommerce` capability check on every admin and REST entry point.
- Nonces on every action with side effects.
- Queries always via `$wpdb->prepare()`. Table names never concatenated from user
  input.
- Escaping at the point of output, without exception.
- All strings translatable using the plugin text domain.
- No external requests in v1.
- `uninstall.php` removes tables and options only if the user has explicitly opted
  in via settings.
