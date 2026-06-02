# WooCommerce ShipStation Integration WR

A WooCommerce plugin for two-way controlled inventory synchronization with ShipStation: scheduled pull of ShipStation `available` quantities into WooCommerce, and real-time decrement of ShipStation inventory when WooCommerce orders are paid.

> **Project status:** Phases 0–6 complete and staging-validated. Export feature (WooCommerce → ShipStation decrement) implemented and staging-validated. Production readiness documentation (Phase 7) is next.

---

## Why This Plugin Exists

This plugin solves two related inventory problems for WooCommerce stores that use ShipStation as the source of truth for inventory across multiple sales channels.

**Problem 1 — Inbound sync performance:** The original ShipStation-to-WooCommerce inventory push was firing frequent inbound requests, exhausting PHP workers, and affecting site performance. This plugin replaces that with a controlled scheduled pull.

**Problem 2 — Outbound sync gap:** WooCommerce orders were importing into ShipStation correctly, but ShipStation was not reliably allocating or deducting inventory for those orders. Without a decrement, ShipStation's `available` count would stay inflated, and the next scheduled pull sync would increase WooCommerce stock back up — potentially allowing overselling.

### Sync Architecture

```text
ShipStation Internal Inventory
        ↓  (scheduled pull, up to 3× per day)
ShipStation Inventory API V2 — GET /v2/inventory
        ↓
WooCommerce ShipStation Integration WR
        ↓
WooCommerce product and variation stock

WooCommerce order paid
        ↓  (real-time, on payment confirmation)
WooCommerce ShipStation Integration WR
        ↓
ShipStation Inventory API V2 — POST /v2/inventory (decrement)
        ↓
ShipStation inventory reduced by quantity sold
```

ShipStation remains the source of truth. The plugin never creates, deletes, or adjusts products in ShipStation — only controlled decrements on confirmed sales.

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
| WooCommerce Action Scheduler | Bundled with WooCommerce — required for scheduled synchronization |
| ShipStation account | With internal inventory configured and at least one inventory location |
| ShipStation V2 API key | Read access to `/v2/inventory` required; write access required for live export decrement |
| Product SKUs | Must be consistent and exact between WooCommerce and ShipStation |

---

## Project Status

### Phases Complete

| Phase | Name | Status |
|---|---|---|
| 0 | Documentation and Architecture | Complete |
| 1 | Plugin Scaffold and Secure Settings | Complete |
| 2 | Read-Only ShipStation API Client | Complete |
| 3 | SKU Matching and Discovery Reporting | Complete — staging validated |
| 4 | Dry-Run Inventory Synchronization | Complete — staging validated |
| 5 | Manual Live Stock Updates | Complete — staging validated |
| 6 | Scheduled Synchronization | Complete — staging validated |

### Export Feature Status

| Feature | Status |
|---|---|
| WooCommerce → ShipStation inventory decrement | Complete — staging validated (dry-run and live) |
| Refund/cancellation inventory restoration | Not implemented — planned follow-on task |

### Phases Planned

| Phase | Name | Status |
|---|---|---|
| 7 | Production Readiness Documentation | Next |

---

## Confirmed API Behavior

### Pull — GET /v2/inventory

Confirmed on staging ShipStation account:

- Returns inventory records containing: `sku`, `on_hand`, `available`, `average_cost`.
- Pagination metadata: `total`, `page`, `pages`, `links`.
- The correct WooCommerce target quantity is ShipStation's `available` value.

### Critical Zero-Stock Behavior

```text
ShipStation quantity > 0 → SKU appears in GET /v2/inventory
ShipStation quantity = 0 → SKU disappears from GET /v2/inventory entirely
```

The plugin never assumes that a WooCommerce SKU missing from the API response should automatically be set to zero. Only SKUs that have been seen and tracked in the SKU registry, absent from two consecutive complete successful syncs, can trigger zero-stock handling — and only under a non-default optional setting.

### Export — POST /v2/inventory

Confirmed on staging:

- `transaction_type: decrement` subtracts the sold quantity from ShipStation's current inventory.
- `transaction_type: adjust` (absolute set) is **never** used — it would overwrite concurrent channel activity.
- The plugin sends `reason`, `description`, and `notes` fields with the same value (`"WooCommerce order #XXXX"`) so whichever field ShipStation uses for its stock history description is populated.
- Successful response codes: 200, 201, or 204 — all treated as success.

### Inventory Locations — GET /v2/inventory_locations

- Returns available inventory locations for the ShipStation account.
- The plugin parses multiple possible response shapes (wrapped or root-level array).
- If exactly one location is returned and none is selected, it is auto-selected on first refresh.

---

## Settings Configuration

Settings are under **WooCommerce → Settings → Integrations → ShipStation Integration WR**.

### API Key

Enter your ShipStation V2 API key in the **ShipStation API Key** field. The key is stored securely and is never displayed after saving.

Alternatively, define the key in `wp-config.php`:

```php
define( 'WSI_WR_SHIPSTATION_API_KEY', 'your-api-key-here' );
```

When the constant is defined, the settings screen shows "API key configured via wp-config.php". The key is never output to the browser.

### All Settings

| Setting | Default | Section | Notes |
|---|---|---|---|
| Enable inventory sync | No | Connection | Master on/off for scheduled pull sync |
| Dry-run mode | Yes | Sync Behaviour | Prevents live WC stock writes — leave enabled until validated |
| Missing SKU handling | Report only | Sync Behaviour | Never auto-zeros absent SKUs by default |
| Recent-order protection window | 60 minutes | Sync Behaviour | Blocks pull-sync stock increases when recent orders exist |
| Auto-enable Manage Stock | No | Sync Behaviour | Whether to enable stock management on matched products |
| Schedule enable | No | Scheduling | Enables Action Scheduler recurring sync |
| Sync times | 07:00, 12:00, 18:00 | Scheduling | Up to three daily execution times (HH:MM, 24-hour, site timezone) |
| Request timeout | 30 seconds | Processing | Per-page API request timeout |
| Page size | 100 | Processing | Records per API page |
| Enable logging | Yes | Logging | WooCommerce log integration |
| Log retention | 30 days | Logging | Days to keep sync log entries |
| Export decrement mode | Disabled | Export | WooCommerce → ShipStation decrement (disabled / dry_run / live) |
| Inventory location | None | Export | Selected ShipStation location — use Refresh button to discover |

### Connection Tools

After saving your API key, use the connection tools on the settings page:

- **Test Connection** — confirms API access and whether the `available` field is present.
- **Fetch Inventory Sample** — retrieves up to 10 inventory records for review.

Neither tool modifies any data.

---

## Pull Sync Features

### SKU Alignment Report

Navigate to **WooCommerce → ShipStation WR → SKU Alignment Report**.

Click **Run SKU Alignment Report** to fetch all ShipStation inventory, match each SKU to a WooCommerce product or variation, and display categorized results:

| Category | Meaning |
|---|---|
| Matched — Simple | ShipStation SKU matched to a WooCommerce simple product |
| Matched — Variation | ShipStation SKU matched to a WooCommerce product variation |
| Unmatched | ShipStation SKU has no WooCommerce product |
| Manage Stock Disabled | WooCommerce product found but stock management is off |
| Ambiguous | Multiple WooCommerce products share the same SKU |
| Skipped — Empty SKU | ShipStation record has a blank SKU |

Results columns: SKU, WC Product ID, WC Stock, SS Available, SS On Hand, Notes. A CSV export is available after each run. The report does not change any stock values.

Running the alignment report automatically registers matched SKUs in the Tracked SKU Registry.

### Tracked SKU Registry

Navigate to **WooCommerce → ShipStation WR → Tracked SKUs**.

The registry tracks all SKUs seen in the ShipStation API or manually approved, enabling the zero-stock safeguard system.

| Column | Meaning |
|---|---|
| SKU | The ShipStation/WooCommerce SKU |
| WC Product ID | Link to the WooCommerce product edit screen |
| Type | Simple or Variation |
| Source | `api_seen` or `manually_approved` |
| Last Available | Last `available` quantity from ShipStation |
| Last Seen | Timestamp the SKU was last in the API response |
| Missing Count | Consecutive complete syncs where the SKU was absent |
| Sync | Pause / Resume per-SKU |

Use the **Manually Approve a SKU** form to register a zero-stock SKU (currently absent from the API) so future absence can be tracked.

### Dry-Run Inventory Sync

Navigate to **WooCommerce → ShipStation WR → Dry-Run Sync**.

Calculates and displays all proposed WooCommerce stock changes without writing anything. Result categories:

| Category | Meaning |
|---|---|
| Proposed Increase | SS available > WC stock; no recent order block |
| Proposed Decrease | SS available < WC stock |
| No Change Required | SS available equals WC stock |
| Skipped — Recent Order Protection | Stock increase blocked: recent order within protection window |
| Missing Tracked SKU | SKU in registry but absent from API; reported only (default) |
| Proposed Zero Stock | Absent 2+ consecutive syncs under optional setting |
| Unmatched | No WooCommerce product found |
| Manage Stock Disabled | WooCommerce product found but stock management is off |
| Ambiguous | Multiple products share the same SKU |

A CSV export of all result rows is available after running.

> **Validated:** Staging test confirmed correct results for all test SKUs.

### Manual Live Stock Sync

Navigate to **WooCommerce → ShipStation WR → Manual Sync**.

After reviewing a dry-run, apply the proposed changes to WooCommerce stock. Same evaluation logic as dry-run, but writes are applied. A CSV export with Action Taken and New Stock columns is available after each run.

> **Validated:** Staging test confirmed correct stock writes for all categories.

### Scheduled Synchronization

Navigate to **WooCommerce → Settings → Integrations → ShipStation Integration WR → Scheduling**.

Enable **Enable Scheduled Synchronization** and configure up to three daily sync times in HH:MM (24-hour) format. Defaults: 07:00, 12:00, 18:00 (site timezone).

Scheduled actions are registered via WooCommerce Action Scheduler and visible at **WooCommerce → Status → Scheduled Actions**. Saving settings always cancels existing actions and re-registers at the current configured times.

Each scheduled run uses the same pipeline as manual sync. If Dry-Run Mode is enabled, scheduled runs calculate and record proposed changes but do not write to WooCommerce.

#### Server Cron Reliability

Action Scheduler depends on WordPress cron, which only runs when a page is loaded. For reliable production scheduling, configure a real server cron to trigger WordPress cron every minute:

```bash
* * * * * wget -q -O - https://yoursite.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1
```

Or using curl:

```bash
* * * * * curl -s https://yoursite.com/wp-cron.php?doing_wp_cron > /dev/null 2>&1
```

Then disable WordPress pseudo-cron in `wp-config.php`:

```php
define( 'DISABLE_WP_CRON', true );
```

> **Validated:** Scheduled sync staging test confirmed correct `trigger=scheduled` / `trigger=scheduled_dry_run` log entries, correct API fetch, SKU matching, and stock evaluation pipeline.

---

## SKU Matching Rules

- Matching is by **exact SKU only** — no normalization, no fuzzy matching, no case conversion (whitespace is trimmed).
- Simple products and variations are both supported.
- If multiple WooCommerce products or variations share the same SKU, the result is `ambiguous`.
- The plugin never creates WooCommerce products automatically.
- Product lookups use the `wc_product_meta_lookup` table (HPOS-compatible).

### Variation Stock Management

Each variation is matched and updated independently by its own SKU. For a variation to be updated directly, it must have **Manage stock** enabled at the variation level (not inherited from the parent). If a variation's stock is managed by the parent, the plugin skips it with a "Variation Uses Parent Stock" notice.

---

## Zero-Stock Safeguard

Because ShipStation omits zero-stock SKUs from the API response, the plugin cannot distinguish "zero stock" from "SKU not in ShipStation" for an unknown SKU.

The tracked SKU registry solves this:

- Only previously confirmed SKUs are evaluated for zero-stock handling.
- Default missing SKU handling is **report only** — no WooCommerce stock is changed when a tracked SKU goes absent.
- Under the optional `zero_after_two_confirmed_absent_syncs` setting, a tracked SKU may be set to zero after two consecutive **complete successful** syncs where it is absent. Disabled by default.
- Zero-stock evaluation is **never** triggered when: the API request fails, auth fails, pagination does not complete, or the response is malformed.

---

## Recent-Order Stock Increase Protection

A pull sync can arrive before ShipStation has imported and allocated a recent WooCommerce order. Without protection, the sync would incorrectly increase WooCommerce stock back toward ShipStation's stale higher number.

Protection behavior:
- A stock decrease is always applied.
- A stock increase is checked against recent WooCommerce orders containing that product within the protection window (default: 60 minutes).
- If a relevant recent order exists, the increase is skipped and reported as "Skipped — Recent Order Protection."
- Order lookups use HPOS-compatible APIs (`wc_order_product_lookup` + `wc_get_orders()`).

---

## Export Feature — WooCommerce → ShipStation Inventory Decrement

> **Status:** Implemented and staging-validated. Export mode defaults to **disabled** — no decrement calls are made until explicitly enabled.

### Why

ShipStation was not reliably allocating or deducting inventory for WooCommerce orders. Without this decrement, ShipStation's `available` count would remain inflated, and the next pull sync could increase WooCommerce stock back up toward the stale number — allowing overselling.

### Double-Decrement Warning

> **Only enable live mode after confirming ShipStation is not already deducting inventory for WooCommerce orders.** If ShipStation deducts AND this plugin also decrements, the result is double inventory reduction.

Check the SKU stock history in ShipStation for a recent order. If allocation/deduction activity already appears for WooCommerce orders, do not enable live mode.

### Export Mode

Configure under **WooCommerce → Settings → Integrations → ShipStation Integration WR → ShipStation Inventory Export**.

| Mode | Behavior |
|---|---|
| **Disabled** (default) | No decrement calls — nothing sent to ShipStation |
| **Dry Run** | Logs what would be sent without calling the API. Order notes added showing the payload. |
| **Live** | Sends actual decrement calls to ShipStation when payment is confirmed |

**Always validate with Dry Run before enabling Live.**

### Inventory Location Setup

ShipStation requires an `inventory_location_id` in every decrement call. The plugin fetches locations dynamically:

1. Save your ShipStation API key in plugin settings.
2. In the **ShipStation Inventory Export** section, click **Refresh ShipStation Inventory Locations**.
3. Select the correct location from the dropdown (if multiple locations are returned).
4. Click **Save changes**.

**Auto-select:** If ShipStation returns exactly one location and no selection exists, it is auto-selected on first refresh. You must still save settings to persist the selection.

**No hardcoded IDs.** The plugin fetches locations for any ShipStation account and stores the selection in WordPress options. It is reusable across stores.

### How the Decrement Works

**Trigger (primary):** `woocommerce_payment_complete` — fires when WooCommerce confirms payment.

**Trigger (fallback):** `woocommerce_order_status_processing` and `woocommerce_order_status_completed` — covers gateways that do not reliably fire `payment_complete`. Both are guarded by `is_paid()` + idempotency checks.

> **Note on hook order:** In WooCommerce's `payment_complete()` flow, `woocommerce_order_status_processing` fires *before* `woocommerce_payment_complete`. The status fallback processes the order first; then `payment_complete` sees the order is already processed and skips. This is correct behavior.

**Per line item:**
1. Resolve the SKU — use variation SKU if present; fall back to parent product SKU.
2. Validate quantity — skip if zero, null, or negative.
3. Build decrement payload and send (or log in dry-run mode).
4. Continue to the next line item regardless of failure — never block the order flow.

**Idempotency:**
- Order-level: `_ss_export_decrement_processed = yes` prevents the entire order from being re-processed.
- Item-level: `_ss_export_item_{line_item_id}` stores `success|failed|dry_run|skipped_*` per item. Items with status `success` are never re-decremented even if the order hook fires again.

### Decrement Payload

```json
{
  "transaction_type": "decrement",
  "inventory_location_id": "se-XXXXXXX",
  "sku": "PRODUCT-SKU",
  "quantity": 1,
  "reason": "WooCommerce order #XXXX",
  "description": "WooCommerce order #XXXX",
  "notes": "WooCommerce order #XXXX"
}
```

`reason`, `description`, and `notes` are all sent with the same value so that whichever field ShipStation uses for its stock history description label is populated.

`decrement` is always used — never `adjust`. Adjust is an absolute value set that would overwrite concurrent inventory activity from other channels.

### Order Notes

After each processed order (in any mode), a private WooCommerce order note is added showing the outcome per line item:

```
ShipStation inventory export — Dry Run
Location: se-4355901
• SKU-001 × 2 → dry run — would decrement
• SKU-002 × 1 → dry run — would decrement
No ShipStation API calls made.
```

```
ShipStation inventory export — Live
Location: se-4355901
• SKU-001 × 2 → decremented ✓
• SKU-002 × 1 → FAILED (HTTP 429)
```

The note is private (not visible to customers) and is added once per order.

### What the Export Feature Does NOT Do

- Does not modify WooCommerce stock.
- Does not handle refunds or cancellations — planned as a follow-on task.
- Does not block the checkout or order flow on failure.
- Does not use `adjust` or any other ShipStation inventory write besides `decrement`.

### Logging

Every attempted, dry-run, skipped, or failed line item is logged to WooCommerce → Status → Logs (source: `wsi-wr-shipstation`). Each entry includes: trigger, order ID, order number, line item ID, product ID, variation ID, SKU, quantity, mode, location ID, payload (in live/dry-run), HTTP status, response body, outcome, and error message.

---

## How the Existing Selling Channel Fits In

This plugin is designed for stores already connected to ShipStation as a selling channel.

| Component | Responsibility | Expected State |
|---|---|---|
| Existing WooCommerce selling channel in ShipStation | Imports WooCommerce orders | Keep active |
| ShipStation Internal Inventory | Source of truth across channels | Keep active |
| This plugin — pull sync | Pulls `available` into WooCommerce on schedule | Enable after validation |
| This plugin — export decrement | Sends decrements to ShipStation on payment | Enable after confirming no double-decrement |
| ShipStation Inventory Sync targeting WooCommerce | Pushes stock directly into WooCommerce | Disable before enabling pull sync |

---

## Security

- All ShipStation requests are server-side via WordPress HTTP API (`wp_remote_get`, `wp_remote_post`).
- The API key is never output to HTML, JavaScript, AJAX responses, CSV exports, error reports, logs, or screenshots.
- The API key is never committed to source control.
- Admin actions require nonce verification and `manage_woocommerce` capability.
- Settings are sanitized on save; output is escaped on display.
- Stock updates use `wc_update_product_stock()` — not direct `_stock` meta writes.
- Order queries use HPOS-compatible WooCommerce APIs.
- No customer personal information is stored in sync logs or reports.
- Export payloads contain only SKU, quantity, location ID, and order number — no customer data.

---

## Technical Architecture

### File Structure

| Path | Purpose |
|---|---|
| `woocommerce-shipstation-integration-wr.php` | Main plugin file, bootstrap, HPOS declaration |
| `uninstall.php` | Drops tables and options on full plugin deletion |
| `includes/class-plugin.php` | Singleton bootstrap, dependency loading, hook registration |
| `includes/class-db-schema.php` | `dbDelta()` table creation with schema versioning |
| `includes/class-activator.php` | DB install, default options on activation |
| `includes/class-deactivator.php` | Unschedules Action Scheduler jobs on deactivation |
| `includes/admin/class-integration.php` | WC_Integration settings — pull + export sections |
| `includes/admin/class-ajax-handlers.php` | All `wp_ajax_wsi_wr_*` handlers including refresh locations |
| `includes/admin/class-admin-pages.php` | WC submenu, tab routing, CSV export |
| `includes/admin/pages/class-sku-alignment-report.php` | Alignment report page renderer |
| `includes/admin/pages/class-dry-run-report.php` | Dry-run sync page renderer |
| `includes/admin/pages/class-manual-sync.php` | Manual sync page renderer |
| `includes/admin/pages/class-tracked-sku-admin.php` | Tracked SKU registry page renderer |
| `includes/api/class-api-client.php` | ShipStation HTTP client — GET /v2/inventory, GET /v2/inventory_locations, POST /v2/inventory |
| `includes/export/class-inventory-location-service.php` | Fetch, cache, and select ShipStation inventory locations |
| `includes/export/class-inventory-export-service.php` | Payment hooks, idempotency, per-item decrement, order notes |
| `includes/sync/class-product-matcher.php` | Exact SKU matching via wc_product_meta_lookup |
| `includes/sync/class-tracked-sku-registry.php` | CRUD for wsi_wr_tracked_skus table |
| `includes/sync/class-order-protection.php` | HPOS-compatible recent-order lookup |
| `includes/sync/class-sync-service.php` | Proposed action evaluation and live stock writes |
| `includes/scheduler/class-scheduler.php` | Action Scheduler registration and management |
| `includes/utilities/class-logger.php` | WC logger wrapper, safe metadata only |
| `assets/js/admin.js` | Admin JavaScript — AJAX, DOM rendering, refresh locations |
| `assets/css/admin.css` | Admin styles |

### Database Tables

| Table | Purpose |
|---|---|
| `{prefix}wsi_wr_sync_runs` | One summary row per inventory sync run |
| `{prefix}wsi_wr_sync_items` | Per-SKU diagnostic rows per run |
| `{prefix}wsi_wr_tracked_skus` | Persistent SKU mapping and zero-stock tracking |

### WordPress Options (standalone — not in settings array)

| Option | Purpose |
|---|---|
| `wsi_wr_api_key` | ShipStation API key (autoload disabled, never in settings array) |
| `wsi_wr_inventory_locations_cache` | Cached array of ShipStation inventory locations |
| `wsi_wr_export_location_id` | Selected inventory location ID for export decrements |

### Order Meta (HPOS-compatible)

| Meta Key | Purpose |
|---|---|
| `_ss_export_decrement_processed` | `yes` when order has been processed — prevents re-processing |
| `_ss_export_decrement_mode` | Mode active when order was processed (dry_run / live) |
| `_ss_export_decrement_timestamp` | ISO datetime when order was processed |
| `_ss_export_decrement_location_id` | Inventory location used |
| `_ss_export_decrement_summary` | JSON summary of per-item results |
| `_ss_export_item_{line_item_id}` | Per-item status: success / failed / dry_run / skipped_* |

### HPOS Compatibility

WooCommerce HPOS compatibility declared via `FeaturesUtil::declare_compatibility`. Product lookups use `wc_product_meta_lookup`. Order queries and meta use WC_Order CRUD methods exclusively.

### Extension Hooks

| Hook | Type | Purpose |
|---|---|---|
| `wsi_wr_api_base_url` | filter | Override API base URL |
| `wsi_wr_api_request_args` | filter | Modify `wp_remote_get` args (auth header excluded from filter) |
| `wsi_wr_api_timeout` | filter | Override request timeout |
| `wsi_wr_api_page_size` | filter | Override records per page |

---

## Store Onboarding Workflow

### Pull Sync Setup

1. Install and activate the plugin. WooCommerce must be active.
2. Enter the ShipStation V2 API key under **WooCommerce → Settings → Integrations → ShipStation Integration WR**.
3. Run **Test Connection** — confirm success and `available` field presence.
4. Run **Fetch Inventory Sample** — review a sample of returned records.
5. Run the **SKU Alignment Report** — resolve unmatched, ambiguous, and manage-stock-disabled cases.
6. Review the **Tracked SKU Registry** — manually approve any zero-stock SKUs you want tracked.
7. Run a **Dry-Run Sync** and review all proposed changes.
8. Once the dry-run looks correct, disable Dry-Run Mode in settings.
9. Run a **Manual Live Sync** and confirm stock writes.
10. Disable any conflicting ShipStation Inventory Sync push targeting WooCommerce.
11. Enable **Scheduled Synchronization** in settings after manual sync is validated.
12. Configure a server-level cron for reliable scheduling (see Server Cron Reliability above).

### Export Decrement Setup

1. Confirm pull sync is working correctly.
2. Check a recent ShipStation order's stock history — verify ShipStation is **not** already deducting inventory for WooCommerce orders. If it is, do not enable live export mode.
3. In the **ShipStation Inventory Export** section, click **Refresh ShipStation Inventory Locations**.
4. Select the correct inventory location from the dropdown and save settings.
5. Set Export Decrement Mode to **Dry Run** and save.
6. Place a test order and confirm dry-run log entries appear in WooCommerce → Status → Logs.
7. Verify the private order note on the test order shows the expected SKUs and quantities.
8. Set Export Decrement Mode to **Live** and save.
9. Place another test order and confirm ShipStation stock history shows the decrement.

---

## Deployment Prerequisites (Before Live Production)

**Pull sync:**
- Confirm ShipStation Inventory API access with the production API key.
- Confirm `available` values are returned correctly for production SKUs.
- Confirm zero-stock omission behavior in the production account.
- Confirm SKU alignment between ShipStation and WooCommerce.
- Confirm WooCommerce orders continue reaching ShipStation through the existing selling channel.
- Confirm ShipStation allocation updates `available` after order import.
- Measure order-to-allocation timing and configure the protection window accordingly.
- Review a complete production dry-run report.
- Disable conflicting ShipStation Inventory Sync push targeting WooCommerce.

**Export decrement:**
- Confirm ShipStation is NOT already deducting inventory for WooCommerce orders.
- Validate with dry-run mode before enabling live.
- Confirm the correct inventory location is selected.

---

## Test Dataset

The staging ShipStation account uses the following test inventory records:

| SKU | ShipStation Available | Test Purpose |
|---|---:|---|
| `WRN-SS-001` | 25 | WooCommerce stock increase |
| `WRN-SS-002` | 7 | WooCommerce stock decrease; also used in export decrement tests |
| `WRN-SS-003` | 0 / absent from API | Zero-stock omission behavior; also used in export decrement tests |
| `WRN-SS-VAR-001` | 12 | Variation matching |
| `WRN-SS-NOSTOCK` | 4 | Manage Stock disabled case |
| `WRN-SS-NOMATCH` | 15 | ShipStation-only / unmatched SKU |

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

Do not provide real API keys to source code or AI coding prompts. Configure the key in the WordPress settings screen or via `wp-config.php`.

---

## Planned Features

### Phase 7 — Production Readiness Documentation

Deployment checklists, discovery checklists, dry-run review guidance, activation steps, and rollback procedures.

### Export Follow-On — Refund/Cancellation Inventory Restoration

If the client needs automatic inventory restoration in ShipStation when eligible WooCommerce refunds or cancellations occur, add a follow-on task to increment ShipStation inventory. Out of scope for the current implementation.

---

## License

License selection has not yet been finalized. Add a license before public distribution.

---

## Author

Developed by **WebReadyNow** for controlled, safer, and more observable ShipStation inventory synchronization in WooCommerce.
