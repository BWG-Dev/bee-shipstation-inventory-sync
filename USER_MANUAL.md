# User Manual — WooCommerce ShipStation Integration WR

**Version:** 0.6.0  
**Audience:** Store administrators and operations staff  
**Plugin location in WordPress:** WooCommerce → Settings → Integrations → ShipStation Integration WR

---

## Table of Contents

1. [What This Plugin Does](#1-what-this-plugin-does)
2. [Requirements](#2-requirements)
3. [Installation](#3-installation)
4. [Recommended Setup Workflow](#4-recommended-setup-workflow)
5. [Plugin Settings Reference](#5-plugin-settings-reference)
6. [API Tools: Test Connection and Inventory Sample](#6-api-tools-test-connection-and-inventory-sample)
7. [SKU Alignment Report](#7-sku-alignment-report)
8. [Tracked SKU Registry](#8-tracked-sku-registry)
9. [Dry-Run Sync](#9-dry-run-sync)
10. [Manual Sync](#10-manual-sync)
11. [Scheduled Sync](#11-scheduled-sync)
12. [Understanding Sync Results](#12-understanding-sync-results)
13. [Safety Features Explained](#13-safety-features-explained)
14. [CSV Exports](#14-csv-exports)
15. [Troubleshooting](#15-troubleshooting)
16. [Glossary](#16-glossary)

---

## 1. What This Plugin Does

This plugin pulls inventory quantities from ShipStation and updates your WooCommerce product stock — automatically, on a schedule, or on demand.

**The core purpose:** When ShipStation is your inventory source of truth (for example, because it processes your fulfilment and adjusts stock as orders ship), this plugin keeps WooCommerce stock in sync with what ShipStation actually has on hand.

**What it does:**
- Connects to the ShipStation V2 API and retrieves current inventory
- Matches ShipStation SKUs to WooCommerce products by exact SKU match
- Updates WooCommerce stock quantities using the ShipStation **available** quantity
- Runs on a configurable daily schedule (up to three times per day)
- Can be triggered manually at any time
- Provides a safe **dry-run** mode so you can review all proposed changes before anything is written

**What it does not do:**
- It does not export WooCommerce orders to ShipStation — that is handled separately by the official ShipStation WooCommerce plugin
- It does not create or modify ShipStation inventory in any way — it is read-only from ShipStation's perspective
- It does not manage shipping labels, tracking, or rates

> **Important:** ShipStation omits products with zero stock entirely from its API response. The plugin has safeguards to handle this safely — a missing SKU is never automatically set to zero without confirmation across multiple successful syncs. See [Section 13](#13-safety-features-explained).

---

## 2. Requirements

| Requirement | Minimum Version |
|---|---|
| WordPress | 6.0 or higher |
| WooCommerce | 7.0 or higher |
| PHP | 8.0 or higher |
| ShipStation account | V2 API access required |

WooCommerce **High Performance Order Storage (HPOS)** is fully supported.

---

## 3. Installation

1. Upload the `woocommerce-shipstation-integration-wr` folder to your `/wp-content/plugins/` directory, or install it via the WordPress admin panel under **Plugins → Add New → Upload Plugin**.
2. Activate the plugin through **Plugins → Installed Plugins**.
3. Navigate to **WooCommerce → Settings → Integrations** and click **ShipStation Integration WR** to begin configuration.

The plugin creates the necessary database tables automatically on activation. No manual database setup is required.

---

## 4. Recommended Setup Workflow

Follow these steps in order the first time you set up the plugin. **Do not enable live sync or scheduling until you have completed the verification steps.**

### Step 1 — Enter your API key and save settings

Go to **WooCommerce → Settings → Integrations → ShipStation Integration WR**.

Enter your ShipStation V2 API key in the **ShipStation API Key** field and click **Save changes**. The key is stored securely and will never be displayed again after saving — this is intentional.

> If you prefer to keep credentials out of the database entirely, you can define the key as a constant in `wp-config.php`:
> ```php
> define( 'WSI_WR_SHIPSTATION_API_KEY', 'your-api-key-here' );
> ```
> When defined this way, the settings page will show "API key configured via wp-config.php" and the field will be hidden.

### Step 2 — Test the connection

Still on the settings page, click **Test Connection** in the API Tools section. You should see a success message confirming that the API key is valid and ShipStation is reachable.

If the test fails, check that:
- The API key was entered correctly before saving
- Your server can reach `api.shipstation.com` (no firewall blocking outbound HTTPS)

### Step 3 — Review an inventory sample

Click **Fetch Inventory Sample** to retrieve the first page of inventory from ShipStation. Review the results table — confirm that the **Available** column contains the quantities you expect. This column is the value the plugin will write to WooCommerce stock.

### Step 4 — Run the SKU Alignment Report

Go to **WooCommerce → ShipStation Reports → SKU Alignment** and click **Run SKU Alignment Report**.

This report compares every SKU in your ShipStation inventory against your WooCommerce products — with no changes made to stock. Review the results:

- **Matched** SKUs are ready to sync
- **Unmatched** SKUs exist in ShipStation but have no corresponding WooCommerce product — these will be skipped
- **Manage Stock Disabled** products need their WooCommerce stock management turned on before they can be synced
- **Ambiguous** SKUs match more than one WooCommerce product — duplicates must be resolved before those SKUs can sync safely

Fix any issues found before proceeding.

### Step 5 — Review the Tracked SKU Registry

Go to **WooCommerce → ShipStation Reports → Tracked SKUs**. After running the alignment report, SKUs that matched a WooCommerce product will appear here. Review the list and confirm it looks correct.

### Step 6 — Run a dry-run sync

Go to **WooCommerce → ShipStation Reports → Dry-Run Sync** and click **Run Dry-Run Sync**.

This fetches live ShipStation inventory and calculates exactly what a live sync would do — including which products would increase, which would decrease, and which would be protected. **No stock is changed.** Review the proposed actions carefully and download the CSV export for a full record.

Repeat this step as many times as needed until you are confident the results are correct.

### Step 7 — Run your first manual sync

Once the dry-run results look correct, go to **WooCommerce → ShipStation Reports → Manual Sync** and click **Run Manual Sync**.

This performs a live sync and writes the updated stock quantities to WooCommerce. Review the results after it completes. Check a few products in WooCommerce to confirm the stock values match what was proposed in the dry-run.

### Step 8 — Enable scheduled sync (optional)

When you are satisfied that the plugin is working correctly, return to **WooCommerce → Settings → Integrations → ShipStation Integration WR** and:

1. Enable **Enable Scheduled Synchronization**
2. Set your preferred daily sync times (default: 07:00, 12:00, 18:00)
3. Click **Save changes**

From this point, the plugin will run automatically at the configured times.

---

## 5. Plugin Settings Reference

Navigate to: **WooCommerce → Settings → Integrations → ShipStation Integration WR**

---

### Connection

| Setting | Description |
|---|---|
| **Enable Synchronization** | Master on/off switch. When disabled, no syncs will run — scheduled, manual, or dry-run. |
| **ShipStation API Key** | Your ShipStation V2 API key. Always submitted as a password field. Leave blank on save to keep the existing key. |

---

### API Tools

| Tool | Description |
|---|---|
| **Test Connection** | Sends a minimal read-only request to ShipStation to confirm the API key is valid and the server can connect. No inventory is fetched. |
| **Fetch Inventory Sample** | Retrieves the first page of inventory (up to the configured page size) and displays SKU, on-hand, and available quantities. Useful for verifying the data structure before running a full sync. |

---

### Synchronization Behaviour

| Setting | Description | Default |
|---|---|---|
| **Dry-Run Mode** | When enabled, all sync operations calculate and report proposed changes but do not write to WooCommerce. Disable this only after reviewing a full dry-run. | Enabled |
| **Quantity Source** | Informational. Stock is always set using ShipStation **available** quantity, not on-hand. On-hand is stored in reports for reference only. | — |
| **Missing Previously Confirmed ShipStation SKU Handling** | Controls what happens when a previously tracked SKU disappears from the ShipStation API response (which can indicate zero stock). See [Section 13](#13-safety-features-explained). | Report only |
| **Recent Order Stock Increase Protection Window (minutes)** | Before allowing a stock increase, the plugin checks for recent WooCommerce orders containing that product within this window. Prevents overselling when ShipStation has not yet processed a new order. Set to 0 to disable. | 60 |
| **Auto-Enable WooCommerce Manage Stock** | When enabled, the plugin will automatically turn on stock management for matched products that have it disabled. Off by default — products with manage stock disabled are skipped and reported instead. | Disabled |

---

### Scheduling

| Setting | Description | Default |
|---|---|---|
| **Enable Scheduled Synchronization** | When enabled, the plugin registers three daily recurring sync jobs using WooCommerce Action Scheduler. | Disabled |
| **Sync Time 1 / 2 / 3** | The three daily times to run the sync, in HH:MM format (24-hour clock, WordPress site timezone). | 07:00 / 12:00 / 18:00 |

> **Note on cron reliability:** Scheduled actions are processed by WooCommerce Action Scheduler, which depends on WordPress cron. On many shared hosting environments, WordPress cron only runs when someone visits your site. For reliable production scheduling, configure a real server-level cron job to trigger WordPress cron. Add a cron entry on your server:
> ```
> * * * * * wget -q -O - https://yoursite.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1
> ```

---

### Processing

| Setting | Description | Default |
|---|---|---|
| **API Page Size** | Number of inventory records requested per API call. Increase for large catalogues; decrease if you encounter timeout issues. | 100 |
| **API Request Timeout (seconds)** | Maximum time to wait for a ShipStation API response before treating it as a failure. | 30 |

---

### Logging & Reports

| Setting | Description | Default |
|---|---|---|
| **Enable Logging** | Records sync run metadata and per-SKU results to the plugin's database tables. Disabling this means no history will be stored. | Enabled |
| **Log Retention (days)** | Sync run and item records older than this many days are automatically cleaned up. The Tracked SKU Registry is never automatically deleted. | 30 |

---

## 6. API Tools: Test Connection and Inventory Sample

Both tools are located on the settings page under the **API Tools** heading.

### Test Connection

Click **Test Connection** to verify that your API key is valid and that your server can reach the ShipStation API.

- A **green success message** confirms everything is working.
- A **red error message** will include the HTTP status code or error description. Common causes: incorrect API key, server firewall blocking outbound requests, or a temporary ShipStation outage.

The key is never displayed in the result — only pass/fail status and the HTTP response code are shown.

### Fetch Inventory Sample

Click **Fetch Inventory Sample** to retrieve the first page of inventory records from ShipStation.

The results table shows:
- **SKU** — the ShipStation product identifier
- **On Hand** — total physical quantity in ShipStation
- **Available (sync target)** — quantity available for new orders (after allocations); this is what the plugin writes to WooCommerce

Review the Available column carefully. If the numbers look unexpected, contact ShipStation support to confirm your warehouse and allocation settings before running a live sync.

---

## 7. SKU Alignment Report

**Navigation:** WooCommerce → ShipStation Reports → SKU Alignment

The SKU Alignment Report is a **read-only diagnostic** that maps every SKU in your ShipStation inventory to a WooCommerce product. No stock is changed.

### Running the report

Click **Run SKU Alignment Report**. The report fetches your full ShipStation inventory and compares each SKU against your WooCommerce catalogue.

### Result categories

| Category | Meaning | Action Required |
|---|---|---|
| **Matched — Simple Product** | SKU found in ShipStation and in WooCommerce as a simple product | None — ready to sync |
| **Matched — Variation** | SKU found in ShipStation and in WooCommerce as a product variation | None — ready to sync |
| **Unmatched** | SKU exists in ShipStation but no WooCommerce product has that SKU | Add the SKU to the WooCommerce product, or leave it — it will be skipped |
| **Manage Stock Disabled** | SKU matched a WooCommerce product, but that product has stock management turned off | Enable "Manage Stock" on the WooCommerce product, or enable auto-enable in settings |
| **Ambiguous (Duplicate SKU)** | The SKU matches more than one WooCommerce product | Remove the duplicate SKU from WooCommerce — only one product can hold a given SKU |
| **Variation Uses Parent Stock** | The variation's stock is managed by its parent product, not per-variation | Enable per-variation stock management, or map the SKU to the parent product |
| **Not Published** | SKU matched a WooCommerce product that is in Draft or Pending status | Publish the product, or leave it — draft products are skipped during sync |
| **Empty SKU** | ShipStation returned a record with no SKU value | No action possible |

### Previous report summary

Below the Run button, the last report's summary is displayed: matched count, unmatched count, manage-stock-disabled count, and ambiguous count.

### CSV export

After running a report, a **Download CSV** button appears. The CSV contains one row per ShipStation SKU with all match details, current WooCommerce stock, ShipStation on-hand, and ShipStation available quantities.

---

## 8. Tracked SKU Registry

**Navigation:** WooCommerce → ShipStation Reports → Tracked SKUs

The Tracked SKU Registry records every ShipStation SKU that has been successfully matched to a WooCommerce product during any sync or alignment report run. This registry is the basis for the zero-stock safeguard — see [Section 13](#13-safety-features-explained).

### What the registry shows

| Column | Description |
|---|---|
| **SKU** | The ShipStation product SKU |
| **WC Product ID** | The matched WooCommerce product ID |
| **Type** | Simple or variation |
| **Status** | Active (seen recently), or Missing (absent from recent API responses) |
| **Last Seen** | Date and time the SKU last appeared in a ShipStation API response |
| **Consecutive Missing Count** | How many consecutive fully successful sync runs the SKU has been absent |
| **Sync Enabled** | Whether this SKU participates in sync (can be paused per-SKU) |

### Pausing or resuming a SKU

Each row has a **Pause** or **Resume** toggle. A paused SKU is skipped in all future syncs (dry-run, manual, and scheduled) until you resume it. Use this when a product is temporarily out of stock at ShipStation and you do not want the sync to interfere.

### Manually adding or approving a SKU

If a SKU has not appeared in a sync run yet, you can add it manually using the **Add / Approve SKU** form at the bottom of the page. Enter the SKU and the WooCommerce product ID to register it.

---

## 9. Dry-Run Sync

**Navigation:** WooCommerce → ShipStation Reports → Dry-Run Sync

A dry-run fetches live ShipStation inventory, evaluates what a sync would do, and displays the proposed actions — **without writing anything to WooCommerce**. Use this to preview changes before running a live sync.

### Running a dry-run

Click **Run Dry-Run Sync**. The plugin fetches your full ShipStation inventory, matches SKUs to WooCommerce products, applies all safety checks, and returns a categorised list of proposed actions.

### Proposed action categories

| Category | Meaning |
|---|---|
| **Proposed Increase** | ShipStation available > WooCommerce stock — stock would be increased |
| **Proposed Decrease** | ShipStation available < WooCommerce stock — stock would be decreased |
| **No Change** | ShipStation available = WooCommerce stock — no update needed |
| **Skipped — Recent Order Protection** | A stock increase was proposed but a recent WooCommerce order was found within the protection window — increase blocked to prevent overselling |
| **Skipped — Unmatched** | No WooCommerce product found for this ShipStation SKU |
| **Skipped — Manage Stock Disabled** | WooCommerce product has stock management turned off |
| **Skipped — Ambiguous SKU** | Multiple WooCommerce products share this SKU |
| **Skipped — Not Published** | The matched WooCommerce product is a draft or pending |
| **Skipped — Variation Uses Parent Stock** | The variation's stock is parent-controlled |
| **Missing Tracked SKU** | A previously matched SKU is absent from this API response — reported but no action taken (default setting) |

### Result summary box

At the top of the results, a summary box shows counts for each category. Increases are highlighted green; decreases amber; protected items blue; missing tracked SKUs red.

### Previous dry-run

Below the Run button, a summary table shows the last dry-run's counts and a **Download CSV** link.

### Tips

- Run a dry-run after any significant change to your ShipStation inventory or WooCommerce catalogue before running a live sync.
- The dry-run also updates the Tracked SKU Registry, so consecutive missing counts accumulate across dry-run and live runs.

---

## 10. Manual Sync

**Navigation:** WooCommerce → ShipStation Reports → Manual Sync

A manual sync performs a live inventory update — it writes stock quantities to WooCommerce. **This changes data.** Always run a dry-run first and review the proposed changes before running a manual sync.

### Running a manual sync

Click **Run Manual Sync**. A warning notice reminds you that stock will be written. The plugin fetches ShipStation inventory, evaluates all proposed actions, applies all safety checks, and then writes updated stock quantities to WooCommerce for all matched products.

### Result categories

Manual sync results use the same categories as dry-run, plus:

| Category | Meaning |
|---|---|
| **Applied — Increase** | Stock was successfully increased |
| **Applied — Decrease** | Stock was successfully decreased |
| **Failed** | A write was attempted but encountered an error — the SKU is listed with a reason |

### Result summary

The summary box shows **Applied** (green) and **Failed** (red) counts prominently, followed by skipped category counts.

### After the sync

Review any **Failed** items — these typically indicate a product that was deleted or had its configuration changed between the evaluation and the write. Correct the issue and re-run if needed.

Check a sample of **Applied** products in WooCommerce to confirm the stock values are correct.

---

## 11. Scheduled Sync

Once enabled in settings, the plugin runs the sync automatically at up to three configured times each day. No manual action is required.

### How it works

Each scheduled run behaves identically to a manual sync run:
- ShipStation inventory is fetched in full
- SKUs are matched and evaluated
- All safety checks are applied (order protection, zero-stock safeguards)
- Stock is written to WooCommerce (unless Dry-Run Mode is enabled in settings)
- Results are stored in the sync history database and can be reviewed via CSV export from the Manual Sync or Dry-Run pages

### Dry-Run Mode and scheduling

If **Dry-Run Mode** is enabled in settings when the scheduled sync runs, the scheduled sync will also be a dry-run — it calculates and stores the proposed actions but does not write to WooCommerce. This is the default behaviour.

To enable live stock writes via the schedule:
1. Run a full dry-run and review the results
2. Run at least one manual sync to confirm live writes work correctly
3. Disable Dry-Run Mode in settings and save

### Monitoring scheduled runs

To verify that scheduled actions are registered, go to **WooCommerce → Status → Scheduled Actions** (or **Tools → Scheduled Actions** in some WooCommerce versions) and search for `wsi_wr_shipstation_inventory_pull_run_sync`. You should see three pending recurring actions, one for each configured time slot.

If no actions appear after saving settings with the schedule enabled, check that WooCommerce and Action Scheduler are active and functioning.

### Changing scheduled times

Update the three time fields in settings and save. The existing scheduled actions are cancelled and new ones are registered at the updated times immediately.

### Disabling the schedule

Uncheck **Enable Scheduled Synchronization** and save. All pending scheduled actions are cancelled immediately.

---

## 12. Understanding Sync Results

### Stock quantity source

The plugin always uses ShipStation's **available** quantity — not **on hand**. The difference:

- **On Hand:** Total physical inventory at the warehouse
- **Available:** On hand minus quantities already allocated to pending ShipStation orders

Using **available** prevents you from showing stock as available in WooCommerce when it is already committed to an order being packed in your warehouse.

### How SKU matching works

Matching is exact and case-sensitive. The plugin looks up each ShipStation SKU in WooCommerce's product database:

1. **Simple products** — the SKU set on the product's Inventory tab
2. **Variations** — the SKU set on each individual variation's inventory

A ShipStation SKU that does not exactly match any WooCommerce product SKU is reported as **Unmatched** and skipped.

### What "no change" means

If ShipStation reports the same available quantity as the current WooCommerce stock, the product is classified as **No Change** — the plugin records the result but does not write anything.

---

## 13. Safety Features Explained

### Dry-Run Mode

Dry-Run Mode is enabled by default. When active, no sync — manual, scheduled, or triggered by any other means — will write stock to WooCommerce. All proposed changes are calculated, recorded, and displayed, but never applied.

**Recommendation:** Keep Dry-Run Mode enabled until you have reviewed at least one complete dry-run report and are confident the results are correct.

### Recent Order Stock Increase Protection

ShipStation may not immediately reflect a WooCommerce order placed seconds ago. If the plugin sees that ShipStation has a higher stock level than WooCommerce (suggesting an increase is needed), and a WooCommerce order for that product was placed within the configured protection window (default: 60 minutes), the increase is blocked and the SKU is reported as **Skipped — Recent Order Protection**.

This prevents the following scenario:
1. Customer places order — WooCommerce stock drops from 5 to 4
2. ShipStation sync runs before the order is processed — ShipStation still shows 5 available
3. Without protection, the plugin would "correct" WooCommerce back to 5 — overselling

Decreases are never blocked by order protection.

### Zero-Stock Safeguard

ShipStation omits products with zero stock from its API response entirely. A missing SKU does not mean zero stock — it could also mean the product is misconfigured, the API response was incomplete, or a filter changed.

The default setting (**Report only**) means the plugin will **never set a WooCommerce product to zero** simply because its SKU was absent from a ShipStation API response. Missing tracked SKUs are highlighted in reports for manual review.

The **Missing Previously Confirmed ShipStation SKU Handling** setting has three options:

| Option | Behaviour |
|---|---|
| **Report only** (default) | Absent tracked SKUs are flagged in reports — stock is never changed due to absence |
| **Set to zero after two consecutive fully successful absent syncs** | After two complete API runs where the SKU is absent, the plugin proposes setting WooCommerce stock to zero. Enable this only after staging validation. |
| **Ignore** | Missing tracked SKU detection is disabled entirely |

**Important:** The "set to zero" option only activates after two **fully successful, complete** API runs. API errors, timeouts, or incomplete pagination never trigger zero-stock writes.

### Variation Parent Stock Detection

WooCommerce allows variations to either manage their own stock independently or inherit stock management from their parent product. The plugin detects variations that use parent-managed stock and skips them with a clear explanation — rather than silently writing to the parent product.

To sync a variation that has its own ShipStation SKU, you must enable per-variation stock management on that variation in WooCommerce.

---

## 14. CSV Exports

Every report and sync run can be exported to CSV. Download links appear on each report page after a run completes, and previous run links are always available below the Run button.

### SKU Alignment Report CSV columns

SKU, Match Type, WC Product ID, WC Stock, SS On Hand, SS Available, Notes

### Dry-Run Sync CSV columns

SKU, Proposed Action, WC Product ID, WC Current Stock, SS On Hand, SS Available, Proposed Stock, Notes

### Manual Sync CSV columns

SKU, Proposed Action, Action Taken, WC Product ID, WC Previous Stock, SS On Hand, SS Available, New Stock, Notes

CSV files are generated on demand and are not stored on the server — each download generates a fresh export from the current database records.

---

## 15. Troubleshooting

### The "Test Connection" button returns an error

- Confirm the API key was saved before testing (save settings, then test)
- Verify the API key is a ShipStation V2 key (V1 keys will not work)
- Ask your hosting provider if outbound HTTPS connections to `api.shipstation.com` are allowed

### No inventory records appear in the sample fetch

- Confirm your ShipStation account has inventory configured and products have on-hand quantities above zero
- ShipStation omits zero-stock items — if all products are at zero, the response will be empty

### Many SKUs show as Unmatched in the alignment report

- Check that WooCommerce product SKUs exactly match ShipStation SKUs (case-sensitive, no extra spaces)
- Export the alignment report CSV to compare side-by-side
- In WooCommerce, SKUs are set under **Product → Inventory tab → SKU**

### A product shows Manage Stock Disabled

- Go to **WooCommerce → Products → [Product Name] → Inventory tab** and enable **Manage stock?**
- Alternatively, enable **Auto-Enable WooCommerce Manage Stock** in plugin settings

### Scheduled actions are not appearing in WooCommerce → Status → Scheduled Actions

- Confirm **Enable Scheduled Synchronization** is checked and settings have been saved
- Confirm WooCommerce is active and the Action Scheduler library is available
- Deactivate and reactivate the plugin if the actions do not appear

### A stock increase was blocked (Skipped — Recent Order Protection)

- This is expected behaviour when a WooCommerce order was placed recently
- The stock will update correctly on the next sync run once the protection window has passed
- If legitimate, reduce the protection window in settings (minimum 0 to disable entirely)

### Stock went up when I expected it to go down (or vice versa)

- Run a dry-run immediately to see what the current proposed actions are
- Check the ShipStation inventory in the **Fetch Inventory Sample** tool to see the current available quantity
- If values look wrong in ShipStation, the issue is upstream — contact ShipStation support

### The sync ran but Dry-Run Mode is on — no stock changed

- This is by design. Disable **Dry-Run Mode** in settings and save, then re-run the sync
- Verify the schedule is configured to run with Dry-Run Mode disabled if using scheduled sync

---

## 16. Glossary

| Term | Definition |
|---|---|
| **Available** | ShipStation's available quantity: on-hand stock minus allocations for pending ShipStation orders. This is the value written to WooCommerce. |
| **On Hand** | Total physical inventory in ShipStation's warehouse. Stored in reports for reference only — not used for WooCommerce stock updates. |
| **Dry-Run** | A sync that evaluates and reports all proposed changes without writing anything to WooCommerce. |
| **SKU** | Stock Keeping Unit — the unique product identifier used to match items between ShipStation and WooCommerce. Must be identical in both systems. |
| **Tracked SKU** | A ShipStation SKU that has been matched to a WooCommerce product at least once. The plugin tracks these to detect when previously matched SKUs disappear from the API. |
| **Action Scheduler** | A WooCommerce library that manages scheduled background tasks. Used to trigger the daily sync at the configured times. |
| **Order Protection** | A safeguard that blocks stock increases for products with recent WooCommerce orders, preventing overselling when ShipStation has not yet processed the order. |
| **Alignment Report** | A read-only comparison of ShipStation SKUs against WooCommerce products. Used to identify unmatched, ambiguous, or misconfigured SKUs before running a sync. |
| **HPOS** | High Performance Order Storage — WooCommerce's modern order database architecture. This plugin is fully compatible with HPOS. |
| **Pagination Complete** | A flag indicating that the plugin successfully retrieved all pages of inventory from the ShipStation API in a single run. Zero-stock safety evaluations only run on complete API fetches. |

---

*For issues or questions, contact your site administrator or the plugin developer.*
