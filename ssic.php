<?php
/*
Plugin Name: Stupid Simple Image Converter
Description: Automatically convert uploaded PNG and JPG images to WebP format.
Version: 1.2
Author: Dynamic Technologies
Author URI: https://bedynamic.tech
Plugin URI: https://github.com/bedynamictech/Stupid-Simple-Image-Converter
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
*/

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Add Settings link on Plugins page
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'ssic_action_links' );
function ssic_action_links( $links ) {
    $settings_link = '<a href="' . admin_url( 'admin.php?page=ssic-settings' ) . '">Settings</a>';
    array_unshift( $links, $settings_link );
    return $links;
}

// Register setting for quality
add_action( 'admin_init', 'ssic_register_settings' );
function ssic_register_settings() {
    register_setting( 'ssic_settings_group', 'ssic_quality', array(
        'type'              => 'integer',
        'description'       => 'Quality for WebP conversion (50,85,100)',
        'default'           => 85,
        'sanitize_callback' => 'absint',
    ) );
}

// Add main menu and submenu
add_action( 'admin_menu', 'ssic_add_menu' );

function ssic_add_menu() {
    global $menu;
    $parent_exists = false;
    foreach ($menu as $item) {
        if (!empty($item[2]) && $item[2] === 'stupidsimple') {
            $parent_exists = true;
            break;
        }
    }

    if (!$parent_exists) {
        add_menu_page(
            'Stupid Simple',
            'Stupid Simple',
            'manage_options',
            'stupidsimple',
            'ssic_settings_page_content',
            'dashicons-hammer',
            99
        );
    }

    add_submenu_page(
        'stupidsimple',
        'Image Converter',
        'Image Converter',
        'manage_options',
        'ssic-settings',
        'ssic_settings_page_content'
    );
}

// Handle mass-convert request
add_action( 'admin_post_ssic_mass_convert', 'ssic_handle_mass_convert' );
function ssic_handle_mass_convert() {
    if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'ssic_mass_convert' ) ) {
        wp_die( __( 'Unauthorized request', 'ssic' ) );
    }
    $processed = 0;
    $args = array(
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'numberposts'    => -1,
        'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/gif' ),
    );
    $attachments = get_posts( $args );
    foreach ( $attachments as $att ) {
        $id = $att->ID;
        if ( get_post_meta( $id, 'ssic_converted', true ) ) {
            continue;
        }
        ssic_convert_to_webp( $id );
        update_post_meta( $id, 'ssic_converted', time() );
        $processed++;
    }
    wp_safe_redirect( add_query_arg( 'ssic_mass_converted', $processed, wp_get_referer() ) );
    exit;
}

// Show notice after mass conversion
add_action( 'admin_notices', function() {
    if ( isset( $_GET['ssic_mass_converted'] ) ) {
        $n = intval( $_GET['ssic_mass_converted'] );
        printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
            sprintf( esc_html__( 'Converted %d images.', 'ssic' ), $n )
        );
    }
});

// Convert single image, skip if webp or already converted
add_filter( 'wp_generate_attachment_metadata', 'ssic_convert_to_webp', 10, 2 );
add_action( 'add_attachment', 'ssic_convert_to_webp', 10, 1 );
function ssic_convert_to_webp( $metadata_or_id, $attachment_id = null ) {
    $attachment_id = is_array( $metadata_or_id ) ? $attachment_id : $metadata_or_id;
    $file          = get_attached_file( $attachment_id );
    $ext           = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );

    if ( 'webp' === $ext || ! wp_attachment_is_image( $attachment_id ) || get_post_meta( $attachment_id, 'ssic_converted', true ) ) {
        return $metadata_or_id;
    }

    $quality     = (int) get_option( 'ssic_quality', 85 );
    $dest        = preg_replace( '/\.[^.]+$/', '.webp', $file );

    if ( file_exists( $dest ) ) {
        update_post_meta( $attachment_id, 'ssic_converted', time() );
        return $metadata_or_id;
    }

    $ok = false;

    if ( class_exists( 'Imagick' ) ) {
        try {
            $i = new Imagick( $file );
            $i->setImageFormat( 'webp' );
            $i->setImageCompressionQuality( $quality );
            $ok = $i->writeImage( $dest );
            $i->clear(); $i->destroy();
        } catch ( Exception $e ) {}
    }

    if ( ! $ok && function_exists( 'imagewebp' ) ) {
        switch ( $ext ) {
            case 'jpg':
            case 'jpeg':
                $img = imagecreatefromjpeg( $file ); break;
            case 'png':
                $img = imagecreatefrompng( $file ); break;
            case 'gif':
                $img = imagecreatefromgif( $file ); break;
            default:
                $img = false;
        }
        if ( $img ) {
            if ( function_exists( 'imagepalettetotruecolor' ) ) {
                @imagepalettetotruecolor( $img );
            }
            $ok = @imagewebp( $img, $dest, $quality );
            imagedestroy( $img );
        }
    }

    if ( $ok ) {
        update_post_meta( $attachment_id, 'ssic_converted', time() );
    }

    return $metadata_or_id;
}

// Serve WebP URLs
add_filter( 'wp_get_attachment_url',   'ssic_serve_webp',       10, 2 );
add_filter( 'wp_get_attachment_image_src', 'ssic_src_webp',     10, 4 );
add_filter( 'wp_calculate_image_srcset',    'ssic_srcset_webp', 10, 5 );
function ssic_serve_webp( $url, $id ) {
    $file = get_attached_file( $id );
    $webp = preg_replace( '/\.[^.]+$/', '.webp', $file );
    if ( file_exists( $webp ) ) {
        return preg_replace( '/\.[^.]+$/', '.webp', $url );
    }
    return $url;
}
function ssic_src_webp( $image, $id, $size, $icon ) {
    $url  = $image[0];
    $path = str_replace( wp_get_upload_dir()['baseurl'], wp_get_upload_dir()['basedir'], $url );
    $webp = preg_replace( '/\.[^.]+$/', '.webp', $path );
    if ( file_exists( $webp ) ) {
        $image[0] = preg_replace( '/\.[^.]+$/', '.webp', $url );
    }
    return $image;
}
function ssic_srcset_webp( $sources, $size, $src, $meta, $id ) {
    foreach ( $sources as $w => $s ) {
        $u    = $s['url'];
        $path = str_replace( wp_get_upload_dir()['baseurl'], wp_get_upload_dir()['basedir'], $u );
        $webp = preg_replace( '/\.[^.]+$/', '.webp', $path );
        if ( file_exists( $webp ) ) {
            $sources[$w]['url'] = preg_replace( '/\.[^.]+$/', '.webp', $u );
        }
    }
    return $sources;
}

// Settings page: quality slider + mass convert button
function ssic_settings_page_content() {
    $quality = (int) get_option( 'ssic_quality', 85 );
    ?>
    <div class="wrap">
        <h1>Image Converter</h1>
        <form method="post" action="options.php">
            <?php settings_fields( 'ssic_settings_group' ); ?>
            <?php do_settings_sections( 'ssic_settings_group' ); ?>
            <table class="form-table">
                <tr>
                    <td colspan="2" style="padding-left:0;">
                        <input type="range" id="ssic_quality_slider" min="0" max="2" step="1" aria-label="Image quality slider" />
                        <span id="ssic_quality_label" style="margin-left:10px;"></span>
                        <input type="hidden" name="ssic_quality" id="ssic_quality" value="<?php echo esc_attr( $quality ); ?>" />
                        <p class="description">Choose WebP quality: 50%, 85%, or 100%.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <hr />
        <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
            <?php wp_nonce_field( 'ssic_mass_convert' ); ?>
            <input type="hidden" name="action" value="ssic_mass_convert" />
            <?php submit_button( 'Convert Existing Images', 'secondary' ); ?>
        </form>
    </div>
    <script>
    (function(){
        var opts   = [50,85,100];
        var slider = document.getElementById('ssic_quality_slider');
        var hidden = document.getElementById('ssic_quality');
        var label  = document.getElementById('ssic_quality_label');
        var idx    = opts.indexOf(parseInt(hidden.value,10));
        slider.value = idx<0?1:idx;
        slider.oninput = function(){ hidden.value=opts[this.value]; label.textContent=opts[this.value]+'%'; };
        label.textContent = opts[slider.value]+'%';
    })();
    </script>
    <?php
}
