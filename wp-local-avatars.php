<?php
/**
 * Plugin Name: Local Avatars
 * Plugin URI: https://github.com/Azanumer/wp-local-avatars
 * Description: Caches Gravatar images locally in your uploads folder and serves them from your own domain — faster avatars, no third-party requests on every page load, and full GDPR-friendliness.
 * Version: 1.0.0
 * Author: Azan Umer
 * Author URI: https://azanumer.cv
 * License: MIT
 * Text Domain: wp-local-avatars
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WLA_VERSION', '1.0.0' );
define( 'WLA_OPTION', 'wla_settings' );
define( 'WLA_DIR_NAME', 'local-avatars' );

/**
 * Default settings.
 *
 * @return array
 */
function wla_default_settings() {
	return array(
		'enabled'       => 1,
		'max_size'      => 256,
		'refresh_days'  => 7,
		'default'       => 'mp', // Mystery Person fallback, same as Gravatar's "mp".
	);
}

/**
 * Get merged settings.
 *
 * @return array
 */
function wla_get_settings() {
	$saved = get_option( WLA_OPTION, array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	return array_merge( wla_default_settings(), $saved );
}

/**
 * Local cache directory (uploads/local-avatars). Creates it on demand.
 *
 * @return string|false Absolute path, or false when uploads are unavailable.
 */
function wla_cache_dir() {
	$upload = wp_upload_dir();
	if ( empty( $upload['basedir'] ) ) {
		return false;
	}
	$dir = trailingslashit( $upload['basedir'] ) . WLA_DIR_NAME;
	if ( ! file_exists( $dir ) ) {
		wp_mkdir_p( $dir );
	}
	return $dir;
}

/**
 * Local cache URL.
 *
 * @return string|false
 */
function wla_cache_url() {
	$upload = wp_upload_dir();
	if ( empty( $upload['baseurl'] ) ) {
		return false;
	}
	return trailingslashit( $upload['baseurl'] ) . WLA_DIR_NAME;
}

/**
 * Build the local file name for an avatar.
 *
 * The hash covers email + size, so different sizes get different files
 * and a changed Gravatar is picked up again after the refresh window.
 *
 * @param string $email Email address (already lower-cased + trimmed).
 * @param int    $size  Requested size in pixels.
 * @return string File name, e.g. "a1b2c3d4...-96.jpg".
 */
function wla_local_filename( $email, $size ) {
	return md5( $email . '|' . (int) $size ) . '-' . (int) $size . '.jpg';
}

/**
 * Download a Gravatar to the local cache. Returns the local URL on success.
 *
 * @param string $email Email address.
 * @param int    $size  Requested size in pixels.
 * @return string|false Local URL, or false on any failure.
 */
function wla_fetch_avatar( $email, $size ) {
	$settings = wla_get_settings();
	$dir      = wla_cache_dir();
	$url_base = wla_cache_url();
	if ( ! $dir || ! $url_base ) {
		return false;
	}

	$filename = wla_local_filename( $email, $size );
	$path     = trailingslashit( $dir ) . $filename;

	// Serve a fresh-enough cached copy without touching the network.
	if ( file_exists( $path ) ) {
		$age = time() - (int) filemtime( $path );
		if ( $age < (int) $settings['refresh_days'] * DAY_IN_SECONDS ) {
			return trailingslashit( $url_base ) . $filename;
		}
	}

	$hash        = md5( strtolower( trim( $email ) ) );
	$remote_url  = 'https://secure.gravatar.com/avatar/' . $hash
		. '?s=' . (int) $size
		. '&d=' . rawurlencode( $settings['default'] )
		. '&r=g';
	$response    = wp_remote_get( $remote_url, array( 'timeout' => 10 ) );
	if ( is_wp_error( $response ) ) {
		// Network hiccup: keep serving the stale copy if one exists.
		return file_exists( $path ) ? trailingslashit( $url_base ) . $filename : false;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$body = wp_remote_retrieve_body( $response );
	if ( 200 !== $code || '' === $body ) {
		return file_exists( $path ) ? trailingslashit( $url_base ) . $filename : false;
	}

	// Only store real images — a 200 with an HTML error page must never
	// be cached as an avatar.
	$type = wp_remote_retrieve_header( $response, 'content-type' );
	if ( false === strpos( (string) $type, 'image/' ) ) {
		return false;
	}

	// Write atomically so a concurrent request never sees a half file.
	$tmp = $path . '.tmp.' . wp_generate_password( 8, false );
	if ( false === file_put_contents( $tmp, $body ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return false;
	}
	rename( $tmp, $path );

	return trailingslashit( $url_base ) . $filename;
}

/**
 * Intercept avatar data and point the URL at the local copy.
 *
 * Hooked to pre_get_avatar_data so it works for get_avatar(), comments,
 * author boxes and anything else that goes through the avatar API.
 *
 * @param array $args Avatar arguments.
 * @param mixed $id_or_email User ID, email, comment object, etc.
 * @return array
 */
function wla_filter_avatar_data( $args, $id_or_email ) {
	$settings = wla_get_settings();
	if ( empty( $settings['enabled'] ) ) {
		return $args;
	}

	$email = '';
	if ( is_object( $id_or_email ) ) {
		if ( isset( $id_or_email->comment_author_email ) ) {
			$email = $id_or_email->comment_author_email;
		} elseif ( isset( $id_or_email->user_email ) ) {
			$email = $id_or_email->user_email;
		}
	} elseif ( is_numeric( $id_or_email ) ) {
		$user = get_user_by( 'id', (int) $id_or_email );
		if ( $user ) {
			$email = $user->user_email;
		}
	} elseif ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
		$email = $id_or_email;
	} elseif ( is_string( $id_or_email ) ) {
		// A display name or URL was passed — no email to hash.
		return $args;
	}

	if ( '' === $email ) {
		return $args;
	}

	$size = isset( $args['size'] ) ? (int) $args['size'] : 96;
	$size = min( $size, (int) $settings['max_size'] );

	$local = wla_fetch_avatar( $email, $size );
	if ( $local ) {
		$args['url'] = $local;
		// Tell WordPress the avatar is already local so it does not
		// append its own default-avatar query string.
		$args['found_avatar'] = true;
	}

	return $args;
}
add_filter( 'pre_get_avatar_data', 'wla_filter_avatar_data', 10, 2 );

/**
 * Weekly cron: refresh stale cached avatars in the background so no
 * visitor ever waits on a Gravatar download.
 */
function wla_refresh_stale_avatars() {
	$dir = wla_cache_dir();
	if ( ! $dir || ! is_dir( $dir ) ) {
		return;
	}
	$settings = wla_get_settings();
	$max_age  = (int) $settings['refresh_days'] * DAY_IN_SECONDS;

	$files = glob( trailingslashit( $dir ) . '*.jpg' );
	if ( ! is_array( $files ) ) {
		return;
	}
	foreach ( $files as $file ) {
		if ( time() - (int) filemtime( $file ) < $max_age ) {
			continue;
		}
		// File name encodes "hash-size"; the email itself is not stored,
		// so a stale file is simply dropped and re-fetched on next view.
		unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
	}
}
add_action( 'wla_weekly_refresh', 'wla_refresh_stale_avatars' );

/**
 * Settings page under Settings → Local Avatars.
 */
function wla_register_settings_page() {
	add_options_page(
		__( 'Local Avatars', 'wp-local-avatars' ),
		__( 'Local Avatars', 'wp-local-avatars' ),
		'manage_options',
		'wp-local-avatars',
		'wla_render_settings_page'
	);
}
add_action( 'admin_menu', 'wla_register_settings_page' );

/**
 * Register settings.
 */
function wla_register_settings() {
	register_setting( 'wla_settings_group', WLA_OPTION, 'wla_sanitize_settings' );
}
add_action( 'admin_init', 'wla_register_settings' );

/**
 * Sanitize settings on save.
 *
 * @param array $input Raw input.
 * @return array
 */
function wla_sanitize_settings( $input ) {
	$out = wla_default_settings();
	if ( ! is_array( $input ) ) {
		return $out;
	}
	$out['enabled']      = ! empty( $input['enabled'] ) ? 1 : 0;
	$out['max_size']     = max( 32, min( 512, (int) ( $input['max_size'] ?? 256 ) ) );
	$out['refresh_days'] = max( 1, min( 90, (int) ( $input['refresh_days'] ?? 7 ) ) );
	$allowed_defaults    = array( 'mp', 'identicon', 'monsterid', 'wavatar', 'retro', 'robohash', 'blank' );
	$out['default']      = in_array( $input['default'] ?? 'mp', $allowed_defaults, true ) ? $input['default'] : 'mp';
	return $out;
}

/**
 * Render the settings page.
 */
function wla_render_settings_page() {
	$settings = wla_get_settings();
	$dir      = wla_cache_dir();
	$count    = 0;
	$total    = 0;
	if ( $dir && is_dir( $dir ) ) {
		$files = glob( trailingslashit( $dir ) . '*.jpg' );
		if ( is_array( $files ) ) {
			$count = count( $files );
			foreach ( $files as $file ) {
				$total += (int) filesize( $file );
			}
		}
	}

	// Handle the "Purge cache" button.
	if ( isset( $_POST['wla_purge'] ) && check_admin_referer( 'wla_purge_cache' ) ) {
		if ( $dir && is_dir( $dir ) ) {
			$files = glob( trailingslashit( $dir ) . '*.jpg' );
			if ( is_array( $files ) ) {
				foreach ( $files as $file ) {
					unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				}
			}
		}
		echo '<div class="notice notice-success"><p>' . esc_html__( 'Avatar cache purged.', 'wp-local-avatars' ) . '</p></div>';
		$count = 0;
		$total = 0;
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Local Avatars', 'wp-local-avatars' ); ?></h1>
		<p><?php esc_html_e( 'Avatars are downloaded once from Gravatar and served from your own uploads folder.', 'wp-local-avatars' ); ?></p>
		<p>
			<?php
			/* translators: %1$d cached avatars, %2$s total size. */
			printf(
				esc_html__( 'Cache: %1$d avatars, %2$s total.', 'wp-local-avatars' ),
				(int) $count,
				esc_html( size_format( $total ) )
			);
			?>
		</p>
		<form method="post" action="options.php">
			<?php settings_fields( 'wla_settings_group' ); ?>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable local avatars', 'wp-local-avatars' ); ?></th>
					<td><input type="checkbox" name="<?php echo esc_attr( WLA_OPTION ); ?>[enabled]" value="1" <?php checked( $settings['enabled'], 1 ); ?> /></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Maximum size (px)', 'wp-local-avatars' ); ?></th>
					<td><input type="number" name="<?php echo esc_attr( WLA_OPTION ); ?>[max_size]" value="<?php echo (int) $settings['max_size']; ?>" min="32" max="512" step="1" /></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Refresh after (days)', 'wp-local-avatars' ); ?></th>
					<td><input type="number" name="<?php echo esc_attr( WLA_OPTION ); ?>[refresh_days]" value="<?php echo (int) $settings['refresh_days']; ?>" min="1" max="90" step="1" /></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Default avatar', 'wp-local-avatars' ); ?></th>
					<td>
						<select name="<?php echo esc_attr( WLA_OPTION ); ?>[default]">
							<?php
							$defaults = array(
								'mp'        => __( 'Mystery Person', 'wp-local-avatars' ),
								'identicon' => __( 'Identicon', 'wp-local-avatars' ),
								'monsterid' => __( 'MonsterID', 'wp-local-avatars' ),
								'wavatar'   => __( 'Wavatar', 'wp-local-avatars' ),
								'retro'     => __( 'Retro', 'wp-local-avatars' ),
								'robohash'  => __( 'RoboHash', 'wp-local-avatars' ),
								'blank'     => __( 'Blank', 'wp-local-avatars' ),
							);
							foreach ( $defaults as $value => $label ) {
								printf(
									'<option value="%s" %s>%s</option>',
									esc_attr( $value ),
									selected( $settings['default'], $value, false ),
									esc_html( $label )
								);
							}
							?>
						</select>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<form method="post">
			<?php wp_nonce_field( 'wla_purge_cache' ); ?>
			<input type="submit" name="wla_purge" class="button button-secondary" value="<?php esc_attr_e( 'Purge avatar cache', 'wp-local-avatars' ); ?>" />
		</form>
	</div>
	<?php
}

/**
 * Activation: schedule the weekly refresh.
 */
function wla_activate() {
	if ( ! wp_next_scheduled( 'wla_weekly_refresh' ) ) {
		wp_schedule_event( time(), 'weekly', 'wla_weekly_refresh' );
	}
}
register_activation_hook( __FILE__, 'wla_activate' );

/**
 * Deactivation: clear the scheduled refresh.
 */
function wla_deactivate() {
	wp_clear_scheduled_hook( 'wla_weekly_refresh' );
}
register_deactivation_hook( __FILE__, 'wla_deactivate' );
