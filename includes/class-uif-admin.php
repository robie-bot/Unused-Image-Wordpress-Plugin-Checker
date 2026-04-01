<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class UIF_Admin {

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'wp_ajax_uif_scan_init', array( __CLASS__, 'ajax_scan_init' ) );
        add_action( 'wp_ajax_uif_scan_phase', array( __CLASS__, 'ajax_scan_phase' ) );
        add_action( 'wp_ajax_uif_scan_finalize', array( __CLASS__, 'ajax_scan_finalize' ) );
        add_action( 'wp_ajax_uif_scan_batch', array( __CLASS__, 'ajax_scan_batch' ) );
        add_action( 'wp_ajax_uif_delete', array( __CLASS__, 'ajax_delete' ) );
        add_action( 'wp_ajax_uif_orphan_phase', array( __CLASS__, 'ajax_orphan_phase' ) );
        add_action( 'wp_ajax_uif_orphan_delete', array( __CLASS__, 'ajax_orphan_delete' ) );
        add_action( 'admin_init', array( __CLASS__, 'handle_csv_export' ) );

        // Media Library integration — "Unused" filter tab.
        add_filter( 'views_upload', array( __CLASS__, 'add_unused_media_view' ) );
        add_action( 'pre_get_posts', array( __CLASS__, 'filter_unused_media' ) );
        add_filter( 'manage_media_columns', array( __CLASS__, 'add_usage_column' ) );
        add_action( 'manage_media_custom_column', array( __CLASS__, 'render_usage_column' ), 10, 2 );
    }

    public static function add_menu() {
        add_media_page(
            __( 'Unused Image Finder', 'unused-image-finder' ),
            __( 'Unused Images', 'unused-image-finder' ),
            'manage_options',
            'unused-image-finder',
            array( __CLASS__, 'render_page' )
        );
    }

    public static function enqueue_assets( $hook ) {
        if ( 'media_page_unused-image-finder' !== $hook ) {
            return;
        }

        wp_enqueue_style(
            'uif-admin',
            UIF_PLUGIN_URL . 'assets/admin.css',
            array(),
            UIF_VERSION
        );

        wp_enqueue_script(
            'uif-admin',
            UIF_PLUGIN_URL . 'assets/admin.js',
            array( 'jquery' ),
            UIF_VERSION,
            true
        );

        wp_localize_script( 'uif-admin', 'uif', array(
            'ajax_url'   => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( 'uif_nonce' ),
            'batch_size' => 25,
            'per_page'   => 50,
            'csv_url'    => wp_nonce_url( admin_url( 'admin.php?page=unused-image-finder&uif_export_csv=1' ), 'uif_csv_export' ),
            'i18n'       => array(
                'scanning'      => __( 'Scanning media library...', 'unused-image-finder' ),
                'scanning_pct'  => __( 'Processing images: %d%%', 'unused-image-finder' ),
                'building'      => __( 'Building unused image list: %d of %d', 'unused-image-finder' ),
                'confirm'       => __( 'Are you sure you want to permanently delete the selected images? This cannot be undone.', 'unused-image-finder' ),
                'deleting'      => __( 'Deleting...', 'unused-image-finder' ),
                'deleted'       => __( 'Deleted successfully.', 'unused-image-finder' ),
                'error'         => __( 'An error occurred. Please try again.', 'unused-image-finder' ),
                'no_selection'  => __( 'Please select at least one image.', 'unused-image-finder' ),
            ),
        ) );
    }

    /**
     * Add an "Unused" link to the Media Library filter views.
     * Only shows if a scan has been run (results stored in transient).
     */
    public static function add_unused_media_view( $views ) {
        $unused_ids = get_transient( 'uif_unused_ids' );
        if ( ! is_array( $unused_ids ) || empty( $unused_ids ) ) {
            return $views;
        }

        $count   = count( $unused_ids );
        $current = ( isset( $_GET['uif_unused'] ) && '1' === $_GET['uif_unused'] ) ? 'current' : '';

        $views['uif_unused'] = sprintf(
            '<a href="%s" class="%s">%s <span class="count">(%s)</span></a>',
            esc_url( admin_url( 'upload.php?uif_unused=1' ) ),
            $current,
            __( 'Unused', 'unused-image-finder' ),
            number_format_i18n( $count )
        );

        return $views;
    }

    /**
     * Filter the Media Library query to show only unused images when the filter is active.
     */
    public static function filter_unused_media( $query ) {
        if ( ! is_admin() || ! $query->is_main_query() ) {
            return;
        }

        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || 'upload' !== $screen->id ) {
            return;
        }

        if ( ! isset( $_GET['uif_unused'] ) || '1' !== $_GET['uif_unused'] ) {
            return;
        }

        $unused_ids = get_transient( 'uif_unused_ids' );
        if ( ! is_array( $unused_ids ) || empty( $unused_ids ) ) {
            // No scan results — show nothing.
            $query->set( 'post__in', array( 0 ) );
            return;
        }

        $query->set( 'post__in', $unused_ids );
        $query->set( 'post_type', 'attachment' );
        $query->set( 'post_status', 'inherit' );
    }

    /**
     * Add a "Usage" column to the Media Library list table.
     */
    public static function add_usage_column( $columns ) {
        $unused_ids = get_transient( 'uif_unused_ids' );
        if ( ! is_array( $unused_ids ) || empty( $unused_ids ) ) {
            return $columns;
        }

        $columns['uif_usage'] = __( 'Usage', 'unused-image-finder' );
        return $columns;
    }

    /**
     * Render the "Usage" column content.
     */
    public static function render_usage_column( $column_name, $post_id ) {
        if ( 'uif_usage' !== $column_name ) {
            return;
        }

        $unused_ids = get_transient( 'uif_unused_ids' );
        if ( is_array( $unused_ids ) && in_array( $post_id, $unused_ids, true ) ) {
            echo '<span style="color:#d63638;font-weight:600;">&#9888; Unused</span>';
        } else {
            echo '<span style="color:#00a32a;">&#10003; Used</span>';
        }
    }

    public static function render_page() {
        ?>
        <div class="wrap uif-wrap">
            <h1><?php esc_html_e( 'Unused Image Finder', 'unused-image-finder' ); ?></h1>

            <div class="uif-intro">
                <p><?php esc_html_e( 'Scan your media library to find images that are not used in any posts, pages, widgets, WooCommerce products, or theme settings.', 'unused-image-finder' ); ?></p>
                <div class="uif-controls">
                    <button id="uif-scan-btn" class="button button-primary button-hero">
                        <?php esc_html_e( 'Scan for Unused Images', 'unused-image-finder' ); ?>
                    </button>
                    <label class="uif-dry-run-toggle">
                        <input type="checkbox" id="uif-dry-run" checked />
                        <strong><?php esc_html_e( 'Safe Mode (Dry Run)', 'unused-image-finder' ); ?></strong>
                        <span class="uif-dry-run-desc"><?php esc_html_e( '— Disables all delete buttons. Uncheck to enable deletion.', 'unused-image-finder' ); ?></span>
                    </label>
                </div>
            </div>

            <div id="uif-dry-run-banner" class="notice notice-info uif-dry-run-banner">
                <p><strong><?php esc_html_e( 'Safe Mode is ON', 'unused-image-finder' ); ?></strong> — <?php esc_html_e( 'Deletion is disabled. You can scan and export CSV only. Uncheck "Safe Mode" above to enable deletion.', 'unused-image-finder' ); ?></p>
            </div>

            <div id="uif-progress" class="uif-progress" style="display:none;">
                <div class="uif-progress-header">
                    <span class="spinner is-active"></span>
                    <span id="uif-progress-text"><?php esc_html_e( 'Scanning...', 'unused-image-finder' ); ?></span>
                </div>
                <div class="uif-progress-bar-wrap">
                    <div class="uif-progress-bar" id="uif-progress-bar" style="width:0%"></div>
                </div>
                <span id="uif-progress-detail" class="uif-progress-detail"></span>
            </div>

            <div id="uif-results" style="display:none;">
                <div class="uif-stats">
                    <div class="uif-stat-card">
                        <span class="uif-stat-number" id="uif-total">0</span>
                        <span class="uif-stat-label"><?php esc_html_e( 'Total Images', 'unused-image-finder' ); ?></span>
                    </div>
                    <div class="uif-stat-card">
                        <span class="uif-stat-number" id="uif-used">0</span>
                        <span class="uif-stat-label"><?php esc_html_e( 'Used', 'unused-image-finder' ); ?></span>
                    </div>
                    <div class="uif-stat-card uif-stat-warning">
                        <span class="uif-stat-number" id="uif-unused">0</span>
                        <span class="uif-stat-label"><?php esc_html_e( 'Unused', 'unused-image-finder' ); ?></span>
                    </div>
                    <div class="uif-stat-card">
                        <span class="uif-stat-number" id="uif-size">0 MB</span>
                        <span class="uif-stat-label"><?php esc_html_e( 'Space Recoverable', 'unused-image-finder' ); ?></span>
                    </div>
                </div>

                <div id="uif-table-wrap" style="display:none;">
                    <div class="uif-table-actions">
                        <label>
                            <input type="checkbox" id="uif-select-all" />
                            <?php esc_html_e( 'Select All (this page)', 'unused-image-finder' ); ?>
                        </label>
                        <label>
                            <input type="checkbox" id="uif-select-all-pages" />
                            <?php esc_html_e( 'Select All Pages', 'unused-image-finder' ); ?>
                        </label>
                        <button id="uif-delete-btn" class="button button-secondary" disabled>
                            <?php esc_html_e( 'Delete Selected', 'unused-image-finder' ); ?>
                        </button>
                        <button id="uif-export-csv-btn" class="button button-secondary">
                            <?php esc_html_e( 'Export CSV', 'unused-image-finder' ); ?>
                        </button>
                        <span id="uif-selected-count"></span>
                    </div>

                    <!-- Pagination top -->
                    <div class="uif-pagination" id="uif-pagination-top"></div>

                    <table class="wp-list-table widefat fixed striped" id="uif-table">
                        <thead>
                            <tr>
                                <th class="uif-col-cb"><input type="checkbox" id="uif-select-all-top" /></th>
                                <th class="uif-col-thumb"><?php esc_html_e( 'Thumbnail', 'unused-image-finder' ); ?></th>
                                <th class="uif-col-title"><?php esc_html_e( 'Title / Filename', 'unused-image-finder' ); ?></th>
                                <th class="uif-col-size"><?php esc_html_e( 'Size', 'unused-image-finder' ); ?></th>
                                <th class="uif-col-date"><?php esc_html_e( 'Date', 'unused-image-finder' ); ?></th>
                                <th class="uif-col-actions"><?php esc_html_e( 'Actions', 'unused-image-finder' ); ?></th>
                            </tr>
                        </thead>
                        <tbody id="uif-tbody"></tbody>
                    </table>

                    <!-- Pagination bottom -->
                    <div class="uif-pagination" id="uif-pagination-bottom"></div>
                </div>

                <div id="uif-empty" style="display:none;">
                    <p class="uif-success"><?php esc_html_e( 'No unused images found. Your media library is clean!', 'unused-image-finder' ); ?></p>
                </div>
            </div>

            <div id="uif-notice" class="notice" style="display:none;"></div>

            <hr style="margin:40px 0 30px;" />

            <h2><?php esc_html_e( 'Orphaned Files (Disk Scan)', 'unused-image-finder' ); ?></h2>
            <div class="uif-intro">
                <p><?php esc_html_e( 'Scan the /wp-content/uploads/ folder for image files that exist on disk but are NOT registered in the WordPress media library. These are files uploaded via FTP, leftover thumbnails from deleted images, or optimizer artifacts.', 'unused-image-finder' ); ?></p>
                <button id="uif-orphan-scan-btn" class="button button-secondary">
                    <?php esc_html_e( 'Scan for Orphaned Files', 'unused-image-finder' ); ?>
                </button>
            </div>

            <div id="uif-orphan-progress" style="display:none;">
                <div class="uif-progress-header">
                    <span class="spinner is-active"></span>
                    <span id="uif-orphan-progress-text"><?php esc_html_e( 'Scanning uploads folder...', 'unused-image-finder' ); ?></span>
                </div>
                <div class="uif-progress-bar-wrap" style="margin-top:10px;">
                    <div id="uif-orphan-progress-bar" class="uif-progress-bar" style="width:0%"></div>
                </div>
            </div>

            <div id="uif-orphan-results" style="display:none;">
                <div class="uif-stats">
                    <div class="uif-stat-card uif-stat-warning">
                        <span class="uif-stat-number" id="uif-orphan-count">0</span>
                        <span class="uif-stat-label"><?php esc_html_e( 'Orphaned Files', 'unused-image-finder' ); ?></span>
                    </div>
                    <div class="uif-stat-card">
                        <span class="uif-stat-number" id="uif-orphan-size">0 MB</span>
                        <span class="uif-stat-label"><?php esc_html_e( 'Disk Space', 'unused-image-finder' ); ?></span>
                    </div>
                </div>

                <div id="uif-orphan-table-wrap" style="display:none;">
                    <div class="uif-table-actions">
                        <label>
                            <input type="checkbox" id="uif-orphan-select-all" />
                            <?php esc_html_e( 'Select All', 'unused-image-finder' ); ?>
                        </label>
                        <button id="uif-orphan-delete-btn" class="button button-secondary" disabled>
                            <?php esc_html_e( 'Delete Selected Files', 'unused-image-finder' ); ?>
                        </button>
                        <span id="uif-orphan-selected-count"></span>
                    </div>

                    <table class="wp-list-table widefat fixed striped" id="uif-orphan-table">
                        <thead>
                            <tr>
                                <th class="uif-col-cb"><input type="checkbox" id="uif-orphan-select-all-top" /></th>
                                <th class="uif-col-thumb"><?php esc_html_e( 'Preview', 'unused-image-finder' ); ?></th>
                                <th class="uif-col-title"><?php esc_html_e( 'File Path', 'unused-image-finder' ); ?></th>
                                <th class="uif-col-size"><?php esc_html_e( 'Size', 'unused-image-finder' ); ?></th>
                                <th class="uif-col-date"><?php esc_html_e( 'Modified', 'unused-image-finder' ); ?></th>
                            </tr>
                        </thead>
                        <tbody id="uif-orphan-tbody"></tbody>
                    </table>
                </div>

                <div id="uif-orphan-empty" style="display:none;">
                    <p class="uif-success"><?php esc_html_e( 'No orphaned files found. Your uploads folder is clean!', 'unused-image-finder' ); ?></p>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Step 1: Get all image IDs and return phase info.
     * Lightweight — just counts images and tells JS how many phases to run.
     */
    public static function ajax_scan_init() {
        check_ajax_referer( 'uif_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        @set_time_limit( 120 );
        wp_raise_memory_limit( 'admin' );

        $all_ids = UIF_Scanner::get_all_image_ids();
        $phases  = UIF_Scanner::get_scan_phases();

        // Store all IDs for later.
        $uid = get_current_user_id();
        set_transient( 'uif_all_ids_' . $uid, $all_ids, HOUR_IN_SECONDS );
        // Reset used IDs accumulator.
        set_transient( 'uif_used_ids_' . $uid, array(), HOUR_IN_SECONDS );

        $phase_labels = array();
        foreach ( $phases as $p ) {
            $phase_labels[] = $p['label'];
        }

        wp_send_json_success( array(
            'total_images' => count( $all_ids ),
            'total_phases' => count( $phases ),
            'phase_labels' => $phase_labels,
        ) );
    }

    /**
     * Step 2: Run a single detection phase.
     * Called repeatedly by JS for each phase index.
     */
    public static function ajax_scan_phase() {
        check_ajax_referer( 'uif_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        @set_time_limit( 120 );
        wp_raise_memory_limit( 'admin' );

        $phase_index = isset( $_POST['phase'] ) ? absint( $_POST['phase'] ) : 0;
        $uid         = get_current_user_id();

        $used_so_far = get_transient( 'uif_used_ids_' . $uid );
        if ( false === $used_so_far ) {
            $used_so_far = array();
        }

        $found = UIF_Scanner::run_scan_phase( $phase_index, $used_so_far );

        // Accumulate used IDs.
        $used_so_far = array_unique( array_merge( $used_so_far, $found ) );
        set_transient( 'uif_used_ids_' . $uid, $used_so_far, HOUR_IN_SECONDS );

        wp_send_json_success( array(
            'phase'      => $phase_index,
            'found'      => count( $found ),
            'total_used' => count( $used_so_far ),
        ) );
    }

    /**
     * Step 3: Finalize — compute unused IDs from all_ids - used_ids.
     */
    public static function ajax_scan_finalize() {
        check_ajax_referer( 'uif_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $uid     = get_current_user_id();
        $all_ids = get_transient( 'uif_all_ids_' . $uid );
        $used    = get_transient( 'uif_used_ids_' . $uid );

        if ( false === $all_ids ) {
            wp_send_json_error( 'Scan expired. Please run the scan again.' );
        }

        if ( false === $used ) {
            $used = array();
        }

        $used    = apply_filters( 'uif_used_image_ids', $used );
        $used    = array_unique( array_filter( array_map( 'absint', $used ) ) );
        $unused  = array_values( array_diff( $all_ids, $used ) );

        // Store for batch loading + CSV export.
        set_transient( 'uif_unused_ids_' . $uid, $unused, HOUR_IN_SECONDS );

        // Also store a shared copy for the Media Library "Unused" filter tab.
        set_transient( 'uif_unused_ids', $unused, HOUR_IN_SECONDS );

        // Clean up temp transients.
        delete_transient( 'uif_all_ids_' . $uid );
        delete_transient( 'uif_used_ids_' . $uid );

        wp_send_json_success( array(
            'total_images' => count( $all_ids ),
            'used_count'   => count( $used ),
            'unused_count' => count( $unused ),
        ) );
    }

    /**
     * Step 2: Fetch metadata for a batch of unused images.
     * Called repeatedly with offset until all images are loaded.
     */
    public static function ajax_scan_batch() {
        check_ajax_referer( 'uif_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        // Ensure each batch gets enough time.
        @set_time_limit( 120 );
        wp_raise_memory_limit( 'admin' );

        $offset     = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
        $batch_size = isset( $_POST['batch_size'] ) ? absint( $_POST['batch_size'] ) : 25;

        // Cap batch size to prevent abuse.
        $batch_size = min( $batch_size, 100 );

        $unused_ids = get_transient( 'uif_unused_ids_' . get_current_user_id() );

        if ( false === $unused_ids ) {
            wp_send_json_error( 'Scan expired. Please run the scan again.' );
        }

        $batch = array_slice( $unused_ids, $offset, $batch_size );
        $images = array();
        $batch_size_bytes = 0;

        foreach ( $batch as $id ) {
            $url       = wp_get_attachment_url( $id );
            $metadata  = wp_get_attachment_metadata( $id );
            $file_path = get_attached_file( $id );
            $filesize  = file_exists( $file_path ) ? filesize( $file_path ) : 0;

            // Also count thumbnail sizes on disk.
            if ( ! empty( $metadata['sizes'] ) && $file_path ) {
                $dir = dirname( $file_path );
                foreach ( $metadata['sizes'] as $size ) {
                    $thumb = $dir . '/' . $size['file'];
                    if ( file_exists( $thumb ) ) {
                        $filesize += filesize( $thumb );
                    }
                }
            }

            $img_data = array(
                'id'        => (int) $id,
                'url'       => $url,
                'title'     => get_the_title( $id ),
                'filename'  => basename( $file_path ),
                'filesize'  => $filesize,
                'date'      => get_the_date( 'Y-m-d', $id ),
                'edit_link' => get_edit_post_link( $id, 'raw' ),
            );

            $images[] = $img_data;
            $batch_size_bytes += $filesize;
        }

        wp_send_json_success( array(
            'images'     => $images,
            'batch_size' => $batch_size_bytes,
            'has_more'   => ( $offset + $batch_size ) < count( $unused_ids ),
            'total'      => count( $unused_ids ),
        ) );
    }

    /**
     * Server-side streaming CSV export.
     * Uses the transient from scan_init, fetches metadata via direct SQL in one query.
     */
    public static function handle_csv_export() {
        if ( empty( $_GET['uif_export_csv'] ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }

        check_admin_referer( 'uif_csv_export' );

        @set_time_limit( 600 );
        wp_raise_memory_limit( 'admin' );

        $unused_ids = get_transient( 'uif_unused_ids_' . get_current_user_id() );

        if ( false === $unused_ids || empty( $unused_ids ) ) {
            wp_die( 'No scan data found. Please run a scan first, then export.' );
        }

        global $wpdb;

        // Get all attachment data in one SQL query.
        $placeholders = implode( ',', array_fill( 0, count( $unused_ids ), '%d' ) );
        $query = $wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_date,
                    pm.meta_value AS attached_file
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wp_attached_file'
             WHERE p.ID IN ($placeholders)
             ORDER BY p.post_date DESC",
            $unused_ids
        );

        $rows = $wpdb->get_results( $query );

        $upload_dir = wp_upload_dir();
        $base_path  = $upload_dir['basedir'];
        $base_url   = $upload_dir['baseurl'];

        // Stream CSV headers.
        $filename = 'unused-images-' . gmdate( 'Y-m-d-His' ) . '.csv';

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $output = fopen( 'php://output', 'w' );

        // UTF-8 BOM for Excel.
        fwrite( $output, "\xEF\xBB\xBF" );

        // Header row.
        fputcsv( $output, array( 'ID', 'Title', 'Filename', 'URL', 'File Size (bytes)', 'File Size (readable)', 'Upload Date' ) );

        $total_size   = 0;
        $total_count  = 0;
        $chunk_count  = 0;

        foreach ( $rows as $row ) {
            $file_rel  = $row->attached_file;
            $file_path = $file_rel ? $base_path . '/' . $file_rel : '';
            $file_url  = $file_rel ? $base_url . '/' . $file_rel : '';
            $filename_only = $file_rel ? basename( $file_rel ) : '';

            $filesize = 0;
            if ( $file_path && file_exists( $file_path ) ) {
                $filesize = filesize( $file_path );
            }

            $readable_size = self::format_bytes( $filesize );

            fputcsv( $output, array(
                $row->ID,
                $row->post_title,
                $filename_only,
                $file_url,
                $filesize,
                $readable_size,
                $row->post_date,
            ) );

            $total_size += $filesize;
            $total_count++;
            $chunk_count++;

            // Flush every 100 rows to keep memory low.
            if ( $chunk_count >= 100 ) {
                $chunk_count = 0;
                flush();
            }
        }

        // Summary rows.
        fputcsv( $output, array() );
        fputcsv( $output, array( 'Summary' ) );
        fputcsv( $output, array( 'Unused Images', $total_count ) );
        fputcsv( $output, array( 'Total Recoverable Space', self::format_bytes( $total_size ) ) );

        fclose( $output );
        exit;
    }

    private static function format_bytes( $bytes ) {
        if ( $bytes === 0 ) return '0 B';
        $units = array( 'B', 'KB', 'MB', 'GB' );
        $i = floor( log( $bytes ) / log( 1024 ) );
        return round( $bytes / pow( 1024, $i ), 1 ) . ' ' . $units[ $i ];
    }

    public static function ajax_delete() {
        check_ajax_referer( 'uif_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) $_POST['ids'] ) : array();

        if ( empty( $ids ) ) {
            wp_send_json_error( 'No images selected.' );
        }

        $deleted = 0;
        foreach ( $ids as $id ) {
            if ( 'attachment' !== get_post_type( $id ) ) {
                continue;
            }
            if ( ! wp_attachment_is_image( $id ) ) {
                continue;
            }
            if ( wp_delete_attachment( $id, true ) ) {
                $deleted++;
            }
        }

        wp_send_json_success( array(
            'deleted' => $deleted,
            'total'   => count( $ids ),
        ) );
    }

    /**
     * Scan the uploads folder for orphaned files.
     */
    /**
     * Phased orphan scan handler. Accepts a 'phase' parameter (1-3).
     * Phase 1: Disk scan (find files not in DB)
     * Phase 2: Check references in post_content
     * Phase 3: Check references in wp_options & finalize results
     */
    public static function ajax_orphan_phase() {
        check_ajax_referer( 'uif_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        @set_time_limit( 300 );
        wp_raise_memory_limit( 'admin' );

        $phase = isset( $_POST['phase'] ) ? intval( $_POST['phase'] ) : 0;

        switch ( $phase ) {
            case 1:
                $result = UIF_Scanner::orphan_scan_disk();
                wp_send_json_success( array(
                    'phase' => 1,
                    'found' => $result['found'],
                ) );
                break;

            case 2:
                $result = UIF_Scanner::orphan_check_content();
                wp_send_json_success( array(
                    'phase'      => 2,
                    'checked'    => $result['checked'],
                    'referenced' => $result['referenced'],
                ) );
                break;

            case 3:
                $result = UIF_Scanner::orphan_check_options_and_finalize( 0, 5000 );
                wp_send_json_success( array(
                    'phase'            => 3,
                    'files'            => $result['files'],
                    'total'            => $result['total'],
                    'total_size'       => $result['total_size'],
                    'referenced_count' => $result['referenced_count'],
                ) );
                break;

            default:
                wp_send_json_error( 'Invalid phase.' );
        }
    }

    /**
     * Delete orphaned files from disk (not WordPress attachments — just raw files).
     */
    public static function ajax_orphan_delete() {
        check_ajax_referer( 'uif_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $paths = isset( $_POST['paths'] ) ? (array) $_POST['paths'] : array();

        if ( empty( $paths ) ) {
            wp_send_json_error( 'No files selected.' );
        }

        $upload_dir = wp_get_upload_dir();
        $base_dir   = str_replace( '\\', '/', $upload_dir['basedir'] );
        $deleted    = 0;

        foreach ( $paths as $relative_path ) {
            // Sanitize: no directory traversal.
            $relative_path = str_replace( '\\', '/', $relative_path );
            if ( strpos( $relative_path, '..' ) !== false ) {
                continue;
            }

            $full_path = $base_dir . '/' . $relative_path;

            // Safety: must be inside the uploads directory.
            if ( strpos( realpath( $full_path ), realpath( $base_dir ) ) !== 0 ) {
                continue;
            }

            if ( file_exists( $full_path ) && is_file( $full_path ) && wp_delete_file( $full_path ) ) {
                $deleted++;
            } elseif ( file_exists( $full_path ) && is_file( $full_path ) ) {
                // wp_delete_file doesn't return a value, check if gone.
                wp_delete_file( $full_path );
                if ( ! file_exists( $full_path ) ) {
                    $deleted++;
                }
            }
        }

        wp_send_json_success( array(
            'deleted' => $deleted,
            'total'   => count( $paths ),
        ) );
    }
}
