# WooCommerce ShipStation Integration WR

A WooCommerce plugin for pulling available inventory from ShipStation into WooCommerce on a controlled schedule, with dry-run reporting, exact SKU matching, zero-stock safeguards, recent-order protection, and sync visibility.

> **Project status:** Phases 0–5 complete. SKU matching, alignment reporting, dry-run sync, and manual live stock updates are implemented and staging-validated. Scheduled synchronization (Phase 6) is next.

---

## Why This Plugin Exists

This plugin solves a common operational problem in WooCommerce stores that use ShipStation as the source of truth for inventory across one or more sales channels.

In the initial client scenario, the existing inventory synchronization process pushes frequent inventory updates from ShipStation into WooCommerce. Those inbound requests can create unnecessary server load, exhaust PHP workers, and negatively affect site performance.

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

The plugin allows each store to choose when inventory synchronization runs, review changes before enabling stock writes, and avoid unsafe assumptions when ShipStation does not return zero-stock SKUs.

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
| API key constant | `WSI_WR_SHIPSTATION_API_KEY` |
| Author | WebReadyNow |

---

## Requirements

| Requirement | Notes |
|---|---|
| WordPress | Current stable recommended |
| WooCommerce | Active and compatible version |
| WooCommerce Action Scheduler | Bundled with WooCommerce — required for scheduled sync (Phase 6) |
| ShipStation account | With internal inventory configured |
| ShipStation V2 API key | Read-only access to `/v2/inventory` is sufficient |
| Product SKUs | Must be consistent and exact between WooCommerce and ShipStation |

---

## Project Status

### Phases Complete

| Phase | Name | Status |
|---|---|---|
| 0 | Documentation and Architecture | Complete |
| 1 | Plugin Scaffold and Secure Settings | Complete |
| 2 | Read-Only ShipStation API Client | Complete |
| 3 | SKU Matching and Discovery Reporting | Complete |
| 4 | Dry-Run Inventory Synchronization | Complete — staging validated |

| 5 | Manual Live Stock Updates (Staging Only) | Complete — staging validated |

### Phases Planned

| Phase | Name | Status |
|---|---|---|
| 6 | Scheduled Synchronization | Next |
| 7 | Production Readiness Documentation | Planned |

---

## Confirmed API Behavior

### API Discovery

The following has been confirmed using a dedicated ShipStation test account:

- ShipStation internal inventory was enabled.
- A test inventory warehouse was created.
- Test products and inventory quantities were imported.
- A ShipStation V2 API key was generated.
- Successful authenticated read requests were made to `GET https://api.shipstation.com/v2/inventory`.
- The API returns inventory records containing: `sku`, `on_hand`, `available`, `average_cost`.
- The API response includes pagination metadata: `total`, `page`, `pages`, `links`.
- The correct WooCommerce target quantity is ShipStation's `available` value.

### Critical Zero-Stock Behavior

Live API testing confirmed an important behavior:

```text
ShipStation quantity greater than 0 → SKU appears in GET /v2/inventory
ShipStation quantity equal to 0   → SKU disappears from GET /v2/inventory
```

Test performed:

1. `WRN-SS-003` was imported with quantity `0` — it did not appear in the bulk endpoint.
2. A direct request for `?sku=WRN-SS-003` also returned no inventory record.
3. Quantity was changed to `1`; the SKU then appeared with `available: 1`.
4. Quantity was changed back to `0`; the SKU disappeared again.

**This plugin never assumes that a WooCommerce SKU missing from the ShipStation response should automatically be set to zero.**

---

## Current Capabilities (Phases 1–5)

### Installation and Activation

1. Upload the `woocommerce-shipstation-integration-wr` folder to `wp-content/plugins/`.
2. Activate the plugin through the WordPress plugins screen.
3. WooCommerce must be active — the plugin shows an admin notice if WooCommerce is missing.
4. On activation, three custom database tables are created:
   - `{prefix}wsi_wr_sync_runs` — one row per sync run summary
   - `{prefix}wsi_wr_sync_items` — per-SKU diagnostic rows per run
   - `{prefix}wsi_wr_tracked_skus` — persistent SKU registry

### Settings Configuration

Settings are under **WooCommerce → Settings → Integrations → ShipStation Integration WR**.

#### API Key

Enter your ShipStation V2 API key in the **ShipStation API Key** field. The key is stored securely and is never displayed after saving.

Alternatively, define the key in `wp-config.php`:

```php
define( 'WSI_WR_SHIPSTATION_API_KEY', 'your-api-key-here' );
```

When the constant is defined, the settings screen shows "API key configured via wp-config.php" and the input field is hidden. The key is never output to the browser.

#### Available Settings

| Setting | Default | Notes |
|---|---|---|
| Enable inventory sync | No | Master on/off switch for scheduled sync |
| Dry-run mode | Yes | Prevents live stock writes — leave enabled until validated |
| Missing SKU handling | Report only | Never auto-zeros absent SKUs by default |
| Recent-order protection window | 60 minutes | Blocks stock increases for products with recent orders |
| Auto-enable Manage Stock | No | Whether to enable stock management on matched products |
| Schedule enable | No | Enables Action Scheduler recurring sync (Phase 6) |
| Sync times | 07:00, 12:00, 18:00 | Three daily execution times |
| Request timeout | 30 seconds | Per-page API request timeout |
| Page size | 500 | Records per API page |
| Enable logging | Yes | WooCommerce log integration |
| Log retention | 30 days | Days to keep sync log entries |

#### Connection Tools

After saving your API key, use the connection tools on the settings page:

- **Test Connection** — sends a single-record request to `/v2/inventory` and confirms the API is accessible and whether the `available` field is present.
- **Fetch Inventory Sample** — retrieves up to 10 inventory records and displays SKU, on-hand quantity, and available quantity in a table.

Neither tool writes to ShipStation or modifies WooCommerce data.

### SKU Alignment Report

Navigate to **WooCommerce → ShipStation WR → SKU Alignment Report**.

Click **Run SKU Alignment Report** to:

1. Fetch all pages of ShipStation inventory.
2. Match each ShipStation SKU to a WooCommerce product or variation by exact SKU.
3. Display categorized results:

| Category | Meaning |
|---|---|
| Matched — Simple | ShipStation SKU matched to a WooCommerce simple product |
| Matched — Variation | ShipStation SKU matched to a WooCommerce product variation |
| Unmatched | ShipStation SKU has no WooCommerce product |
| Manage Stock Disabled | WooCommerce product found but stock management is off |
| Ambiguous | Multiple WooCommerce products share the same SKU |
| Skipped — Empty SKU | ShipStation record has a blank SKU |

Results columns: SKU, WC Product ID, WC Stock, SS Available, SS On Hand, Notes.

The report does **not** change any WooCommerce stock values.

#### CSV Export

After running a report, click **Download CSV** to export all result rows. The previous run is also shown below the report button with a CSV download link.

#### Tracked SKU Registry (automatic population)

Running the alignment report or a dry-run sync automatically registers all matched SKUs in the Tracked SKU Registry. This is the foundation of the zero-stock safeguard system.

### Tracked SKU Registry

Navigate to **WooCommerce → ShipStation WR → Tracked SKUs**.

The registry displays all SKUs that have been seen in the ShipStation API or manually approved, with:

| Column | Meaning |
|---|---|
| SKU | The ShipStation/WooCommerce SKU |
| WC Product ID | Link to the WooCommerce product edit screen |
| Type | Simple or Variation |
| Source | `api_seen` or `manually_approved` |
| Status | Active, Possible Zero Stock, Confirmed Absent, Zero Applied, Paused, Mapping Error |
| Last Available | Last `available` quantity returned by ShipStation |
| Last Seen | Timestamp the SKU was last present in the API response |
| Missing Count | Consecutive complete syncs where the SKU was absent |
| Sync | Pause / Resume button |

#### Manual SKU Approval

Use the **Manually Approve a SKU** form to register a SKU that is currently at zero stock (and therefore absent from the ShipStation API) so the plugin can track its future absence.

Required fields: SKU (exact), WooCommerce Product/Variation ID, Type (Simple or Variation).

#### Pause / Resume

Each tracked SKU can be individually paused to exclude it from sync evaluation without removing it from the registry.

### Dry-Run Inventory Sync

Navigate to **WooCommerce → ShipStation WR → Dry-Run Sync**.

Click **Run Dry-Run Sync** to:

1. Fetch all pages of ShipStation inventory.
2. Match each SKU to a WooCommerce product or variation.
3. Calculate what a live sync would do for each matched SKU.
4. Evaluate sync-enabled tracked SKUs absent from the API response.

Result categories returned:

| Category | Meaning |
|---|---|
| Proposed Increase | SS available > WC stock; no recent order block |
| Proposed Decrease | SS available < WC stock |
| No Change Required | SS available equals WC stock |
| Skipped — Recent Order Protection | Stock increase blocked: a recent WooCommerce order exists within the protection window |
| Missing Tracked SKU | SKU in registry but absent from API; reported only (default) |
| Proposed Zero Stock | Absent 2+ consecutive syncs under optional `zero_after_two_confirmed_absent_syncs` setting |
| Unmatched | No WooCommerce product found |
| Manage Stock Disabled | WooCommerce product found but stock management is off |
| Ambiguous | Multiple WooCommerce products share the same SKU |

The dry-run does **not** write to WooCommerce. It does update the Tracked SKU Registry (missing counts, last-seen timestamps) exactly as a live sync would.

A CSV export of all result rows is available after running.

> **Validated:** Staging test confirmed correct results for all test SKUs — increases, decreases, unmatched, manage-stock-disabled, and zero-stock omission all behaved as expected.

### SKU Matching Rules

- Matching is by **exact SKU only** — no normalization, no fuzzy matching, no case conversion (whitespace is trimmed).
- Simple products and variations are both supported.
- If multiple WooCommerce products or variations share the same SKU, the result is `ambiguous` — the plugin does not guess.
- The plugin never creates WooCommerce products automatically.
- WooCommerce product lookup uses the `wc_product_meta_lookup` table (HPOS-compatible).

### Zero-Stock Safeguard

Because ShipStation omits zero-stock SKUs from the API response, the plugin cannot distinguish "zero stock" from "not in ShipStation" for an unknown SKU.

The tracked SKU registry solves this:

- Only SKUs previously confirmed (via alignment report or manual approval) are evaluated for zero-stock handling.
- The default missing SKU handling is **report only** — no WooCommerce stock is changed when a tracked SKU goes absent.
- Under the optional `zero_after_two_confirmed_absent_syncs` setting, a tracked SKU may be set to zero after two consecutive **complete successful** syncs where it is absent. This mode is disabled by default.
- Zero-stock evaluation is **never** triggered when: the API request fails, authentication fails, pagination does not complete, or the response is malformed.

---

### Manual Live Stock Sync

Navigate to **WooCommerce → ShipStation WR → Manual Sync**.

After reviewing a dry-run, use this tab to apply the proposed changes to WooCommerce stock:

1. Click **Run Manual Sync**.
2. The plugin fetches ShipStation inventory, evaluates proposed actions (identical logic to dry-run), then writes stock for all increases, decreases, and zero-stock proposals.
3. Results are displayed grouped by outcome:

| Category | Meaning |
|---|---|
| Applied — Stock Increased | ShipStation available > WC stock; written to WooCommerce |
| Applied — Stock Decreased | ShipStation available < WC stock; written to WooCommerce |
| Applied — Zero Stock | Tracked SKU absent 2+ syncs (optional setting); set to zero |
| Failed | Product could not be loaded or manage_stock disabled at write time |
| No Change Required | Stock already matched |
| Skipped — Recent Order Protection | Increase blocked due to recent WooCommerce order |
| Skipped — Variation Uses Parent Stock | Variation's own manage_stock is not enabled; enable per-variation stock management or map the ShipStation SKU to the parent product |

A CSV export with Action Taken and New Stock columns is available after each run. The previous run summary is shown below the button.

> **Validated:** Staging test confirmed correct stock writes for increases, decreases, and all skip/protection categories.

#### SKU Matching — Variation Stock Management

Each variation is matched and updated independently by its own SKU. For a variation to be updated directly, it must have **Manage stock** enabled at the variation level (not inherited from the parent). If a variation's stock is managed by the parent variable product, the plugin skips it with a "Variation Uses Parent Stock" notice — the correct setup is either to enable per-variation stock management or to map the ShipStation SKU to the parent product.

---

## Planned Features

> These features are **not yet implemented**. They are planned for Phases 6–7.

### Phase 6 — Scheduled Synchronization

Three daily automated syncs using WooCommerce Action Scheduler:

| Default Time | Purpose |
|---|---|
| 7:00 AM | Morning inventory update |
| 12:00 PM | Midday inventory update |
| 6:00 PM | End-of-day inventory update |

Scheduled actions are visible at **WooCommerce → Status → Scheduled Actions**. Each store can configure custom times. Duplicate prevention is included on settings save.

### Phase 7 — Production Readiness Documentation

Deployment checklists, discovery checklists, dry-run review guidance, activation steps, and rollback procedures.

---

## How the Existing Selling Channel Fits In

This plugin is designed primarily for stores that already have WooCommerce connected to ShipStation as a selling channel.

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
| Existing WooCommerce selling channel in ShipStation | Imports WooCommerce orders into ShipStation | Keep active |
| ShipStation Internal Inventory | Remains inventory source of truth | Keep active |
| This plugin | Pulls `available` quantities into WooCommerce | Enable after validation |
| ShipStation Inventory Sync targeting WooCommerce | Pushes stock directly into WooCommerce | Disable operationally before live plugin sync |

The plugin does not modify the existing selling-channel connection. Order flow must be verified separately before production activation.

---

## Recent-Order Stock Increase Protection

There is a timing risk when an order reduces WooCommerce stock before ShipStation has imported the order and reduced its `available` quantity.

```text
WooCommerce and ShipStation begin with stock 10.
Customer purchases 2 in WooCommerce; WooCommerce becomes 8.
ShipStation has not imported the order yet and still reports 10.
A sync runs too early.
Without protection, WooCommerce would incorrectly be restored to 10.
```

This protection is active in both manual sync and (Phase 6) scheduled sync:

- A ShipStation stock decrease is applied when safely matched.
- A stock increase is checked against recent WooCommerce orders containing that product within the configured protection window (default: 60 minutes).
- If a relevant recent order exists within the window, the increase is skipped and reported as "Skipped — Recent Order Protection."
- Order lookups use HPOS-compatible WooCommerce APIs (`wc_order_product_lookup` + `wc_get_orders()`).

This protection reduces timing risk but does not replace the need to test the real store's WooCommerce-to-ShipStation order allocation timing before live activation.

---

## Explicitly Not Included (Phase 1)

- Pushing WooCommerce orders into ShipStation
- Replacing the existing ShipStation selling-channel order connection
- Automatically changing ShipStation selling-channel configuration
- Automatically disabling ShipStation Inventory Sync
- Creating or updating products inside ShipStation
- Writing inventory quantities into ShipStation
- Labels, carriers, tracking, fulfillment, rates, or shipping logic
- Price synchronization

---

## Security

- All ShipStation requests are server-side via `wp_remote_get`.
- The API key is never output to HTML, JavaScript, AJAX responses, CSV exports, error reports, logs, or screenshots.
- The API key is never committed to source control.
- Admin actions require nonce verification and `manage_woocommerce` capability.
- Settings are sanitized on save; output is escaped.
- Stock updates (Phase 5+) use `wc_update_product_stock()` — not direct `_stock` meta.
- Recent-order queries use HPOS-compatible WooCommerce APIs.
- No customer personal information is stored in sync logs or reports.

---

## Test Dataset

The development test account uses the following ShipStation inventory records:

| SKU | ShipStation Available | Test Purpose |
|---|---:|---|
| `WRN-SS-001` | 25 | WooCommerce stock increase |
| `WRN-SS-002` | 7 | WooCommerce stock decrease |
| `WRN-SS-003` | 0 / absent from API | Zero-stock omission behavior |
| `WRN-SS-VAR-001` | 12 | Variation matching |
| `WRN-SS-NOSTOCK` | 4 | Manage Stock disabled case |
| `WRN-SS-NOMATCH` | 15 | ShipStation-only/unmatched SKU |

Expected WooCommerce staging products (needed before Phase 4 testing):

| SKU | Initial WC Stock | Expected Dry-Run Outcome |
|---|---:|---|
| `WRN-SS-001` | 5 | Propose increase to 25 |
| `WRN-SS-002` | 20 | Propose decrease to 7 |
| `WRN-SS-003` | 8 | Report zero-stock/tracking case — no action |
| `WRN-SS-VAR-001` | 3 | Propose variation increase to 12 |
| `WRN-SS-NOSTOCK` | Not managed | Skip and report |
| `WRN-SS-NOMATCH` | Not in WooCommerce | Report unmatched and skip |

---

## Technical Architecture

### File Structure

| Path | Purpose |
|---|---|
| `woocommerce-shipstation-integration-wr.php` | Main plugin file, bootstrap, HPOS declaration |
| `uninstall.php` | Drops tables and options on full plugin deletion |
| `includes/class-plugin.php` | Singleton bootstrap, dependency loading, hook registration |
| `includes/class-db-schema.php` | `dbDelta()` table creation with schema versioning |
| `includes/class-activator.php` | Runs DB install, sets default options on activation |
| `includes/class-deactivator.php` | Unschedules Action Scheduler jobs on deactivation |
| `includes/admin/class-integration.php` | WC_Integration settings page, secure API key field |
| `includes/admin/class-ajax-handlers.php` | All `wp_ajax_wsi_wr_*` handlers |
| `includes/admin/class-admin-pages.php` | WC submenu, tab routing, CSV export via admin-post.php |
| `includes/admin/pages/class-sku-alignment-report.php` | Alignment report page renderer |
| `includes/admin/pages/class-dry-run-report.php` | Dry-run sync page renderer |
| `includes/admin/pages/class-manual-sync.php` | Manual sync page renderer |
| `includes/admin/pages/class-tracked-sku-admin.php` | Tracked SKU registry page renderer |
| `includes/api/class-api-client.php` | ShipStation HTTP client (read-only) |
| `includes/sync/class-product-matcher.php` | Exact SKU matching via wc_product_meta_lookup |
| `includes/sync/class-tracked-sku-registry.php` | CRUD for wsi_wr_tracked_skus table |
| `includes/sync/class-order-protection.php` | HPOS-compatible recent-order lookup |
| `includes/sync/class-sync-service.php` | Proposed action evaluation and live stock writes |
| `includes/utilities/class-logger.php` | WC logger wrapper, safe metadata only |
| `assets/js/admin.js` | Admin JavaScript (AJAX, DOM rendering) |
| `assets/css/admin.css` | Admin styles |

### Database Tables

| Table | Purpose |
|---|---|
| `{prefix}wsi_wr_sync_runs` | One summary row per inventory/test run |
| `{prefix}wsi_wr_sync_items` | Per-SKU diagnostic rows per run |
| `{prefix}wsi_wr_tracked_skus` | Persistent SKU mapping and zero-stock tracking |

### HPOS Compatibility

WooCommerce HPOS (High-Performance Order Storage) compatibility is declared via `FeaturesUtil::declare_compatibility`. Product lookups use the `wc_product_meta_lookup` table. Order queries (Phase 5+) will use `wc_get_orders()`.

### Extension Hooks

The API client exposes the following filters for customization:

| Hook | Type | Purpose |
|---|---|---|
| `wsi_wr_api_base_url` | filter | Override API base URL |
| `wsi_wr_api_request_args` | filter | Modify `wp_remote_get` args (auth header excluded) |
| `wsi_wr_api_timeout` | filter | Override request timeout |
| `wsi_wr_api_page_size` | filter | Override records per page |
| `wsi_wr_sku_before_matching` | filter | Modify SKU string before matching lookup |
| `wsi_wr_product_match_result` | filter | Override or augment a match result |

---

## Store Onboarding Workflow

### Store Already Connected to ShipStation

1. Keep the selling-channel order connection active.
2. Confirm ShipStation internal inventory is configured.
3. Install and activate this plugin.
4. Enter the ShipStation V2 API key in plugin settings.
5. Run **Test Connection** — confirm success and `available` field presence.
6. Run **Fetch Inventory Sample** — review returned records.
7. Run the **SKU Alignment Report** — resolve unmatched, ambiguous, and manage-stock-disabled cases.
8. Review the Tracked SKU Registry — manually approve any zero-stock SKUs you want tracked.
9. Run a **dry-run sync** and review proposed changes (Dry-Run Sync tab).
10. *(Phase 5, planned)* Confirm WooCommerce order timing with ShipStation allocation.
11. *(Phase 5, planned)* Disable conflicting ShipStation Inventory Sync push.
12. *(Phase 5, planned)* Enable manual live sync after staging validation.
13. *(Phase 6, planned)* Enable scheduled synchronization.

---

## Development Setup

Plugin directory:

```text
wp-content/plugins/woocommerce-shipstation-integration-wr
```

Start Claude Code from inside the plugin directory:

```powershell
cd C:\wamp64\www\<your-site-folder>\wp-content\plugins\woocommerce-shipstation-integration-wr
claude
```

Do not provide real API keys to source code or AI coding prompts. Configure the key in the WordPress settings screen or locally via `wp-config.php`.

---

## Deployment Prerequisites (Before Live Production Sync)

> These apply when Phase 5 (live stock writes) is enabled.

- Confirm ShipStation Inventory API access with the production API key.
- Confirm `available` values are returned correctly.
- Confirm zero-stock omission behavior in the production account.
- Confirm SKU alignment between ShipStation and WooCommerce.
- Confirm WooCommerce orders continue reaching ShipStation.
- Confirm ShipStation allocation updates `available` after order import.
- Measure order-to-allocation timing and configure the protection window.
- Review a complete production dry-run report.
- Disable conflicting ShipStation Inventory Sync push targeting WooCommerce.
- Enable live sync only after all the above is verified.

---

## Non-Goals (Phase 1)

This plugin is not intended, in Phase 1, to:

- Replace full ShipStation fulfillment workflows
- Purchase labels
- Select carriers or rates
- Manage tracking notifications
- Send orders to ShipStation
- Modify ShipStation inventory
- Automatically configure ShipStation selling channels
- Function as an ERP or omnichannel inventory platform

---

## License

License selection has not yet been finalized. Add a license before public distribution.

---

## Author

Developed by **WebReadyNow** for controlled, safer, and more observable ShipStation inventory synchronization in WooCommerce.
