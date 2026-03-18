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

        $used = array_merge( $used, self::get_featured_image_ids() );
        $used = array_merge( $used, self::get_content_image_ids() );
        $used = array_merge( $used, self::get_content_gallery_ids() );
        $used = array_merge( $used, self::get_woocommerce_image_ids() );
        $used = array_merge( $used, self::get_option_image_ids() );
        $used = array_merge( $used, self::get_widget_image_ids() );
        $used = array_merge( $used, self::get_acf_image_ids() );
        $used = array_merge( $used, self::get_elementor_image_ids() );
        $used = array_merge( $used, self::get_divi_image_ids() );
        $used = array_merge( $used, self::get_wpbakery_image_ids() );
        $used = array_merge( $used, self::get_impreza_image_ids() );
        $used = array_merge( $used, self::get_site_icon_ids() );
        $used = array_merge( $used, self::get_css_background_image_ids() );

        $used = apply_filters( 'uif_used_image_ids', $used );

        return array_unique( array_filter( array_map( 'absint', $used ) ) );
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

        $upload_dir = wp_get_upload_dir();
        $base_url   = preg_quote( $upload_dir['baseurl'], '/' );

        foreach ( $contents as $content ) {
            // wp-image-{id} class used by the editor.
            if ( preg_match_all( '/wp-image-(\d+)/i', $content, $m ) ) {
                $ids = array_merge( $ids, $m[1] );
            }

            // Direct URL references to uploads.
            if ( preg_match_all( '/' . $base_url . '\/[^\s"\'<>]+/i', $content, $m ) ) {
                foreach ( $m[0] as $url ) {
                    $found = self::url_to_attachment_id( $url );
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

        // Product gallery images.
        $galleries = $wpdb->get_col(
            "SELECT meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_product_image_gallery'
             AND meta_value != ''"
        );

        foreach ( $galleries as $gallery ) {
            $ids = array_merge( $ids, explode( ',', $gallery ) );
        }

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

        // 1. Impreza uses [us_*] shortcodes in post_content.
        $contents = $wpdb->get_col(
            "SELECT post_content FROM {$wpdb->posts}
             WHERE post_status IN ('publish','draft','pending','private','future')
             AND post_content LIKE '%[us_%'"
        );

        foreach ( $contents as $content ) {
            // image="123" or images="1,2,3" attributes on [us_*] shortcodes.
            if ( preg_match_all( '/\[us_\w+[^\]]*\s(?:image|images|img|logo|photo|icon|media)=["\']?([\d,]+)["\']?/', $content, $m ) ) {
                foreach ( $m[1] as $id_string ) {
                    $ids = array_merge( $ids, explode( ',', $id_string ) );
                }
            }

            // [us_image image="123"], [us_image_slider ids="1,2,3"].
            if ( preg_match_all( '/\[us_image[^\]]*image=["\']?(\d+)["\']?/', $content, $m ) ) {
                $ids = array_merge( $ids, $m[1] );
            }
            if ( preg_match_all( '/\[us_image_slider[^\]]*ids=["\']?([\d,]+)["\']?/', $content, $m ) ) {
                foreach ( $m[1] as $id_string ) {
                    $ids = array_merge( $ids, explode( ',', $id_string ) );
                }
            }

            // [us_gallery ids="1,2,3"].
            if ( preg_match_all( '/\[us_gallery[^\]]*ids=["\']?([\d,]+)["\']?/', $content, $m ) ) {
                foreach ( $m[1] as $id_string ) {
                    $ids = array_merge( $ids, explode( ',', $id_string ) );
                }
            }

            // Background image URLs in us_ shortcode attributes.
            if ( preg_match_all( '/' . $base_url . '\/[^\s"\'<>\]\[)]+/i', $content, $m ) ) {
                foreach ( $m[0] as $url ) {
                    $found = self::url_to_attachment_id( $url );
                    if ( $found ) {
                        $ids[] = $found;
                    }
                }
            }
        }

        // 2. Impreza reusable blocks / page templates (us_page_block, us_content_template).
        $impreza_blocks = $wpdb->get_col(
            "SELECT post_content FROM {$wpdb->posts}
             WHERE post_type IN ('us_page_block','us_content_template','us_header','us_footer','us_grid_layout')
             AND post_content != ''"
        );

        foreach ( $impreza_blocks as $content ) {
            if ( preg_match_all( '/\[us_\w+[^\]]*\s(?:image|images|img|logo|photo|icon|media)=["\']?([\d,]+)["\']?/', $content, $m ) ) {
                foreach ( $m[1] as $id_string ) {
                    $ids = array_merge( $ids, explode( ',', $id_string ) );
                }
            }
            if ( preg_match_all( '/' . $base_url . '\/[^\s"\'<>\]\[)]+/i', $content, $m ) ) {
                foreach ( $m[0] as $url ) {
                    $found = self::url_to_attachment_id( $url );
                    if ( $found ) {
                        $ids[] = $found;
                    }
                }
            }
        }

        // 3. Impreza theme options (stored in wp_options as 'usof_options').
        $impreza_options = $wpdb->get_col(
            "SELECT option_value FROM {$wpdb->options}
             WHERE option_name IN ('usof_options','us_theme_options')
             OR option_name LIKE 'usof_%'"
        );

        foreach ( $impreza_options as $data ) {
            $unserialized = maybe_unserialize( $data );
            $json         = wp_json_encode( $unserialized );

            // Numeric IDs stored as values.
            if ( preg_match_all( '/"(?:logo|favicon|og_image|custom_icon|header_image)[^"]*"\s*:\s*"?(\d+)"?/', $json, $m ) ) {
                $ids = array_merge( $ids, $m[1] );
            }

            // Upload URLs in theme options.
            if ( preg_match_all( '/' . $base_url . '\/[^\s"\'<>\\\\]+/i', $json, $m ) ) {
                foreach ( $m[0] as $url ) {
                    $found = self::url_to_attachment_id( $url );
                    if ( $found ) {
                        $ids[] = $found;
                    }
                }
            }
        }

        // 4. Impreza meta boxes store image IDs in postmeta with us_ prefix.
        $us_meta = $wpdb->get_col(
            "SELECT DISTINCT pm.meta_value
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.meta_value AND p.post_type = 'attachment'
             WHERE pm.meta_key LIKE 'us_%'
             AND pm.meta_value REGEXP '^[0-9]+$'
             AND CAST(pm.meta_value AS UNSIGNED) > 0"
        );

        $ids = array_merge( $ids, $us_meta );

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

        $upload_dir = wp_get_upload_dir();
        $base_url   = preg_quote( $upload_dir['baseurl'], '/' );

        // Regex matches both background:url() and background-image:url()
        // including shorthand like background: #fff url(...) no-repeat;
        $bg_regex = '/background(?:-image)?\s*:[^;}]*url\(\s*["\']?(' . $base_url . '\/[^\s"\'<>)]+)["\']?\s*\)/i';

        // 1. All post content (catches inline styles in any builder/editor).
        $contents = $wpdb->get_col(
            "SELECT post_content FROM {$wpdb->posts}
             WHERE post_status IN ('publish','draft','pending','private','future')
             AND post_content LIKE '%background%'
             AND post_content LIKE '%url(%'"
        );

        foreach ( $contents as $content ) {
            if ( preg_match_all( $bg_regex, $content, $m ) ) {
                foreach ( $m[1] as $url ) {
                    $found = self::url_to_attachment_id( $url );
                    if ( $found ) {
                        $ids[] = $found;
                    }
                }
            }
        }

        // 2. WordPress Customizer Additional CSS (stored as custom_css post type).
        $custom_css_posts = $wpdb->get_col(
            "SELECT post_content FROM {$wpdb->posts}
             WHERE post_type = 'custom_css'
             AND post_content != ''"
        );

        foreach ( $custom_css_posts as $css ) {
            if ( preg_match_all( $bg_regex, $css, $m ) ) {
                foreach ( $m[1] as $url ) {
                    $found = self::url_to_attachment_id( $url );
                    if ( $found ) {
                        $ids[] = $found;
                    }
                }
            }
        }

        // 3. All postmeta that contains background url() (covers builder custom CSS fields).
        $meta_css = $wpdb->get_col(
            "SELECT meta_value FROM {$wpdb->postmeta}
             WHERE meta_value LIKE '%background%'
             AND meta_value LIKE '%url(%'
             AND meta_value LIKE '%" . $wpdb->esc_like( $upload_dir['baseurl'] ) . "%'"
        );

        foreach ( $meta_css as $css ) {
            if ( preg_match_all( $bg_regex, $css, $m ) ) {
                foreach ( $m[1] as $url ) {
                    $found = self::url_to_attachment_id( $url );
                    if ( $found ) {
                        $ids[] = $found;
                    }
                }
            }
        }

        // 4. wp_options that contain background url() (theme custom CSS options).
        $option_css = $wpdb->get_col(
            "SELECT option_value FROM {$wpdb->options}
             WHERE option_value LIKE '%background%'
             AND option_value LIKE '%url(%'
             AND option_value LIKE '%" . $wpdb->esc_like( $upload_dir['baseurl'] ) . "%'"
        );

        foreach ( $option_css as $css ) {
            if ( preg_match_all( $bg_regex, $css, $m ) ) {
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
}
