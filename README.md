# WooCommerce ShipStation Integration WR

A reusable WooCommerce plugin for pulling available inventory from ShipStation into WooCommerce on a controlled schedule, with dry-run reporting, exact SKU matching, zero-stock safeguards, recent-order protection, and sync visibility.

> **Project status:** In development. ShipStation API discovery and test-account validation are complete. Plugin implementation is planned in phased milestones, beginning with safe read-only discovery and dry-run functionality.

---

## Why This Plugin Exists

This plugin is being developed to solve a common operational problem in WooCommerce stores that use ShipStation as the source of truth for inventory across one or more sales channels.

In the initial client scenario, the current inventory synchronization process pushes frequent inventory updates from ShipStation into WooCommerce. Those inbound requests can create unnecessary server load, exhaust PHP workers, and negatively affect site performance.

This plugin replaces the inventory-update direction with a controlled pull model:

```text
ShipStation Internal Inventory
        ↓
ShipStation Inventory API V2
        ↓
WooCommerce ShipStation Integration WR
        ↓
WooCommerce product and variation stock
```

The plugin is intended to allow each store to choose when inventory synchronization runs, review changes before enabling stock writes, and avoid unsafe assumptions when ShipStation does not return zero-stock SKUs.

---

## Plugin Identity

| Item | Value |
|---|---|
| Plugin name | WooCommerce ShipStation Integration WR |
| Plugin folder / slug | `woocommerce-shipstation-integration-wr` |
| Main plugin file | `woocommerce-shipstation-integration-wr.php` |
| Text domain | `woocommerce-shipstation-integration-wr` |
| PHP namespace | `WebReadyNow\WooCommerceShipStationIntegration` |
| Hook / option prefix | `wsi_wr_` |
| Suggested API key constant | `WSI_WR_SHIPSTATION_API_KEY` |
| Author | WebReadyNow |

---

## Project Status

### Confirmed and Tested

The following discovery work has already been completed using a dedicated ShipStation test account:

- ShipStation internal inventory was enabled.
- A test inventory warehouse was created.
- Test products and inventory quantities were imported.
- A ShipStation V2 API key was generated.
- A successful authenticated read request was made to:

```http
GET https://api.shipstation.com/v2/inventory
```

- The API returned inventory records containing:
  - `sku`
  - `on_hand`
  - `available`
  - `average_cost`
- The API response returned pagination metadata including:
  - `total`
  - `page`
  - `pages`
  - `links`
- The correct WooCommerce target quantity was confirmed to be ShipStation's `available` value.

### Critical Zero-Stock Discovery

Live API testing confirmed an important behavior:

```text
ShipStation quantity greater than 0 → SKU appears in GET /v2/inventory
ShipStation quantity equal to 0   → SKU disappears from GET /v2/inventory
```

Test performed:

1. `WRN-SS-003` was imported with quantity `0`.
2. It did not appear in the bulk inventory endpoint.
3. A direct request for `?sku=WRN-SS-003` also returned no inventory record.
4. Quantity was changed to `1`; the SKU then appeared with `available: 1`.
5. Quantity was changed back to `0`; the SKU disappeared again.

Because of this behavior, this plugin must never assume that every WooCommerce SKU missing from the ShipStation response should automatically be set to zero.

---

## Phase 1 Scope: Inventory Pull Only

The first production-ready version will focus only on safely pulling inventory from ShipStation into WooCommerce.

### Included in Phase 1

- Secure ShipStation V2 API configuration.
- Read-only inventory retrieval from ShipStation.
- WooCommerce matching by exact SKU.
- Simple product and variation support.
- Discovery reports and SKU alignment checks.
- Dry-run inventory synchronization.
- Manual inventory synchronization after validation.
- Scheduled inventory synchronization using WooCommerce Action Scheduler.
- Sync logs and history.
- Tracked-SKU logic for zero-stock safety.
- Recent WooCommerce order protection for unsafe stock increases.
- CSV reporting and onboarding tools.

### Explicitly Excluded from Phase 1

- Pushing WooCommerce orders into ShipStation.
- Replacing an existing ShipStation selling-channel order connection.
- Automatically changing ShipStation selling-channel configuration.
- Automatically disabling ShipStation Inventory Sync.
- Creating or updating products inside ShipStation through the API.
- Writing inventory quantities into ShipStation.
- Labels, carriers, tracking, fulfillment, rates, or shipping logic.
- Price synchronization.

---

## How the Existing Selling Channel Fits In

This plugin is designed primarily for stores that already have WooCommerce connected to ShipStation as a selling channel.

The expected implementation is:

```text
WooCommerce customer order
        ↓
Existing ShipStation selling-channel connection imports the order
        ↓
ShipStation allocates/commits inventory
        ↓
This plugin reads ShipStation available inventory
        ↓
This plugin updates WooCommerce stock on a controlled schedule
```

| Component | Responsibility | Expected State |
|---|---|---|
| Existing WooCommerce selling channel in ShipStation | Imports WooCommerce orders into ShipStation | Keep active for Phase 1 |
| ShipStation Internal Inventory | Remains inventory source of truth | Keep active |
| This plugin | Pulls `available` quantities into WooCommerce | Enable after validation |
| ShipStation Inventory Sync targeting WooCommerce | Pushes stock directly into WooCommerce | Disable operationally before live plugin sync |

The plugin must not modify the existing selling-channel connection automatically. Order flow must be verified separately before production activation.

---

## Planned Features

### Connection and Settings

The plugin will provide settings under WooCommerce administration for:

- Enable or disable inventory pull synchronization.
- Enter a ShipStation V2 API key securely.
- Supply credentials through `wp-config.php` using:

```php
define( 'WSI_WR_SHIPSTATION_API_KEY', 'your-api-key-here' );
```

- Test the API connection.
- Fetch an inventory sample.
- Enable dry-run mode by default.
- Configure log retention.
- Configure API timeout and batch/page size where applicable.

No API key will be exposed after it is saved, included in reports, or written to logs.

### Exact SKU Matching

The plugin will:

- Match ShipStation inventory records to WooCommerce products by exact SKU.
- Support simple products.
- Support individual product variations.
- Report unmatched ShipStation SKUs.
- Report WooCommerce products with stock management disabled.
- Refuse ambiguous or unsafe matches.
- Never create WooCommerce products automatically.

### Dry-Run Reporting

Before allowing stock changes, the plugin will provide a dry-run report identifying:

- Proposed stock increases.
- Proposed stock decreases.
- Products already synchronized.
- ShipStation records with no WooCommerce match.
- Matching products with WooCommerce stock management disabled.
- Potential zero-stock cases.
- Stock increases skipped due to recent WooCommerce orders.
- Warnings and API errors.

### Manual Inventory Sync

After dry-run validation, an administrator will be able to run a manual sync using the same synchronization service later used by scheduled actions.

### Scheduled Inventory Sync

The planned default schedule is three times per day:

| Sync Time | Purpose |
|---|---|
| 7:00 AM | Morning inventory update |
| 12:00 PM | Midday inventory update |
| 6:00 PM | End-of-day inventory update |

Scheduling will use WooCommerce Action Scheduler so inventory jobs can be reviewed in:

```text
WooCommerce → Status → Scheduled Actions
```

Each store will be able to customize these execution times.

### Sync History and Logging

Planned reporting includes:

- Sync run history.
- Run type: connection test, sample fetch, dry run, manual live, or scheduled live.
- API success/failure status.
- Pagination-complete status.
- Number of inventory records returned.
- Number of products matched, updated, unchanged, skipped, or flagged.
- CSV export of detailed results.
- Configurable log retention.

Sensitive credentials and customer personally identifiable information will not be stored in sync logs.

---

## Zero-Stock Safeguard

Because a ShipStation SKU can disappear from the API response when its stock reaches zero, the plugin requires a tracked-SKU strategy.

### Planned Tracked SKU Registry

The plugin will maintain records for ShipStation SKUs previously confirmed or manually approved for synchronization, including:

- SKU.
- Matched WooCommerce product or variation.
- Last returned `available` quantity.
- Last seen timestamp.
- Missing count across complete successful sync runs.
- Mapping and safety status.

### Missing SKU Modes

| Setting | Behavior |
|---|---|
| `report_only` | Default. Report missing tracked SKUs without changing WooCommerce stock. |
| `zero_after_two_confirmed_absent_syncs` | Optionally set a previously confirmed SKU to zero after two consecutive complete successful API syncs where it remains absent. |
| `ignore` | Ignore missing tracked SKU evaluation. |

### Safety Rules

Missing-SKU zero handling must not run when:

- The API request fails.
- Authentication fails.
- Pagination does not complete.
- The response is malformed.
- Filters or inventory context changed.
- The SKU was never previously confirmed or manually approved.

Default behavior is always reporting only.

---

## Recent-Order Stock Increase Protection

There is a timing risk when an order reduces WooCommerce stock before ShipStation has imported the order and reduced its `available` quantity.

Example:

```text
WooCommerce and ShipStation begin with stock 10.
Customer purchases 2 in WooCommerce; WooCommerce becomes 8.
ShipStation has not imported the order yet and still reports 10.
A sync runs too early.
Without protection, WooCommerce would incorrectly be restored to 10.
```

To reduce this risk, the plugin will include:

```text
Recent Order Stock Increase Protection Window
Default: 60 minutes
```

Planned behavior:

- A ShipStation stock decrease may be applied when safely matched.
- A stock increase is checked against recent WooCommerce orders containing that product or variation.
- If a relevant recent order exists within the protection window, the increase is skipped and reported.
- Order lookups will use WooCommerce APIs compatible with HPOS.

This protection reduces timing risk but does not replace the need to test the real store's WooCommerce-to-ShipStation order allocation timing before live activation.

---

## Store Onboarding Scenarios

### Store Already Has Products and Inventory in ShipStation

For an already-connected selling channel:

1. Keep the selling-channel order connection active.
2. Confirm ShipStation internal inventory is configured.
3. Confirm API inventory records contain `available` quantities.
4. Run the plugin SKU Alignment Report.
5. Resolve mismatches and stock-management warnings.
6. Run dry-run sync.
7. Confirm order import/allocation timing.
8. Disable conflicting ShipStation Inventory Sync targeting WooCommerce.
9. Enable controlled plugin synchronization.

### Store Has No Products in ShipStation

A store without ShipStation product/inventory records cannot immediately use ShipStation as its inventory source of truth.

Planned onboarding tools will support:

- WooCommerce catalog/SKU review export.
- Validation of missing and duplicate SKUs.
- CSV preparation for manual ShipStation product import.
- Tracked-SKU bootstrap import for approved mappings, including intentionally zero-stock products.
- Dry-run validation after ShipStation inventory is initialized.

Phase 1 will not automatically create ShipStation products or write initial inventory into ShipStation.

---

## Test Dataset

The initial development test account uses the following ShipStation inventory records:

| SKU | ShipStation Available | Test Purpose |
|---|---:|---|
| `WRN-SS-001` | 25 | WooCommerce stock increase |
| `WRN-SS-002` | 7 | WooCommerce stock decrease |
| `WRN-SS-003` | 0 / absent from API | Zero-stock omission behavior |
| `WRN-SS-VAR-001` | 12 | Variation matching |
| `WRN-SS-NOSTOCK` | 4 | Manage Stock disabled case |
| `WRN-SS-NOMATCH` | 15 | ShipStation-only/unmatched SKU case |

Expected WooCommerce staging products:

| SKU | Initial WooCommerce Stock | Expected Dry-Run Outcome |
|---|---:|---|
| `WRN-SS-001` | 5 | Propose increase to 25 |
| `WRN-SS-002` | 20 | Propose decrease to 7 |
| `WRN-SS-003` | 8 | Report zero-stock/tracking case; do not infer blindly |
| `WRN-SS-VAR-001` | 3 | Propose variation increase to 12 |
| `WRN-SS-NOSTOCK` | Not managed | Skip and report |
| `WRN-SS-NOMATCH` | Not created in WooCommerce | Report unmatched and skip |

---

## Planned Technical Architecture

The implementation is expected to use modular services such as:

| Component | Responsibility |
|---|---|
| Plugin bootstrap | Loading, compatibility declarations, dependency checks |
| Settings service | Admin configuration and secure credential handling |
| ShipStation API client | Read-only V2 inventory requests and pagination |
| Product matcher | Exact SKU matching for products and variations |
| Tracked SKU repository | Zero-stock state and approved mapping persistence |
| Inventory sync service | Dry-run/manual/scheduled stock evaluation |
| Recent-order protection service | Prevent unsafe stock increases |
| Action Scheduler integration | Traceable recurring synchronization |
| Reporting service | Run history, details, warnings, CSV exports |
| Admin UI | Settings, reports, discovery tools, tracked SKU management |

Planned custom database tables:

| Table Suffix | Purpose |
|---|---|
| `wsi_wr_sync_runs` | Summary row per inventory/test run |
| `wsi_wr_sync_items` | Per-SKU report and diagnostic detail |
| `wsi_wr_tracked_skus` | Persistent mapping and zero-stock tracking state |

Final architecture and schema are subject to Phase 0 review before functional implementation begins.

---

## Security Requirements

The plugin will be built with the following security requirements:

- ShipStation API requests remain server-side.
- API key never appears in source code, rendered output, exports, or logs.
- API key may be provided through a masked admin setting or `wp-config.php` constant.
- Admin actions require capability checks and nonces.
- Settings and CSV input are sanitized and validated.
- Admin output is escaped.
- Stock updates use WooCommerce-supported APIs, not direct `_stock` meta updates.
- Recent order checks use HPOS-compatible WooCommerce APIs.
- The plugin does not store customer personal information for synchronization reporting.
- The plugin performs no ShipStation write operations in Phase 1.

---

## Requirements

Planned minimum requirements will be finalized during implementation. Expected dependencies include:

- WordPress installation.
- WooCommerce active.
- WooCommerce Action Scheduler availability through WooCommerce.
- ShipStation account with internal inventory configured.
- ShipStation Inventory API access.
- ShipStation V2 API key.
- Products with consistent SKU values across systems.

---

## Development Setup

The plugin directory is:

```text
wp-content/plugins/woocommerce-shipstation-integration-wr
```

Start Claude Code from inside the plugin directory:

```powershell
cd C:\wamp64\www\<your-site-folder>\wp-content\plugins\woocommerce-shipstation-integration-wr
claude
```

Do not provide the real API key to source code or AI coding prompts. Configure it in the WordPress settings screen once implemented, or locally through a secure `wp-config.php` constant.

---

## Implementation Roadmap

### Phase 0 — Architecture and Documentation

- Create project memory/documentation files.
- Document confirmed API findings.
- Define architecture and database schema.
- Record unresolved risks and implementation decisions.

### Phase 1 — Plugin Scaffold and Secure Settings

- Create installable plugin structure.
- Add WooCommerce dependency checking.
- Declare HPOS compatibility.
- Add settings interface and secure key configuration.

### Phase 2 — Read-Only API Discovery

- API connection testing.
- Inventory sample retrieval.
- Pagination handling.
- Safe API diagnostics.

### Phase 3 — SKU Matching and Tracked Registry

- Exact SKU product/variation matching.
- SKU alignment reports.
- CSV exports.
- Persistent tracked-SKU state.

### Phase 4 — Dry-Run Synchronization

- Proposed stock-change reports.
- Recent-order protection evaluation.
- Missing tracked-SKU reporting.
- No live inventory writes.

### Phase 5 — Manual Staging Stock Updates

- Live stock writes through WooCommerce APIs.
- Manual staging-only validation.
- Keep missing SKU policy at report-only initially.

### Phase 6 — Scheduled Synchronization

- Three daily Action Scheduler jobs.
- Schedule settings and duplicate prevention.
- Production cron reliability documentation.

### Phase 7 — Production Readiness

- Deployment checklist.
- Discovery checklist.
- Dry-run review checklist.
- Activation and rollback plans.

### Future Phase — Direct WooCommerce Order Push Research

A future version may investigate sending WooCommerce orders directly into ShipStation through an appropriate API rather than relying on selling-channel imports. This is explicitly excluded from Phase 1 and requires dedicated research into API access, allocation timing, duplicate-order prevention, failure handling, and selling-channel configuration.

---

## Non-Goals

This plugin is not intended, in its initial release, to:

- Replace full ShipStation fulfillment workflows.
- Purchase labels.
- Select carriers or rates.
- Manage tracking notifications.
- Send orders to ShipStation.
- Modify ShipStation inventory.
- Automatically configure ShipStation selling channels.
- Function as an ERP or omnichannel inventory platform.

---

## Deployment Prerequisites for a Live Store

Before enabling stock writes on any production store:

- Confirm ShipStation Inventory API access.
- Confirm returned `available` values.
- Confirm API behavior for zero-stock SKUs in that account.
- Confirm SKU alignment with WooCommerce.
- Confirm WooCommerce orders continue reaching ShipStation.
- Confirm ShipStation allocation changes available inventory.
- Measure order-to-allocation timing.
- Configure the recent-order protection window.
- Review a complete production dry-run report.
- Disable any conflicting ShipStation Inventory Sync push targeting WooCommerce without breaking order import.
- Enable live sync only after validation.

---

## License

License selection has not yet been finalized. Add a license before public distribution.

---

## Author

Developed by **WebReadyNow** for controlled, safer, and more observable ShipStation inventory synchronization in WooCommerce.
