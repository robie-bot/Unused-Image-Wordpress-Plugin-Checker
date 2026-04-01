# Unused Image Finder - WordPress Plugin Documentation

## Overview

**Unused Image Finder** scans your WordPress media library to identify images that are not referenced anywhere on your site - posts, pages, WooCommerce products, page builders, widgets, theme settings, or CSS. It helps reclaim disk space by safely finding and optionally removing unused images.

**Current Version:** 2.3.6
**GitHub:** https://github.com/robie-bot/Unused-Image-Wordpress-Plugin-Checker

---

## Installation

1. Download or clone the plugin from GitHub
2. Upload the `unused-image-finder` folder to `/wp-content/plugins/`
3. Activate the plugin in **Plugins > Installed Plugins**
4. Access the scanner at **Media > Unused Images**

---

## How to Use

### Step 1: Run the Scan

1. Go to **Media > Unused Images**
2. Click **"Scan for Unused Images"**
3. The scan runs in 7 phases (each shown in the progress bar):
   - Phase 1: Core content (featured images, post content, galleries)
   - Phase 2: WooCommerce, widgets, options, site icons
   - Phase 3: ACF & Elementor
   - Phase 4: Divi, WPBakery & Impreza
   - Phase 5: CSS background images
   - Phase 6: Filename search (cross-domain/staging URL detection)
   - Phase 7: Imagify / WebP / AVIF protection
4. After all phases complete, the plugin loads image details in batches

**Note:** If a phase times out (common on shared hosting for Phase 6), it will retry 3 times then **skip and continue**. A yellow warning will indicate which phase was skipped. The scan will still complete - the skipped phase is an extra safety check, not critical.

### Step 2: Review Results via CSV Export

After the scan completes, you will see:
- **Stat cards** showing Total Images, Used, Unused, and Space Recoverable
- A table listing unused images (50 per page)

**IMPORTANT: Use the CSV export as the primary method to review all unused images.** The plugin table pagination may only display a limited number of pages due to hosting/server constraints. The CSV contains the **complete list** regardless of pagination.

#### To export the full list:
1. Click **"Export CSV"** after a scan completes
2. A `.csv` file downloads with ALL unused images (not limited to what's visible in the table)
3. CSV columns: ID, Title, Filename, URL, File Size (bytes), File Size (readable), Upload Date

**Use the exported CSV to review the full unused image list in ClickUp or any spreadsheet tool.**

### Step 3: Delete Images (Optional)

**CRITICAL: To delete images, you MUST rescan first.** The plugin table may only show a partial list (due to pagination/batch loading limits). Do NOT rely solely on what the table displays. Always follow this process:

1. **Scan first** - Click "Scan for Unused Images" and wait for it to complete
2. **Export CSV** - Click "Export CSV" to download the full list
3. **Review the CSV carefully** - Open it in a spreadsheet and verify images are truly unused. Spot-check by visiting image URLs in your browser
4. **Rescan before deleting** - Always run a fresh scan immediately before deleting. Do NOT delete based on an old scan
5. **Disable Safe Mode** - Uncheck "Safe Mode (Dry Run)" (you will be asked to confirm)
6. **Select and delete** - Use checkboxes or "Select All Pages" then click "Delete Selected"
7. **Confirm deletion** - A confirmation prompt appears before any deletion

**WARNING:**
- Deletion is **permanent** and cannot be undone
- The plugin table pagination may only show ~100 images (2 pages) even if there are 300+ unused images. This is a display limitation - the **CSV always has the full list**
- Always verify images in the CSV before deleting
- **Never delete images based on an old/stale scan** - always rescan first
- Safe Mode is ON by default to prevent accidental deletion

---

## What the Plugin Detects

The scanner checks all of the following locations to determine if an image is "used":

| Source | What It Checks |
|--------|---------------|
| **Featured Images** | Post thumbnails on all post types |
| **Post/Page Content** | Images embedded in post_content (any post type), including relative paths |
| **Galleries** | WordPress `[gallery]` shortcode IDs |
| **WooCommerce** | Product images, gallery images, variation images, category thumbnails, placeholder image |
| **Theme Options** | Custom logo, custom header, custom background, site icon/favicon |
| **Widgets** | All widget data (image widgets, text widgets with images, custom widgets) |
| **ACF (Advanced Custom Fields)** | Image fields, gallery fields, flexible content, repeaters |
| **Elementor** | All element data stored in `_elementor_data` postmeta |
| **Divi Builder** | `[et_pb_*]` shortcodes with image/url/logo/background attributes |
| **WPBakery (Visual Composer)** | `[vc_*]` shortcodes with image/images/img_id attributes |
| **Impreza** | Grid layouts, page blocks, content templates, headers, footers, `[us_*]` shortcodes, all Impreza postmeta, term meta, theme options (`usof_options`), raw HTML widgets |
| **CSS Backgrounds** | `background:url()` and `background-image:url()` in post content, postmeta, options, custom CSS, Customizer (including relative paths) |
| **Relative Paths** | Images referenced via `/wp-content/uploads/...` without a domain (hover images, lazy-loaded, JS-injected) |
| **Filename Search** | Searches all `post_content` for each image's filename (catches staging URLs, CDN URLs, and cross-domain references) |
| **Imagify / WebP / AVIF** | Protects original images when their optimized WebP/AVIF versions are in use; protects all Imagify-optimized images and WP backup sizes from deletion |

### What the Plugin Does NOT Check

- **Front-end rendered HTML** - The plugin only checks the database, not the live front-end output
- **PHP template files** - Images hardcoded in theme `.php` files are not detected
- **External JavaScript files** - Images loaded dynamically by JS files are not detected
- **Plugin asset files** - Files inside `/wp-content/plugins/` are never touched (only `/wp-content/uploads/`)

---

## Cross-Domain / Staging URL Detection

The plugin handles images referenced via staging or CDN URLs that differ from the production domain:

1. **Path-based matching** - Extracts the `/wp-content/uploads/` path and matches regardless of domain
2. **Filename search** - Searches all post content for each image's filename (e.g., `logo2.svg`) regardless of what domain appears in the URL
3. **Cross-domain URL resolution** - Strips the domain from URLs and rebuilds them with the local site URL for ID lookup
4. **Relative path detection** - Catches paths like `/wp-content/uploads/2021/09/file.svg` without any domain

---

## Known Limitations

| Limitation | Details |
|-----------|---------|
| **Pagination shows limited pages** | The plugin table may only load ~100 images (2 pages) even when 300+ exist. This is due to server batch loading constraints. **Always use CSV export for the full list.** |
| **Phase 6 may time out** | The filename search (Phase 6) is the heaviest phase and may time out on shared hosting. It will be skipped automatically - other phases still catch most images. |
| **No front-end crawling** | The plugin checks the database only, not the rendered website. Images loaded purely via JS or PHP templates may not be detected. |
| **Permanent deletion** | Deleted images cannot be recovered. Always review CSV and rescan before deleting. |

---

## Performance Notes

| Site Size | Expected Behavior |
|-----------|-------------------|
| Small (< 500 images) | Scan completes in seconds |
| Medium (500-1500 images) | Scan takes 30-90 seconds across all phases |
| Large (1500+ images, 4GB+) | Some phases may time out and be skipped; scan still completes with most detection working |

### If scans are slow or time out:
- The plugin splits work into 7 separate server requests (phases)
- Each phase has a 120-second timeout with 3 retries
- Failed phases are automatically skipped - the scan still completes
- Phase 6 (filename search) is the heaviest and most likely to time out on shared hosting - this is normal
- Batch loading has a 300ms delay between requests to prevent server rate-limiting
- The CSV export always works regardless of timeout issues

---

## Recommended Workflow

1. **Scan** - Click "Scan for Unused Images"
2. **Export** - Click "Export CSV" to download the full list
3. **Review** - Open CSV in a spreadsheet or upload to ClickUp for team review
4. **Verify** - Spot-check flagged images by visiting their URLs before deleting
5. **Rescan** - Run a fresh scan immediately before deleting (do NOT use old scan results)
6. **Delete** - Only after thorough review and a fresh scan, disable Safe Mode and delete confirmed unused images

---

## Important Notes

- The plugin **only scans the media library** (`/wp-content/uploads/`). It does not touch plugin assets (e.g., `/wp-content/plugins/us-core/assets/images/placeholder.svg`)
- **Safe Mode is ON by default** - no images can be accidentally deleted
- SVG files are included in the scan
- The plugin requires the `manage_options` capability (admin users only)
- All AJAX requests use WordPress nonce verification for security
- Scan results are stored in a transient (valid for 1 hour) - if you wait too long between scanning and exporting, you may need to rescan
- **Always rescan before deleting** - never delete based on stale results

---

## File Structure

```
unused-image-finder/
|-- unused-image-finder.php          # Main plugin file, constants & loader
|-- includes/
|   |-- class-uif-scanner.php        # All detection methods & scan logic
|   |-- class-uif-admin.php          # Admin UI, AJAX handlers, CSV export
|-- assets/
|   |-- admin.js                     # Client-side scan, pagination, UI
|   |-- admin.css                    # Admin page styles
```

---

## Version History

| Version | Changes |
|---------|---------|
| 2.3.6 | Add delay between batch requests to prevent server rate-limiting |
| 2.3.5 | Fix image list stuck at 50 - skip failed batches, increase batch size to 50 |
| 2.3.4 | Detect images referenced via relative paths (no domain) |
| 2.3.3 | Fix scan aborting when a phase times out - now skips and continues |
| 2.3.2 | Fix Impreza grid layout images not detected as used |
| 2.3.1 | Fix phase 6 timeout - replace LIKE queries with chunk+strpos |
| 2.3.0 | Split scan into 7 phases to prevent gateway timeout |
| 2.2.2 | Fix 504 timeout - stop scanning postmeta/options/termmeta in filename search |
| 2.2.1 | Fix 500 error - replace memory blob with batched OR queries |
| 2.2.0 | Fix 504 timeout - replace per-image SQL with single content blob |
| 2.1.1 | Fix 504 timeout - optimize Imagify detection with bulk queries |
| 2.1.0 | Add Imagify/WebP/AVIF awareness to prevent false positives |
| 2.0.0 | Add filename-based database search (domain-agnostic detection) |
| 1.8.0 | Fix cross-domain detection bugs, WooCommerce placeholder, staging URLs |
| 1.7.0 | Add Safe Mode (dry run), deep Impreza scanning |
| 1.6.0 | Server-side CSV export, batched scanning, pagination |
| 1.0.0 | Initial release |
