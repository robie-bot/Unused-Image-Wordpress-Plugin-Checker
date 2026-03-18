<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class UIF_Admin {

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'wp_ajax_uif_scan', array( __CLASS__, 'ajax_scan' ) );
        add_action( 'wp_ajax_uif_delete', array( __CLASS__, 'ajax_delete' ) );
        add_action( 'admin_init', array( __CLASS__, 'handle_csv_export' ) );
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
            'csv_url'    => wp_nonce_url( admin_url( 'admin.php?uif_export_csv=1' ), 'uif_csv_export' ),
            'i18n'     => array(
                'scanning'      => __( 'Scanning...', 'unused-image-finder' ),
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
                <div class="uif-spinner"><span class="spinner is-active"></span></div>
                <span id="uif-progress-text"><?php esc_html_e( 'Scanning...', 'unused-image-finder' ); ?></span>
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

    public static function ajax_scan() {
        check_ajax_referer( 'uif_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $results = UIF_Scanner::scan();
        wp_send_json_success( $results );
    }

    /**
     * Handle CSV export via admin GET request.
     */
    public static function handle_csv_export() {
        if (
            ! isset( $_GET['uif_export_csv'] ) ||
            '1' !== $_GET['uif_export_csv'] ||
            ! isset( $_GET['_wpnonce'] ) ||
            ! wp_verify_nonce( $_GET['_wpnonce'], 'uif_csv_export' )
        ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }

        $results = UIF_Scanner::scan();

        $filename = 'unused-images-' . date( 'Y-m-d-His' ) . '.csv';

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $output = fopen( 'php://output', 'w' );

        // UTF-8 BOM for Excel compatibility.
        fprintf( $output, chr(0xEF) . chr(0xBB) . chr(0xBF) );

        // Header row.
        fputcsv( $output, array(
            'ID',
            'Title',
            'Filename',
            'URL',
            'File Size (bytes)',
            'File Size (readable)',
            'Upload Date',
            'Edit Link',
        ) );

        // Data rows.
        foreach ( $results['unused_images'] as $img ) {
            fputcsv( $output, array(
                $img['id'],
                $img['title'],
                $img['filename'],
                $img['url'],
                $img['filesize'],
                size_format( $img['filesize'], 1 ),
                $img['date'],
                $img['edit_link'],
            ) );
        }

        // Summary row.
        fputcsv( $output, array() );
        fputcsv( $output, array( 'Summary' ) );
        fputcsv( $output, array( 'Total Images in Library', $results['total_images'] ) );
        fputcsv( $output, array( 'Used Images', $results['used_count'] ) );
        fputcsv( $output, array( 'Unused Images', $results['unused_count'] ) );
        fputcsv( $output, array( 'Total Recoverable Space', size_format( $results['total_size'], 1 ) ) );

        fclose( $output );
        exit;
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
            // Only delete if it's actually an image attachment.
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
