<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class UIF_Scanner {

    /**
     * Get all image attachment IDs in the media library.
     */
    public static function get_all_image_ids() {
        global $wpdb;

        return $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'attachment'
             AND post_mime_type LIKE 'image/%'
             AND post_status = 'inherit'"
        );
    }

    /**
     * Get all image IDs that are actively used somewhere on the site.
     */
    public static function get_used_image_ids() {
        $used = array();
        $phases = self::get_scan_phases();
        foreach ( $phases as $phase ) {
            foreach ( $phase['methods'] as $method ) {
                if ( $method === 'get_imagify_protected_ids' ) {
                    $used = array_merge( $used, self::$method( $used ) );
                } else {
                    $used = array_merge( $used, self::$method() );
                }
            }
        }
        $used = apply_filters( 'uif_used_image_ids', $used );
        return array_unique( array_filter( array_map( 'absint', $used ) ) );
    }

    /**
     * Scan phases — each phase is a group of methods that can run in one
     * AJAX request without timing out.
     */
    public static function get_scan_phases() {
        return array(
            array(
                'label'   => 'Core content (featured images, galleries)',
                'methods' => array( 'get_featured_image_ids', 'get_content_image_ids', 'get_content_gallery_ids' ),
            ),
            array(
                'label'   => 'WooCommerce, widgets, options, site icons',
                'methods' => array( 'get_woocommerce_image_ids', 'get_option_image_ids', 'get_widget_image_ids', 'get_site_icon_ids' ),
            ),
            array(
                'label'   => 'ACF & Elementor',
                'methods' => array( 'get_acf_image_ids', 'get_elementor_image_ids' ),
            ),
            array(
                'label'   => 'Divi, WPBakery & Impreza',
                'methods' => array( 'get_divi_image_ids', 'get_wpbakery_image_ids', 'get_impreza_image_ids' ),
            ),
            array(
                'label'   => 'CSS backgrounds',
                'methods' => array( 'get_css_background_image_ids' ),
            ),
            array(
                'label'   => 'Filename search (cross-domain)',
                'methods' => array( 'get_filename_referenced_ids' ),
            ),
            array(
                'label'   => 'Imagify / WebP / AVIF protection',
                'methods' => array( 'get_imagify_protected_ids' ),
            ),
        );
    }

    /**
     * Run a single scan phase and return found used IDs.
     *
     * @param int   $phase_index Zero-based phase index.
     * @param array $used_so_far IDs already found in previous phases (needed by Imagify method).
     * @return array Used image IDs found in this phase.
     */
    public static function run_scan_phase( $phase_index, $used_so_far = array() ) {
        $phases = self::get_scan_phases();
        if ( ! isset( $phases[ $phase_index ] ) ) {
            return array();
        }

        $ids = array();
        foreach ( $phases[ $phase_index ]['methods'] as $method ) {
            if ( $method === 'get_imagify_protected_ids' ) {
                $ids = array_merge( $ids, self::$method( $used_so_far ) );
            } else {
                $ids = array_merge( $ids, self::$method() );
            }
        }

        return array_unique( array_filter( array_map( 'absint', $ids ) ) );
    }

    /**
     * Run the scan and return unused image data.
     */
    public static function scan() {
        $all_ids  = self::get_all_image_ids();
        $used_ids = self::get_used_image_ids();
        $unused   = array_diff( $all_ids, $used_ids );

        $results = array();
        foreach ( $unused as $id ) {
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

            $results[] = array(
                'id'        => (int) $id,
                'url'       => $url,
                'title'     => get_the_title( $id ),
                'filename'  => basename( $file_path ),
                'filesize'  => $filesize,
                'date'      => get_the_date( 'Y-m-d', $id ),
                'edit_link' => get_edit_post_link( $id, 'raw' ),
            );
        }

        return array(
            'total_images'  => count( $all_ids ),
            'used_count'    => count( $used_ids ),
            'unused_count'  => count( $unused ),
            'unused_images' => $results,
            'total_size'    => array_sum( wp_list_pluck( $results, 'filesize' ) ),
        );
    }

    // ── Sources ──────────────────────────────────────────────

    private static function get_featured_image_ids() {
        global $wpdb;

        return $wpdb->get_col(
            "SELECT meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_thumbnail_id'
             AND meta_value > 0"
        );
    }

    private static function get_content_image_ids() {
        global $wpdb;
        $ids = array();

        $contents = $wpdb->get_col(
            "SELECT post_content FROM {$wpdb->posts}
             WHERE post_status IN ('publish','draft','pending','private','future')
             AND post_content != ''"
        );

        $upload_dir  = wp_get_upload_dir();
        $base_url    = preg_quote( $upload_dir['baseurl'], '/' );
        // Path-only pattern: always use the base upload path (not the monthly subdir).
        $upload_base_path = wp_parse_url( $upload_dir['baseurl'], PHP_URL_PATH ); // e.g. /wp-content/uploads
        $upload_path_re   = preg_quote( $upload_base_path, '/' );

        foreach ( $contents as $content ) {
            // wp-image-{id} class used by the editor.
            if ( preg_match_all( '/wp-image-(\d+)/i', $content, $m ) ) {
                $ids = array_merge( $ids, $m[1] );
            }

            // Direct URL references matching current site upload URL.
            if ( preg_match_all( '/' . $base_url . '\/[^\s"\'<>)]+/i', $content, $m ) ) {
                foreach ( $m[0] as $url ) {
                    $found = self::url_to_attachment_id( $url );
                    if ( $found ) {
                        $ids[] = $found;
                    }
                }
            }

            // Cross-domain: any https?:// URL pointing to /wp-content/uploads/ path.
            // Catches staging URLs, CDN URLs, old domain references.
            if ( preg_match_all( '/https?:\/\/[^\s"\'<>)]*' . $upload_path_re . '\/[^\s"\'<>)]+/i', $content, $m ) ) {
                foreach ( $m[0] as $url ) {
                    $found = self::url_to_attachment_id_cross_domain( $url );
                    if ( $found ) {
                        $ids[] = $found;
                    }
                }
            }

            // Relative upload paths without domain (e.g. src="/wp-content/uploads/2021/09/file.svg").
            // Catches hover images, lazy-loaded images, JS-injected images, etc.
            if ( preg_match_all( '/(?:^|["\'\s=(])(' . $upload_path_re . '\/[^\s"\'<>)]+)/i', $content, $m ) ) {
                foreach ( $m[1] as $rel_path ) {
                    $found = self::url_to_attachment_id_cross_domain( site_url( $rel_path ) );
                    if ( $found ) {
                        $ids[] = $found;
                    }
                }
            }

            // Gutenberg block attributes with "id":123.
            if ( preg_match_all( '/"id"\s*:\s*(\d+)/', $content, $m ) ) {
                $ids = array_merge( $ids, $m[1] );
            }
        }

        return $ids;
    }

    private static function get_content_gallery_ids() {
        global $wpdb;
        $ids = array();

        $contents = $wpdb->get_col(
            "SELECT post_content FROM {$wpdb->posts}
             WHERE post_status IN ('publish','draft','pending','private','future')
             AND post_content LIKE '%[gallery%'"
        );

        foreach ( $contents as $content ) {
            if ( preg_match_all( '/\[gallery[^\]]*ids=["\']?([\d,]+)["\']?/i', $content, $m ) ) {
                foreach ( $m[1] as $id_string ) {
                    $ids = array_merge( $ids, explode( ',', $id_string ) );
                }
            }
        }

        return $ids;
    }

    private static function get_woocommerce_image_ids() {
        global $wpdb;

        if ( ! class_exists( 'WooCommerce' ) ) {
            return array();
        }

        $ids = array();

        // Product gallery images (_product_image_gallery stores comma-separated IDs).
        $galleries = $wpdb->get_col(
            "SELECT meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_product_image_gallery'
             AND meta_value != ''"
        );

        foreach ( $galleries as $gallery ) {
            $ids = array_merge( $ids, explode( ',', $gallery ) );
        }

        // WooCommerce placeholder image (shown when a product has no image).
        // Stored as an attachment ID in the woocommerce_placeholder_image option.
        $placeholder_id = get_option( 'woocommerce_placeholder_image', 0 );
        if ( $placeholder_id ) {
            $ids[] = absint( $placeholder_id );
        } else {
            // Fallback: find the placeholder by its known filename in the uploads root.
            $upload_dir      = wp_get_upload_dir();
            $placeholder_url = $upload_dir['baseurl'] . '/woocommerce-placeholder.png';
            $found           = attachment_url_to_postid( $placeholder_url );
            if ( $found ) {
                $ids[] = $found;
            }
        }

        // Product featured images (_thumbnail_id is already caught globally,
        // but also catch any variation images stored in product variation meta).
        $variation_images = $wpdb->get_col(
            "SELECT DISTINCT pm.meta_value
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE p.post_type = 'product_variation'
             AND pm.meta_key = '_thumbnail_id'
             AND pm.meta_value > 0"
        );
        $ids = array_merge( $ids, $variation_images );

        // WooCommerce category thumbnail images (stored in term meta).
        $cat_images = $wpdb->get_col(
            "SELECT meta_value FROM {$wpdb->termmeta}
             WHERE meta_key = 'thumbnail_id'
             AND meta_value > 0"
        );
        $ids = array_merge( $ids, $cat_images );

        return $ids;
    }

    private static function get_option_image_ids() {
        $ids = array();

        // Custom logo.
        $custom_logo = get_theme_mod( 'custom_logo' );
        if ( $custom_logo ) {
            $ids[] = $custom_logo;
        }

        // Header image.
        $header_image = get_theme_mod( 'header_image_data' );
        if ( ! empty( $header_image->attachment_id ) ) {
            $ids[] = $header_image->attachment_id;
        }

        // Background image.
        $bg_id = get_theme_mod( 'background_image' );
        if ( $bg_id ) {
            $found = self::url_to_attachment_id( $bg_id );
            if ( $found ) {
                $ids[] = $found;
            }
        }

        return $ids;
    }

    private static function get_widget_image_ids() {
        global $wpdb;
        $ids = array();

        $widget_data = $wpdb->get_col(
            "SELECT option_value FROM {$wpdb->options}
             WHERE option_name LIKE 'widget_%'"
        );

        $upload_dir = wp_get_upload_dir();
        $base_url   = preg_quote( $upload_dir['baseurl'], '/' );

        foreach ( $widget_data as $data ) {
            $unserialized = maybe_unserialize( $data );
            $json         = wp_json_encode( $unserialized );

            if ( preg_match_all( '/' . $base_url . '\/[^\s"\'<>\\\\]+/i', $json, $m ) ) {
                foreach ( $m[0] as $url ) {
                    $found = self::url_to_attachment_id( $url );
                    if ( $found ) {
                        $ids[] = $found;
                    }
                }
            }

            if ( preg_match_all( '/"id"\s*:\s*(\d+)/', $json, $m ) ) {
                $ids = array_merge( $ids, $m[1] );
            }
        }

        return $ids;
    }

    private static function get_acf_image_ids() {
        global $wpdb;

        if ( ! class_exists( 'ACF' ) ) {
            return array();
        }

        $ids = array();

        // ACF stores image fields as attachment IDs in postmeta.
        // We look for numeric meta values that correspond to attachments.
        $acf_meta = $wpdb->get_col(
            "SELECT DISTINCT pm.meta_value
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.meta_value AND p.post_type = 'attachment'
             WHERE pm.meta_value REGEXP '^[0-9]+$'
             AND CAST(pm.meta_value AS UNSIGNED) > 0"
        );

        $ids = array_merge( $ids, $acf_meta );

        // ACF gallery fields store serialized arrays of IDs.
        $serialized = $wpdb->get_col(
            "SELECT meta_value FROM {$wpdb->postmeta}
             WHERE meta_value LIKE 'a:%'
             AND meta_key NOT LIKE '\_%'"
        );

        foreach ( $serialized as $val ) {
            $arr = maybe_unserialize( $val );
            if ( is_array( $arr ) ) {
                foreach ( $arr as $item ) {
                    if ( is_numeric( $item ) ) {
                        $ids[] = $item;
                    }
                }
            }
        }

        return $ids;
    }

    private static function get_elementor_image_ids() {
        global $wpdb;

        $ids = array();

        $elementor_data = $wpdb->get_col(
            "SELECT meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_elementor_data'
             AND meta_value != ''"
        );

        foreach ( $elementor_data as $data ) {
            // Elementor stores image IDs as "id":"123" or "id":123.
            if ( preg_match_all( '/"id"\s*:\s*"?(\d+)"?/', $data, $m ) ) {
                $ids = array_merge( $ids, $m[1] );
            }

            // Also look for upload URLs in Elementor data.
            $upload_dir = wp_get_upload_dir();
            $base_url   = preg_quote( $upload_dir['baseurl'], '/' );

            if ( preg_match_all( '/' . $base_url . '\/[^\s"\'<>\\\\]+/i', $data, $m ) ) {
                foreach ( $m[0] as $url ) {
                    $found = self::url_to_attachment_id( $url );
                    if ( $found ) {
                        $ids[] = $found;
                    }
                }
            }
        }

        return $ids;
    }

    private static function get_divi_image_ids() {
        global $wpdb;
        $ids = array();

        // Divi stores layout data in post_content as shortcodes and in
        // the et_pb_* postmeta keys. It also stores global presets,
        // theme builder templates, and saved layouts.

        // 1. Shortcode-based content: [et_pb_image src="..."], [et_pb_gallery gallery_ids="..."], etc.
        $contents = $wpdb->get_col(
            "SELECT post_content FROM {$wpdb->posts}
             WHERE post_status IN ('publish','draft','pending','private','future')
             AND post_content LIKE '%et_pb_%'"
        );

        $upload_dir = wp_get_upload_dir();
        $base_url   = preg_quote( $upload_dir['baseurl'], '/' );

        foreach ( $contents as $content ) {
            // src="..." attributes in Divi shortcodes.
            if ( preg_match_all( '/\[et_pb_\w+[^\]]*\s(?:src|logo|image|background_image|header_image|image_url|portrait_url|logo_image_url|icon_image)=["\'](' . $base_url . '\/[^\s"\'<>]+)["\']/', $content, $m ) ) {
                foreach ( $m[1] as $url ) {
                    $found = self::url_to_attachment_id( $url );
                    if ( $found ) {
                        $ids[] = $found;
                    }
                }
            }

            // gallery_ids="1,2,3" in [et_pb_gallery].
            if ( preg_match_all( '/\[et_pb_gallery[^\]]*gallery_ids=["\']?([\d,]+)["\']?/', $content, $m ) ) {
                foreach ( $m[1] as $id_string ) {
                    $ids = array_merge( $ids, explode( ',', $id_string ) );
                }
            }

            // Catch any remaining upload URLs inside Divi shortcode content.
            if ( preg_match_all( '/' . $base_url . '\/[^\s"\'<>\]\[]+/i', $content, $m ) ) {
                foreach ( $m[0] as $url ) {
                    $found = self::url_to_attachment_id( $url );
                    if ( $found ) {
                        $ids[] = $found;
                    }
                }
            }
        }

        // 2. Divi Theme Builder templates (stored as et_template post type).
        $template_contents = $wpdb->get_col(
            "SELECT post_content FROM {$wpdb->posts}
             WHERE post_type IN ('et_template','et_header_layout','et_body_layout','et_footer_layout')
             AND post_content != ''"
        );

        foreach ( $template_contents as $content ) {
            if ( preg_match_all( '/' . $base_url . '\/[^\s"\'<>\]\[]+/i', $content, $m ) ) {
                foreach ( $m[0] as $url ) {
                    $found = self::url_to_attachment_id( $url );
                    if ( $found ) {
                        $ids[] = $found;
                    }
                }
            }
        }

        // 3. Divi global presets and theme options (stored in wp_options).
        $divi_options = $wpdb->get_col(
            "SELECT option_value FROM {$wpdb->options}
             WHERE option_name IN ('et_divi','divi','et_developer')
             OR option_name LIKE 'et_pb_%'
             OR option_name LIKE 'divi_%'"
        );

        foreach ( $divi_options as $data ) {
            $json = is_serialized( $data ) ? wp_json_encode( maybe_unserialize( $data ) ) : $data;
            if ( preg_match_all( '/' . $base_url . '\/[^\s"\'<>\\\\]+/i', $json, $m ) ) {
                foreach ( $m[0] as $url ) {
                    $found = self::url_to_attachment_id( $url );
                    if ( $found ) {
                        $ids[] = $found;
                    }
                }
            }
        }

        return $ids;
    }

    private static function get_wpbakery_image_ids() {
        global $wpdb;
        $ids = array();

        // WPBakery (Visual Composer) stores content as shortcodes in post_content.
        $contents = $wpdb->get_col(
            "SELECT post_content FROM {$wpdb->posts}
             WHERE post_status IN ('publish','draft','pending','private','future')
             AND (post_content LIKE '%[vc_%' OR post_content LIKE '%[/vc_%')"
        );

        $upload_dir = wp_get_upload_dir();
        $base_url   = preg_quote( $upload_dir['baseurl'], '/' );

        foreach ( $contents as $content ) {
            // image="123" or images="1,2,3" attributes.
            if ( preg_match_all( '/\[vc_\w+[^\]]*\s(?:image|img_id|images|photo)=["\']?([\d,]+)["\']?/', $content, $m ) ) {
                foreach ( $m[1] as $id_string ) {
                    $ids = array_merge( $ids, explode( ',', $id_string ) );
                }
            }

            // [vc_single_image image="123"].
            if ( preg_match_all( '/\[vc_single_image[^\]]*image=["\']?(\d+)["\']?/', $content, $m ) ) {
                $ids = array_merge( $ids, $m[1] );
            }

            // [vc_gallery images="1,2,3"].
            if ( preg_match_all( '/\[vc_gallery[^\]]*images=["\']?([\d,]+)["\']?/', $content, $m ) ) {
                foreach ( $m[1] as $id_string ) {
                    $ids = array_merge( $ids, explode( ',', $id_string ) );
                }
            }

            // [vc_media_grid include="1,2,3"].
            if ( preg_match_all( '/\[vc_(?:media_grid|masonry_media_grid|basic_grid)[^\]]*include=["\']?([\d,]+)["\']?/', $content, $m ) ) {
                foreach ( $m[1] as $id_string ) {
                    $ids = array_merge( $ids, explode( ',', $id_string ) );
                }
            }

            // Background image URLs in shortcode attributes.
            if ( preg_match_all( '/\[vc_\w+[^\]]*(?:bg_image|background_image|css)[^\]]*' . $base_url . '\/[^\s"\'<>\]\[]+/i', $content, $m ) ) {
                foreach ( $m[0] as $match ) {
                    if ( preg_match_all( '/' . $base_url . '\/[^\s"\'<>\]\[)]+/i', $match, $urls ) ) {
                        foreach ( $urls[0] as $url ) {
                            $found = self::url_to_attachment_id( $url );
                            if ( $found ) {
                                $ids[] = $found;
                            }
                        }
                    }
                }
            }

            // bg_image="123" attribute (numeric ID).
            if ( preg_match_all( '/\[vc_\w+[^\]]*bg_image=["\']?(\d+)["\']?/', $content, $m ) ) {
                $ids = array_merge( $ids, $m[1] );
            }

            // CSS custom field: background-image:url(...) inside vc_custom_* classes.
            if ( preg_match_all( '/background-image\s*:\s*url\(\s*["\']?(' . $base_url . '\/[^\s"\'<>)]+)["\']?\s*\)/', $content, $m ) ) {
                foreach ( $m[1] as $url ) {
                    $found = self::url_to_attachment_id( $url );
                    if ( $found ) {
                        $ids[] = $found;
                    }
                }
            }

            // Catch remaining upload URLs in WPBakery content.
            if ( preg_match_all( '/' . $base_url . '\/[^\s"\'<>\]\[)]+/i', $content, $m ) ) {
                foreach ( $m[0] as $url ) {
                    $found = self::url_to_attachment_id( $url );
                    if ( $found ) {
                        $ids[] = $found;
                    }
                }
            }
        }

        // WPBakery also stores shortcode CSS in postmeta as _wpb_shortcodes_custom_css.
        $custom_css = $wpdb->get_col(
            "SELECT meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_wpb_shortcodes_custom_css'
             AND meta_value != ''"
        );

        foreach ( $custom_css as $css ) {
            if ( preg_match_all( '/background-image\s*:\s*url\(\s*["\']?(' . $base_url . '\/[^\s"\'<>)]+)["\']?\s*\)/', $css, $m ) ) {
                foreach ( $m[1] as $url ) {
                    $found = self::url_to_attachment_id( $url );
                    if ( $found ) {
                        $ids[] = $found;
                    }
                }
            }
        }

        return $ids;
    }

    private static function get_impreza_image_ids() {
        global $wpdb;
        $ids = array();

        $upload_dir = wp_get_upload_dir();
        $base_url   = preg_quote( $upload_dir['baseurl'], '/' );

        $upload_base_path = wp_parse_url( $upload_dir['baseurl'], PHP_URL_PATH );
        $upload_path_re   = preg_quote( $upload_base_path, '/' );

        // Helper: extract image IDs and URLs from any content string.
        $extract = function ( $content ) use ( &$ids, $base_url, $upload_path_re ) {
            // Numeric image/images attributes on [us_*] shortcodes.
            if ( preg_match_all( '/\[us_\w+[^\]]*\s(?:image|images|img|logo|photo|icon|media|thumbnail|ids|include)=["\']?([\d,]+)["\']?/', $content, $m ) ) {
                foreach ( $m[1] as $id_string ) {
                    $ids = array_merge( $ids, explode( ',', $id_string ) );
                }
            }

            // Also catch WPBakery [vc_*] shortcodes inside Impreza layouts.
            if ( preg_match_all( '/\[vc_\w+[^\]]*\s(?:image|images|img_id|photo|include)=["\']?([\d,]+)["\']?/', $content, $m ) ) {
                foreach ( $m[1] as $id_string ) {
                    $ids = array_merge( $ids, explode( ',', $id_string ) );
                }
            }

            // Generic shortcode attribute patterns: gallery_ids="1,2,3", image_id="123".
            if ( preg_match_all( '/(?:gallery_ids|image_id|media_id|attachment_id|bg_image)=["\']?([\d,]+)["\']?/', $content, $m ) ) {
                foreach ( $m[1] as $id_string ) {
                    $ids = array_merge( $ids, explode( ',', $id_string ) );
                }
            }

            // Any upload URLs in the content (current domain).
            if ( preg_match_all( '/' . $base_url . '\/[^\s"\'<>\]\[)]+/i', $content, $m ) ) {
                foreach ( $m[0] as $url ) {
                    $found = self::url_to_attachment_id( $url );
                    if ( $found ) {
                        $ids[] = $found;
                    }
                }
            }

            // Cross-domain upload URLs (staging, CDN, old domain).
            if ( preg_match_all( '/https?:\/\/[^\s"\'<>\]\[)]*' . $upload_path_re . '\/[^\s"\'<>\]\[)]+/i', $content, $m ) ) {
                foreach ( $m[0] as $url ) {
                    $found = self::url_to_attachment_id_cross_domain( $url );
                    if ( $found ) {
                        $ids[] = $found;
                    }
                }
            }

            // Relative upload paths WITHOUT domain (e.g. /wp-content/uploads/2021/09/file.svg
            // or src="/wp-content/uploads/..."). Handles hover images, lazy-loaded images, etc.
            if ( preg_match_all( '/(?:^|["\'\s=(])(' . $upload_path_re . '\/[^\s"\'<>\]\[)]+)/i', $content, $m ) ) {
                foreach ( $m[1] as $rel_path ) {
                    $found = self::url_to_attachment_id_cross_domain( site_url( $rel_path ) );
                    if ( $found ) {
                        $ids[] = $found;
                    }
                }
            }

            // JSON-style: any key containing "image", "img", "id", "logo", "photo",
            // "thumbnail", "media", "icon", "avatar", "background" with a numeric value.
            // Catches: "id":123, "image":"456", "us_image_id":"789", etc.
            if ( preg_match_all( '/"[^"]*(?:id|image|img|logo|photo|thumbnail|media|icon|avatar|background)[^"]*"\s*:\s*"?(\d+)"?/i', $content, $m ) ) {
                $ids = array_merge( $ids, $m[1] );
            }

            // Catch bare numeric values in common Impreza grid layout patterns.
            // e.g., "source":"custom","custom":"1234" or "value":"5678"
            if ( preg_match_all( '/"(?:custom|value|source_\d+_value|selected)"\s*:\s*"(\d+)"/i', $content, $m ) ) {
                $ids = array_merge( $ids, $m[1] );
            }
        };

        // 1. ALL Impreza post types — post_content (any status, including drafts and auto-drafts).
        $impreza_post_types = array(
            'us_page_block',
            'us_content_template',
            'us_header',
            'us_footer',
            'us_grid_layout',
        );
        $pt_placeholders = implode( ',', array_fill( 0, count( $impreza_post_types ), '%s' ) );

        $impreza_contents = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT post_content FROM {$wpdb->posts}
                 WHERE post_type IN ($pt_placeholders)
                 AND post_content != ''",
                $impreza_post_types
            )
        );

        foreach ( $impreza_contents as $content ) {
            $extract( $content );
        }

        // 2. Regular posts/pages containing [us_*] shortcodes.
        $us_contents = $wpdb->get_col(
            "SELECT post_content FROM {$wpdb->posts}
             WHERE post_status IN ('publish','draft','pending','private','future')
             AND post_content LIKE '%[us_%'"
        );

        foreach ( $us_contents as $content ) {
            $extract( $content );
        }

        // 3. ALL postmeta for Impreza post types (grid layouts store settings in meta).
        $impreza_meta = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT pm.meta_value FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE p.post_type IN ($pt_placeholders)
                 AND pm.meta_value != ''
                 AND pm.meta_value != '0'",
                $impreza_post_types
            )
        );

        foreach ( $impreza_meta as $meta_val ) {
            // Numeric attachment ID.
            if ( is_numeric( $meta_val ) && (int) $meta_val > 0 ) {
                $ids[] = $meta_val;
                continue;
            }

            // Serialized or JSON data.
            $unserialized = maybe_unserialize( $meta_val );
            if ( is_array( $unserialized ) || is_object( $unserialized ) ) {
                $json = wp_json_encode( $unserialized );
                $extract( $json );

                // Also walk arrays for numeric IDs.
                if ( is_array( $unserialized ) ) {
                    array_walk_recursive( $unserialized, function ( $val ) use ( &$ids ) {
                        if ( is_numeric( $val ) && (int) $val > 0 ) {
                            $ids[] = $val;
                        }
                    } );
                }
            } elseif ( is_string( $meta_val ) && strlen( $meta_val ) > 10 ) {
                // Longer strings might contain URLs or shortcodes.
                $extract( $meta_val );
            }
        }

        // 4. Postmeta with us_ prefix on ANY post type (titlebar images, page settings, etc.).
        $us_meta = $wpdb->get_col(
            "SELECT DISTINCT pm.meta_value
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.meta_value AND p.post_type = 'attachment'
             WHERE pm.meta_key LIKE 'us_%'
             AND pm.meta_value REGEXP '^[0-9]+$'
             AND CAST(pm.meta_value AS UNSIGNED) > 0"
        );

        $ids = array_merge( $ids, $us_meta );

        // 5. Postmeta with us_ prefix that contain URLs (not just numeric IDs).
        $us_url_meta = $wpdb->get_col(
            "SELECT pm.meta_value FROM {$wpdb->postmeta} pm
             WHERE pm.meta_key LIKE 'us_%'
             AND pm.meta_value LIKE '%" . $wpdb->esc_like( $upload_dir['baseurl'] ) . "%'"
        );

        foreach ( $us_url_meta as $val ) {
            $extract( $val );
        }

        // 6. Impreza theme options (stored in wp_options as 'usof_options').
        $impreza_options = $wpdb->get_col(
            "SELECT option_value FROM {$wpdb->options}
             WHERE option_name IN ('usof_options','us_theme_options')
             OR option_name LIKE 'usof_%'
             OR option_name LIKE 'us_%'"
        );

        foreach ( $impreza_options as $data ) {
            $unserialized = maybe_unserialize( $data );
            $json         = wp_json_encode( $unserialized );

            // Any numeric value that's an attachment.
            if ( preg_match_all( '/"[^"]*"\s*:\s*"?(\d+)"?/', $json, $m ) ) {
                $ids = array_merge( $ids, $m[1] );
            }

            // Upload URLs in theme options.
            $extract( $json );
        }

        // 7. Term meta — Impreza allows images on categories/tags.
        $term_meta = $wpdb->get_col(
            "SELECT meta_value FROM {$wpdb->termmeta}
             WHERE meta_value REGEXP '^[0-9]+$'
             AND CAST(meta_value AS UNSIGNED) > 0"
        );

        // Only keep values that are actual attachments.
        if ( ! empty( $term_meta ) ) {
            $term_placeholders = implode( ',', array_fill( 0, count( $term_meta ), '%d' ) );
            $valid_term_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts}
                     WHERE ID IN ($term_placeholders)
                     AND post_type = 'attachment'",
                    $term_meta
                )
            );
            $ids = array_merge( $ids, $valid_term_ids );
        }

        // Term meta with URLs (search by path, not domain, to catch staging/CDN URLs).
        $upload_base_path = wp_parse_url( $upload_dir['baseurl'], PHP_URL_PATH );
        $term_url_meta = $wpdb->get_col(
            "SELECT meta_value FROM {$wpdb->termmeta}
             WHERE meta_value LIKE '%" . $wpdb->esc_like( $upload_base_path ) . "%'"
        );

        foreach ( $term_url_meta as $val ) {
            $extract( $val );
        }

        return $ids;
    }

    /**
     * Search post_content for filenames by loading posts in small chunks
     * and using PHP stripos(). Zero LIKE queries — uses only indexed
     * SELECT ... WHERE ID IN (...) lookups.
     *
     * @param array $filenames List of filenames to search for.
     * @return array Filenames that were found in any post_content.
     */
    private static function search_post_content_for_filenames( $filenames ) {
        global $wpdb;
        $found = array();
        $remaining = array_flip( $filenames ); // filename => anything (fast isset check)

        // Get all post IDs with non-empty content (fast indexed query).
        $post_ids = $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type != 'attachment'
             AND post_status IN ('publish','draft','pending','private','future','inherit')
             AND post_content != ''"
        );

        if ( empty( $post_ids ) ) {
            return $found;
        }

        // Process posts in chunks of 200 — each chunk loads ~1-2 MB of content.
        $chunks = array_chunk( $post_ids, 200 );

        foreach ( $chunks as $chunk ) {
            if ( empty( $remaining ) ) {
                break; // All filenames found.
            }

            $in  = implode( ',', array_map( 'intval', $chunk ) );
            $rows = $wpdb->get_col(
                "SELECT post_content FROM {$wpdb->posts} WHERE ID IN ({$in})"
            );

            if ( ! $rows ) {
                continue;
            }

            // Concatenate this chunk into one string for fast searching.
            $blob = implode( "\n", $rows );
            unset( $rows );

            // Check each remaining filename.
            foreach ( $remaining as $filename => $v ) {
                if ( stripos( $blob, $filename ) !== false ) {
                    $found[] = $filename;
                    unset( $remaining[ $filename ] );
                }
            }

            unset( $blob );
        }

        return $found;
    }

    private static function get_filename_referenced_ids() {
        global $wpdb;
        $ids = array();

        // Build filename => ID lookup from _wp_attached_file meta.
        $attachments = $wpdb->get_results(
            "SELECT p.ID, pm.meta_value AS filepath
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wp_attached_file'
             WHERE p.post_type = 'attachment'
             AND p.post_mime_type LIKE 'image/%'
             AND p.post_status = 'inherit'"
        );

        if ( empty( $attachments ) ) {
            return $ids;
        }

        $by_filename = array();
        foreach ( $attachments as $att ) {
            $basename = basename( $att->filepath );
            $by_filename[ $basename ][] = (int) $att->ID;
        }

        // Search post_content using chunk-and-strpos approach (no LIKE queries).
        $found = self::search_post_content_for_filenames( array_keys( $by_filename ) );

        foreach ( $found as $filename ) {
            if ( isset( $by_filename[ $filename ] ) ) {
                $ids = array_merge( $ids, $by_filename[ $filename ] );
            }
        }

        return $ids;
    }

    /**
     * Imagify awareness: protect original images when their WebP/AVIF versions are in use,
     * and protect Imagify backup originals from being flagged as unused.
     *
     * @param array $used Already-identified used image IDs.
     * @return array Additional IDs that should be marked as used.
     */
    private static function get_imagify_protected_ids( $used ) {
        global $wpdb;
        $ids = array();

        // 1. Imagify / optimizer meta: any image that has been optimized should
        //    be protected — the attachment IS the original backup.
        //    These are fast indexed lookups on meta_key.
        $imagify_ids = $wpdb->get_col(
            "SELECT DISTINCT post_id FROM {$wpdb->postmeta}
             WHERE meta_key IN ('_imagify_data', '_imagify_status')
             AND meta_value != ''
             AND meta_value != 'a:0:{}'"
        );

        if ( $imagify_ids ) {
            $ids = array_merge( $ids, $imagify_ids );
        }

        // 2. WP core backup sizes (used by Imagify, EWWW, ShortPixel, etc.)
        $backup_ids = $wpdb->get_col(
            "SELECT DISTINCT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_wp_attachment_backup_sizes'
             AND meta_value != ''"
        );

        if ( $backup_ids ) {
            $ids = array_merge( $ids, $backup_ids );
        }

        // 3. Reverse lookup: if a .webp/.avif version of an image filename is
        //    found in post_content, mark the original as used.
        $used_set = array_flip( array_unique( array_filter( array_map( 'absint', $used ) ) ) );

        $all_meta = $wpdb->get_results(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_wp_attached_file'"
        );

        $variant_map = array(); // variant_filename => post_id
        foreach ( $all_meta as $row ) {
            $post_id = (int) $row->post_id;
            if ( isset( $used_set[ $post_id ] ) ) {
                continue;
            }

            $basename    = wp_basename( $row->meta_value );
            $name_no_ext = pathinfo( $basename, PATHINFO_FILENAME );

            $variant_map[ $basename . '.webp' ]  = $post_id;
            $variant_map[ $basename . '.avif' ]  = $post_id;
            $variant_map[ $name_no_ext . '.webp' ] = $post_id;
            $variant_map[ $name_no_ext . '.avif' ] = $post_id;
        }

        if ( empty( $variant_map ) ) {
            return $ids;
        }

        // Search using chunk-and-strpos (no LIKE queries).
        $found = self::search_post_content_for_filenames( array_keys( $variant_map ) );

        foreach ( $found as $variant ) {
            if ( isset( $variant_map[ $variant ] ) ) {
                $ids[] = $variant_map[ $variant ];
            }
        }

        return $ids;
    }

    private static function get_site_icon_ids() {
        $ids     = array();
        $site_icon = get_option( 'site_icon' );
        if ( $site_icon ) {
            $ids[] = $site_icon;
        }
        return $ids;
    }

    /**
     * Scan ALL content for CSS background:url() and background-image:url().
     * Covers inline styles, custom CSS, Customizer CSS, and theme options.
     */
    private static function get_css_background_image_ids() {
        global $wpdb;
        $ids = array();

        $upload_dir      = wp_get_upload_dir();
        $base_url        = preg_quote( $upload_dir['baseurl'], '/' );
        $upload_base_path = wp_parse_url( $upload_dir['baseurl'], PHP_URL_PATH ); // e.g. /wp-content/uploads
        $upload_path_re  = preg_quote( $upload_base_path, '/' );

        // Matches current-domain upload URLs in background/background-image.
        $bg_regex = '/background(?:-image)?\s*:[^;}]*url\(\s*["\']?(' . $base_url . '\/[^\s"\'<>)]+)["\']?\s*\)/i';

        // Matches ANY domain's /wp-content/uploads/ path (staging, CDN, old domain).
        $bg_regex_cross = '/background(?:-image)?\s*:[^;}]*url\(\s*["\']?(https?:\/\/[^\s"\'<>)]*' . $upload_path_re . '\/[^\s"\'<>)]+)["\']?\s*\)/i';

        // Matches relative upload paths in background url() — no domain, just /wp-content/uploads/...
        $bg_regex_relative = '/background(?:-image)?\s*:[^;}]*url\(\s*["\']?(' . $upload_path_re . '\/[^\s"\'<>)]+)["\']?\s*\)/i';

        // Helper: extract from current-domain, cross-domain, and relative-path patterns.
        $extract_bg = function ( $text ) use ( &$ids, $bg_regex, $bg_regex_cross, $bg_regex_relative ) {
            if ( preg_match_all( $bg_regex, $text, $m ) ) {
                foreach ( $m[1] as $url ) {
                    $found = self::url_to_attachment_id( $url );
                    if ( $found ) {
                        $ids[] = $found;
                    }
                }
            }
            if ( preg_match_all( $bg_regex_cross, $text, $m ) ) {
                foreach ( $m[1] as $url ) {
                    $found = self::url_to_attachment_id_cross_domain( $url );
                    if ( $found ) {
                        $ids[] = $found;
                    }
                }
            }
            if ( preg_match_all( $bg_regex_relative, $text, $m ) ) {
                foreach ( $m[1] as $rel_path ) {
                    $found = self::url_to_attachment_id_cross_domain( site_url( $rel_path ) );
                    if ( $found ) {
                        $ids[] = $found;
                    }
                }
            }
        };

        // 1. All post content (catches inline styles in any builder/editor).
        //    Use broad LIKE — just look for background + url(, no domain filter.
        $contents = $wpdb->get_col(
            "SELECT post_content FROM {$wpdb->posts}
             WHERE post_status IN ('publish','draft','pending','private','future')
             AND post_content LIKE '%background%'
             AND post_content LIKE '%url(%'"
        );

        foreach ( $contents as $content ) {
            $extract_bg( $content );
        }

        // 2. WordPress Customizer Additional CSS (stored as custom_css post type).
        $custom_css_posts = $wpdb->get_col(
            "SELECT post_content FROM {$wpdb->posts}
             WHERE post_type = 'custom_css'
             AND post_content != ''"
        );

        foreach ( $custom_css_posts as $css ) {
            $extract_bg( $css );
        }

        // 3. All postmeta that contains background url() (covers builder custom CSS fields).
        //    Search for /wp-content/uploads path, not a specific domain.
        $meta_css = $wpdb->get_col(
            "SELECT meta_value FROM {$wpdb->postmeta}
             WHERE meta_value LIKE '%background%'
             AND meta_value LIKE '%url(%'
             AND meta_value LIKE '%" . $wpdb->esc_like( $upload_base_path ) . "%'"
        );

        foreach ( $meta_css as $css ) {
            $extract_bg( $css );
        }

        // 4. wp_options that contain background url() (theme custom CSS options).
        $option_css = $wpdb->get_col(
            "SELECT option_value FROM {$wpdb->options}
             WHERE option_value LIKE '%background%'
             AND option_value LIKE '%url(%'
             AND option_value LIKE '%" . $wpdb->esc_like( $upload_base_path ) . "%'"
        );

        foreach ( $option_css as $css ) {
            $extract_bg( $css );
        }

        return $ids;
    }

    // ── Helpers ──────────────────────────────────────────────

    /**
     * Convert an image URL to its attachment ID. Handles thumbnail suffixes.
     */
    private static function url_to_attachment_id( $url ) {
        // Strip thumbnail suffix (-300x200 etc.) before lookup.
        $clean = preg_replace( '/-\d+x\d+(?=\.\w+$)/', '', $url );
        $id    = attachment_url_to_postid( $clean );

        if ( ! $id ) {
            $id = attachment_url_to_postid( $url );
        }

        return $id ?: 0;
    }

    /**
     * Convert a cross-domain URL (staging, CDN, old domain) to an attachment ID.
     * Strips the foreign domain and replaces with the current site's upload URL,
     * then looks up the attachment. This handles images created on staging that
     * still reference the staging domain in post_content after migration.
     */
    private static function url_to_attachment_id_cross_domain( $url ) {
        $upload_dir = wp_get_upload_dir();
        $base_url   = $upload_dir['baseurl'];

        // Extract the /wp-content/uploads/... path from the foreign URL.
        $upload_base_path = wp_parse_url( $base_url, PHP_URL_PATH ); // e.g. /wp-content/uploads
        $parsed           = wp_parse_url( $url );

        if ( empty( $parsed['path'] ) ) {
            return 0;
        }

        $path = $parsed['path'];

        // Find where /wp-content/uploads starts in the foreign URL path.
        $pos = strpos( $path, $upload_base_path );
        if ( false === $pos ) {
            // Try generic /wp-content/uploads fallback.
            $pos = strpos( $path, '/wp-content/uploads' );
            if ( false === $pos ) {
                return 0;
            }
            $relative = substr( $path, $pos + strlen( '/wp-content/uploads' ) );
            $local_url = $base_url . $relative;
        } else {
            $relative  = substr( $path, $pos + strlen( $upload_base_path ) );
            $local_url = $base_url . $relative;
        }

        // Strip thumbnail suffix before lookup.
        $clean = preg_replace( '/-\d+x\d+(?=\.\w+$)/', '', $local_url );
        $id    = attachment_url_to_postid( $clean );

        if ( ! $id ) {
            $id = attachment_url_to_postid( $local_url );
        }

        return $id ?: 0;
    }

    /**
     * Scan the /wp-content/uploads/ folder on disk and find orphaned image files
     * that exist on disk but are NOT registered in the WordPress database.
     *
     * These are files uploaded via FTP, leftover thumbnails, optimizer backups, etc.
     *
     * @param int $offset  Start index for pagination.
     * @param int $limit   How many to return.
     * @return array {
     *     @type array  $files       Array of orphaned file info.
     *     @type int    $total       Total orphaned files found.
     *     @type int    $total_size  Total size of orphaned files.
     * }
     */
    public static function get_orphaned_files( $offset = 0, $limit = 50 ) {
        $upload_dir = wp_get_upload_dir();
        $base_dir   = $upload_dir['basedir'];
        $base_url   = $upload_dir['baseurl'];

        if ( ! is_dir( $base_dir ) ) {
            return array( 'files' => array(), 'total' => 0, 'total_size' => 0 );
        }

        // Image extensions to look for.
        $image_exts = array( 'jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'avif', 'bmp', 'ico', 'tiff', 'tif' );

        // Build a set of ALL known files from the database (main files + thumbnails).
        $known_files = self::get_all_known_files();

        // Recursively scan the uploads directory.
        $orphaned = array();
        $total_size = 0;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $base_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ( $iterator as $file ) {
            if ( ! $file->isFile() ) {
                continue;
            }

            $ext = strtolower( pathinfo( $file->getFilename(), PATHINFO_EXTENSION ) );
            if ( ! in_array( $ext, $image_exts, true ) ) {
                continue;
            }

            $full_path = $file->getPathname();
            // Normalize path separators for Windows.
            $full_path = str_replace( '\\', '/', $full_path );
            $base_norm = str_replace( '\\', '/', $base_dir );

            // Get the relative path from uploads base.
            $relative = ltrim( str_replace( $base_norm, '', $full_path ), '/' );

            // Skip if this file is known to WordPress.
            if ( isset( $known_files[ $relative ] ) ) {
                continue;
            }

            // Skip common non-media directories.
            $skip_dirs = array( 'backup', 'backups', 'imagify-backup', 'cache', 'wpcf7_uploads', 'wc-logs', 'elementor/css', 'elementor/thumbs' );
            $skip = false;
            foreach ( $skip_dirs as $dir ) {
                if ( strpos( $relative, $dir ) === 0 ) {
                    $skip = true;
                    break;
                }
            }
            if ( $skip ) {
                continue;
            }

            $size = $file->getSize();
            $total_size += $size;

            $orphaned[] = array(
                'path'      => $relative,
                'url'       => $base_url . '/' . $relative,
                'filename'  => $file->getFilename(),
                'filesize'  => $size,
                'modified'  => gmdate( 'Y-m-d', $file->getMTime() ),
                'full_path' => $full_path,
            );
        }

        // Sort by modified date descending.
        usort( $orphaned, function ( $a, $b ) {
            return strcmp( $b['modified'], $a['modified'] );
        } );

        $total = count( $orphaned );

        return array(
            'files'      => array_slice( $orphaned, $offset, $limit ),
            'total'      => $total,
            'total_size' => $total_size,
        );
    }

    /**
     * Build a set of all file paths known to WordPress (main files + all thumbnail sizes).
     *
     * @return array Associative array of relative_path => true.
     */
    private static function get_all_known_files() {
        global $wpdb;
        $known = array();

        // Get all attached files (relative paths like "2024/08/photo.jpg").
        $rows = $wpdb->get_results(
            "SELECT pm.meta_value AS filepath, pm2.meta_value AS metadata
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'attachment'
             LEFT JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = pm.post_id AND pm2.meta_key = '_wp_attachment_metadata'
             WHERE pm.meta_key = '_wp_attached_file'"
        );

        foreach ( $rows as $row ) {
            // Main file.
            $known[ $row->filepath ] = true;

            // Thumbnail sizes from metadata.
            if ( ! empty( $row->metadata ) ) {
                $meta = maybe_unserialize( $row->metadata );
                if ( is_array( $meta ) && ! empty( $meta['sizes'] ) ) {
                    $dir = dirname( $row->filepath );
                    if ( '.' === $dir ) {
                        $dir = '';
                    } else {
                        $dir .= '/';
                    }
                    foreach ( $meta['sizes'] as $size_info ) {
                        if ( ! empty( $size_info['file'] ) ) {
                            $known[ $dir . $size_info['file'] ] = true;
                        }
                    }
                }
            }
        }

        return $known;
    }
}
