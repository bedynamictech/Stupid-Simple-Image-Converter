<?php
/*
Plugin Name: Stupid Simple Image Converter
Description: Automatically convert uploaded PNG and JPG images to WebP format.
Version: 1.0
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

// Register settings for image quality
add_action( 'admin_init', 'ssic_register_settings' );
function ssic_register_settings() {
    register_setting( 'ssic_settings_group', 'ssic_quality', array(
        'type'              => 'integer',
        'description'       => 'Quality for WebP conversion (50, 85, or 100)',
        'default'           => 85,
        'sanitize_callback' => 'absint',
    ) );
}

// Add parent and submenu under Stupid Simple menu
add_action( 'admin_menu', 'ssic_add_menu' );
function ssic_add_menu() {
    add_menu_page(
        'Stupid Simple',
        'Stupid Simple',
        'manage_options',
        'stupidsimple',
        'ssic_settings_page_content',
        'dashicons-hammer',
        99
    );
    add_submenu_page(
        'stupidsimple',
        'Image Converter',
        'Image Converter',
        'manage_options',
        'ssic-settings',
        'ssic_settings_page_content'
    );
}

// Settings page content with stepped slider
function ssic_settings_page_content() {
    $quality = (int) get_option( 'ssic_quality', 85 );
    ?>
    <div class="wrap">
        <h1>Image Converter</h1>
        <form method="post" action="options.php">
            <?php settings_fields( 'ssic_settings_group' ); ?>
            <?php do_settings_sections( 'ssic_settings_group' ); ?>
            <table class="form-table">
                <tr valign="top">
                    <td colspan="2" style="padding-left:0;">
                        <input type="range" id="ssic_quality_slider" min="0" max="2" step="1" aria-label="Image quality slider" />
                        <span id="ssic_quality_label" style="margin-left:10px;"></span>
                        <input type="hidden" name="ssic_quality" id="ssic_quality" value="<?php echo esc_attr( $quality ); ?>" />
                        <p class="description">Choose the quality for WebP conversion: 50%, 85%, or 100%.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <script>
    (function() {
        var options = [50, 85, 100];
        var slider  = document.getElementById('ssic_quality_slider');
        var hidden  = document.getElementById('ssic_quality');
        var label   = document.getElementById('ssic_quality_label');
        var initial = parseInt(hidden.value, 10);
        var idx     = options.indexOf(initial);
        if ( idx < 0 ) idx = 1;
        slider.value = idx;
        function updateQuality() {
            var q = options[slider.value];
            hidden.value = q;
            label.textContent = q + '%';
        }
        slider.addEventListener('input', updateQuality);
        updateQuality();
    })();
    </script>
    <?php
}

// Hooks for conversion
add_filter( 'wp_generate_attachment_metadata', 'ssic_convert_to_webp', 10, 2 );
add_action( 'add_attachment', 'ssic_convert_to_webp', 10, 1 );

// Convert uploaded images to WebP
function ssic_convert_to_webp( $metadata_or_id, $attachment_id = null ) {
    if ( is_array( $metadata_or_id ) ) {
        $attachment_id = $attachment_id;
    } else {
        $attachment_id = $metadata_or_id;
    }

    $file = get_attached_file( $attachment_id );
    $ext  = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );

    if ( 'webp' === $ext || ! wp_attachment_is_image( $attachment_id ) ) {
        return $metadata_or_id;
    }

    $quality     = (int) get_option( 'ssic_quality', 85 );
    $destination = preg_replace( '/\.[^.]+$/', '.webp', $file );

    if ( class_exists( 'Imagick' ) ) {
        try {
            $imagick = new Imagick( $file );
            $imagick->setImageFormat( 'webp' );
            $imagick->setImageCompressionQuality( $quality );
            $imagick->writeImage( $destination );
            $imagick->clear();
            $imagick->destroy();
        } catch ( Exception $e ) {}
    } elseif ( function_exists( 'imagewebp' ) ) {
        switch ( $ext ) {
            case 'jpg': case 'jpeg': $image = imagecreatefromjpeg( $file ); break;
            case 'png': $image = imagecreatefrompng( $file ); break;
            case 'gif': $image = imagecreatefromgif( $file ); break;
            default: return $metadata_or_id;
        }
        if ( $image ) {
            if ( function_exists( 'imagepalettetotruecolor' ) ) {
                @imagepalettetotruecolor( $image );
            }
            @imagewebp( $image, $destination, $quality );
            imagedestroy( $image );
        }
    }

    return $metadata_or_id;
}

// Serve WebP instead of original URL when available
add_filter( 'wp_get_attachment_url', 'ssic_serve_webp', 10, 2 );
function ssic_serve_webp( $url, $post_id ) {
    $file = get_attached_file( $post_id );
    $webp = preg_replace( '/\.[^.]+$/', '.webp', $file );
    if ( file_exists( $webp ) ) {
        return preg_replace( '/\.[^.]+$/', '.webp', $url );
    }
    return $url;
}

// Replace src (single image) with WebP when available
add_filter( 'wp_get_attachment_image_src', 'ssic_src_webp', 10, 4 );
function ssic_src_webp( $image, $attachment_id, $size, $icon ) {
    $url  = $image[0];
    $path = str_replace( wp_get_upload_dir()['baseurl'], wp_get_upload_dir()['basedir'], $url );
    $webp = preg_replace( '/\.[^.]+$/', '.webp', $path );
    if ( file_exists( $webp ) ) {
        $image[0] = preg_replace( '/\.[^.]+$/', '.webp', $url );
    }
    return $image;
}

// Replace srcset URLs with WebP when available
add_filter( 'wp_calculate_image_srcset', 'ssic_srcset_webp', 10, 5 );
function ssic_srcset_webp( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
    foreach ( $sources as $width => $source ) {
        $url  = $source['url'];
        $path = str_replace( wp_get_upload_dir()['baseurl'], wp_get_upload_dir()['basedir'], $url );
        $webp = preg_replace( '/\.[^.]+$/', '.webp', $path );
        if ( file_exists( $webp ) ) {
            $sources[$width]['url'] = preg_replace( '/\.[^.]+$/', '.webp', $url );
        }
    }
    return $sources;
}
