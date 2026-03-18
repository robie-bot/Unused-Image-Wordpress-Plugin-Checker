<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class UIF_Admin {

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'wp_ajax_uif_scan_init', array( __CLASS__, 'ajax_scan_init' ) );
        add_action( 'wp_ajax_uif_scan_batch', array( __CLASS__, 'ajax_scan_batch' ) );
        add_action( 'wp_ajax_uif_delete', array( __CLASS__, 'ajax_delete' ) );
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
            'batch_size' => 50,
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

    public static function render_page() {
        ?>
        <div class="wrap uif-wrap">
            <h1><?php esc_html_e( 'Unused Image Finder', 'unused-image-finder' ); ?></h1>

            <div class="uif-intro">
                <p><?php esc_html_e( 'Scan your media library to find images that are not used in any posts, pages, widgets, WooCommerce products, or theme settings.', 'unused-image-finder' ); ?></p>
                <button id="uif-scan-btn" class="button button-primary button-hero">
                    <?php esc_html_e( 'Scan for Unused Images', 'unused-image-finder' ); ?>
                </button>
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
                            <?php esc_html_e( 'Select All', 'unused-image-finder' ); ?>
                        </label>
                        <button id="uif-delete-btn" class="button button-secondary" disabled>
                            <?php esc_html_e( 'Delete Selected', 'unused-image-finder' ); ?>
                        </button>
                        <button id="uif-export-csv-btn" class="button button-secondary">
                            <?php esc_html_e( 'Export CSV', 'unused-image-finder' ); ?>
                        </button>
                        <span id="uif-selected-count"></span>
                    </div>

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
                </div>

                <div id="uif-empty" style="display:none;">
                    <p class="uif-success"><?php esc_html_e( 'No unused images found. Your media library is clean!', 'unused-image-finder' ); ?></p>
                </div>
            </div>

            <div id="uif-notice" class="notice" style="display:none;"></div>
        </div>
        <?php
    }

    /**
     * Step 1: Identify all unused image IDs (heavy lifting).
     * Stores IDs in a transient so batch requests can pull from it.
     */
    public static function ajax_scan_init() {
        check_ajax_referer( 'uif_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        // Increase limits for the detection phase.
        @set_time_limit( 300 );
        wp_raise_memory_limit( 'admin' );

        $all_ids  = UIF_Scanner::get_all_image_ids();
        $used_ids = UIF_Scanner::get_used_image_ids();
        $unused   = array_values( array_diff( $all_ids, $used_ids ) );

        // Store unused IDs in a transient (valid 1 hour).
        set_transient( 'uif_unused_ids_' . get_current_user_id(), $unused, HOUR_IN_SECONDS );

        wp_send_json_success( array(
            'total_images' => count( $all_ids ),
            'used_count'   => count( $used_ids ),
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

        $offset     = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
        $batch_size = isset( $_POST['batch_size'] ) ? absint( $_POST['batch_size'] ) : 50;

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
}
