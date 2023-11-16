<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( 'acf_pro_updates' ) ) :

	class acf_pro_updates {

		/**
		 * Initialize filters, action, variables and includes
		 *
		 * @date  23/06/12
		 * @since 5.0.0
		 */
		public function __construct() {
			add_action( 'init', array( $this, 'init' ), 20 );
		}

		/**
		 * Initializes the ACF PRO updates functionality.
		 *
		 *  @date    10/4/17
		 *  @since   5.5.10
		 */
		public function init() {
			// Bail early if no show_updates.
			if ( ! acf_get_setting( 'show_updates' ) ) {
				return;
			}

			// Bail early if not a plugin (included in theme).
			if ( ! acf_is_plugin_active() ) {
				return;
			}

			acf_register_plugin_update(
				array(
					'id'       => 'pro',
					'key'      => acf_pro_get_license_key(),
					'slug'     => acf_get_setting( 'slug' ),
					'basename' => acf_get_setting( 'basename' ),
					'version'  => acf_get_setting( 'version' ),
				)
			);

			add_action( 'admin_init', 'acf_pro_check_defined_license', 20 );
			add_action( 'admin_init', 'acf_pro_maybe_reactivate_license', 25 );
			add_action( 'current_screen', 'acf_pro_display_activation_error', 30 );

			if ( is_admin() ) {
				add_action( 'in_plugin_update_message-' . acf_get_setting( 'basename' ), array( $this, 'modify_plugin_update_message' ), 10, 2 );
			}
		}

		/**
		 * Displays an update message for plugin list screens.
		 *
		 * @date    14/06/2016
		 * @since   5.3.8
		 *
		 * @param array  $plugin_data An array of plugin metadata.
		 * @param object $response    An object of metadata about the available plugin update.
		 * @return void
		 */
		public function modify_plugin_update_message( $plugin_data, $response ) {
			// Bail early if we have a key.
			if ( acf_pro_get_license_key() ) {
				return;
			}

			// Display message.
			echo '<br />' . sprintf( __( 'To enable updates, please enter your license key on the <a href="%1$s">Updates</a> page. If you don\'t have a license key, please see <a href="%2$s" target="_blank">details & pricing</a>.', 'acf' ), admin_url( 'edit.php?post_type=acf-field-group&page=acf-settings-updates' ), acf_add_url_utm_tags( 'https://www.advancedcustomfields.com/pro/', 'ACF upgrade', 'updates' ) );
		}
	}

	new acf_pro_updates();

endif; // class_exists check

/**
 * Check if a license is defined in wp-config.php and requires activation.
 * Also checks if the license key has been changed and reactivates.
 *
 * @date 29/09/2021
 * @since 5.11.0
 */
function acf_pro_check_defined_license() {

	// Bail early if the license is not defined in wp-config.
	if ( ! defined( 'ACF_PRO_LICENSE' ) || empty( ACF_PRO_LICENSE ) || ! is_string( ACF_PRO_LICENSE ) ) {
		return;
	}

	// Bail early if no show_admin.
	if ( ! acf_get_setting( 'show_admin' ) ) {
		return;
	}

	// Check if we've been asked to clear the transient to retry activation.
	if ( acf_verify_nonce( 'acf_delete_activation_transient' ) || ( isset( $_REQUEST['acf_retry_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['acf_retry_nonce'] ) ), 'acf_retry_activation' ) ) ) {
		delete_transient( 'acf_activation_error' );
	} else {
		// If we've failed activation recently, check if the key has been changed, otherwise return.
		$activation_data = acf_pro_get_activation_failure_transient();
		if ( $activation_data && $activation_data['license'] === ACF_PRO_LICENSE ) {
			return;
		}
	}

	// If we're already activated, check if the defined license key has changed.
	$license = acf_pro_get_license();
	if ( $license ) {

		// Check the saved license key against the defined key.
		if ( acf_pro_get_license_key() !== ACF_PRO_LICENSE ) {

			// Deactivate if the key has changed.
			$deactivation_response = acf_pro_deactivate_license( true );

			// A connection error occurred while trying to deactivate.
			if ( is_wp_error( $deactivation_response ) ) {
				return acf_pro_set_activation_failure_transient( __( 'Your defined license key has changed, but an error occurred when connecting to activation server', 'acf' ) . ' <span class="description">(' . esc_html( $deactivation_response->get_error_message() ) . ').</span>', ACF_PRO_LICENSE );
				// A deactivation error occurred. Display the message returned by our API.
			} elseif ( ! $deactivation_response['success'] ) {
				return acf_pro_set_activation_failure_transient( __( 'Your defined license key has changed, but an error occurred when deactivating your old license', 'acf' ) . ' <span class="description">(' . $deactivation_response['message'] . ').</span>', ACF_PRO_LICENSE );
			}
		} else {

			// Check if the license has been marked as invalid during the update check.
			$basename = acf_get_setting( 'basename' );
			$update   = acf_updates()->get_plugin_update( $basename );
			if ( isset( $update['license_valid'] ) && ! $update['license_valid'] ) {
				// Our site is not activated, so remove the license.
				acf_pro_update_license( '' );
			} else {
				// License key hasn't changed, we are activated and license is still valid, return.
				return;
			}
		}
	}

	// Activate the defined key license.
	$activation_response = acf_pro_activate_license( ACF_PRO_LICENSE, true, true );

	$error_text = false;

	// Activation was prevented by filter.
	if ( $activation_response === false ) {
		return;

		// A connection error occurred during activation.
	} elseif ( is_wp_error( $activation_response ) ) {
		$error_text = __( 'An error occurred when connecting to activation server', 'acf' ) . ' <span class="description">(' . esc_html( $activation_response->get_error_message() ) . ').</span>';

		// A deactivation error occurred. Display the message returned by our API.
	} elseif ( ! $activation_response['success'] ) {
		$error_text  = __( 'There was an issue activating your license key.', 'acf' ) . ' ';
		$error_text .= acf_pro_get_translated_connect_message( $activation_response['message'] );
	} else {

		// Delete any previously saved activation error transient.
		delete_transient( 'acf_activation_error' );

		// Use our own success message instead of the one from connect so it can be translated.
		acf_add_admin_notice(
			__( '<strong>ACF PRO &mdash;</strong> Your license key has been activated successfully. Access to updates, support &amp; PRO features is now enabled.', 'acf' ),
			'success'
		);

		return;
	}

	// Clear out old license status if something went wrong.
	acf_pro_remove_license_status();

	return acf_pro_set_activation_failure_transient( $error_text, ACF_PRO_LICENSE );
}

/**
 * Get translated upstream message
 *
 * @since   6.2.3
 * @param   string $text server side message string.
 *
 * @return  string a translated (or original, if unavailable), message string.
 */
function acf_pro_get_translated_connect_message( $text ) {

	if ( strpos( $text, 'key activated' ) !== false ) {
		return __( 'Your license key has been activated successfully. Access to updates, support &amp; PRO features is now enabled.', 'acf' );
	} elseif ( strpos( $text, 'key deactivated' ) !== false ) {
		return __( 'Your license key has been deactivated.', 'acf' );
	} elseif ( strpos( $text, 'key not found' ) !== false ) {
		$text = __( 'License key not found. Make sure you have copied your license key exactly as it appears in your receipt or your account.', 'acf' );

		$text .= sprintf(
			' <a href="%1$s" target="_blank">%2$s</a>',
			acf_add_url_utm_tags(
				'https://www.advancedcustomfields.com/my-account/view-licenses/',
				'activation error',
				'license key not found'
			),
			__( 'View your licenses', 'acf' )
		);

		return $text;
	} elseif ( strpos( $text, 'key unavailable' ) !== false ) {
		$text = __( 'Your license key has expired and cannot be activated.', 'acf' );

		$text .= sprintf(
			' <a href="%1$s" target="_blank">%2$s</a>',
			acf_add_url_utm_tags(
				'https://www.advancedcustomfields.com/my-account/subscriptions/',
				'activation error',
				'license key expired'
			),
			__( 'View your subscriptions', 'acf' )
		);

		return $text;
	} elseif ( strpos( $text, 'Activation limit reached' ) !== false ) {
		$text = __( 'You have reached the activation limit for the license.', 'acf' );

		$view_license = sprintf(
			'<a href="%1$s" target="_blank">%2$s</a>',
			acf_add_url_utm_tags(
				'https://www.advancedcustomfields.com/my-account/view-licenses/',
				'activation error',
				'license limit exceeded'
			),
			__( 'View your licenses', 'acf' )
		);

		$nonce       = wp_create_nonce( 'acf_retry_activation' );
		$check_again = sprintf(
			'<a href="%1$s">%2$s</a>',
			admin_url( 'edit.php?post_type=acf-field-group&page=acf-settings-updates&acf_retry_nonce=' . $nonce ),
			__( 'check again', 'acf' )
		);

		/* translators: %1$s - link to view licenses, %2$s - link to try activating license again */
		$text .= ' ' . sprintf( __( '%1$s or %2$s.', 'acf' ), $view_license, $check_again );
		return $text;
	}

	return $text;
}

/**
 *  Set the automatic activation failure transient
 *
 *  @date    11/10/2021
 *  @since   5.11.0
 *
 *  @param   string $error_text string containing the error text message.
 *  @param   string $license_key the license key that was used during the failed activation.
 *
 *  @return void
 */
function acf_pro_set_activation_failure_transient( $error_text, $license_key ) {
	set_transient(
		'acf_activation_error',
		array(
			'error'   => $error_text,
			'license' => $license_key,
		),
		HOUR_IN_SECONDS
	);
}

/**
 *  Get the automatic activation failure transient
 *
 *  @date    11/10/2021
 *  @since   5.11.0
 *
 *  @return array|false Activation failure transient array, or false if it's not set.
 */
function acf_pro_get_activation_failure_transient() {
	return get_transient( 'acf_activation_error' );
}

/**
 * Display the stored activation error
 *
 * @date    11/10/2021
 * @since   5.11.0
 */
function acf_pro_display_activation_error( $screen ) {
	// Return if we're not in admin.
	if ( ! is_admin() ) {
		return;
	}

	// Return if the current user cannot view ACF settings.
	if ( ! acf_current_user_can_admin() ) {
		return;
	}

	// Check if the transient exists.
	$activation_data = acf_pro_get_activation_failure_transient();

	// Return if the transient does not exist.
	if ( ! $activation_data ) {
		return;
	}

	// Return if on an ACF admin screen - we handle those notices differently.
	if ( acf_is_acf_admin_screen() ) {
		return;
	}

	// Check if the license key is defined. If not, delete the transient.
	if ( ! defined( 'ACF_PRO_LICENSE' ) || empty( ACF_PRO_LICENSE ) || ! is_string( ACF_PRO_LICENSE ) ) {
		delete_transient( 'acf_activation_error' );
		return;
	}

	// Prepend ACF PRO for context since we're not in an ACF PRO admin screen.
	$activation_data['error'] = __( '<strong>ACF PRO &mdash;</strong> ', 'acf' ) . $activation_data['error'];

	// Append a retry link if we're not already on the settings page and we don't already have a link from upstream.
	if ( ! acf_is_screen( 'acf_page_acf-settings-updates' ) ) {
		if ( strpos( $activation_data['error'], 'http' ) === false ) {
			$nonce                    = wp_create_nonce( 'acf_retry_activation' );
			$check_again_url          = admin_url( 'edit.php?post_type=acf-field-group&page=acf-settings-updates&acf_retry_nonce=' . $nonce );
			$activation_data['error'] = $activation_data['error'] . ' <a href="' . $check_again_url . '">' . __( 'Check again', 'acf' ) . '</a>';
		}
	}

	// Add a non-dismissible error message with the activation error.
	acf_add_admin_notice( acf_esc_html( $activation_data['error'] ), 'error', false );
}

/**
 *  This function will return the license
 *
 *  @type    function
 *  @date    20/09/2016
 *  @since   5.4.0
 *
 *  @return  $license    Activated license array
 */
function acf_pro_get_license() {

	// get option
	$license = get_option( 'acf_pro_license' );

	// bail early if no value
	if ( ! $license ) {
		return false;
	}

	// decode
	$license = acf_maybe_unserialize( base64_decode( $license ) );

	// bail early if corrupt
	if ( ! is_array( $license ) ) {
		return false;
	}

	// return
	return $license;
}

/**
 * An ACF specific getter to replace `home_url` in our license checks to ensure we can avoid third party filters.
 *
 * @since 6.0.1
 *
 * @return string $home_url The output from home_url, sans known third party filters which cause license activation issues.
 */
function acf_get_home_url() {
	// Disable WPML and TranslatePress's home url overrides for our license check.
	add_filter( 'wpml_get_home_url', 'acf_license_ml_intercept', 99, 2 );
	add_filter( 'trp_home_url', 'acf_license_ml_intercept', 99, 2 );

	$home_url = home_url();

	// Re-enable WPML and TranslatePress's home url overrides.
	remove_filter( 'wpml_get_home_url', 'acf_license_ml_intercept', 99 );
	remove_filter( 'trp_home_url', 'acf_license_ml_intercept', 99 );

	return $home_url;
}

/**
 * Return the original home url inside ACF's home url getter.
 *
 * @since 6.0.1
 *
 * @param string $home_url the multilingual plugin converted home URL.
 * @param string $url the original home URL.
 *
 * @return string $url
 */
function acf_license_ml_intercept( $home_url, $url ) {
	return $url;
}

/**
 * Returns the license key.
 *
 * @since 5.4.0
 *
 * @param boolean $skip_url_check Skip the check of the current site url.
 * @return string|bool License key on success, or false on failure.
 */
function acf_pro_get_license_key( $skip_url_check = false ) {
	$license = acf_pro_get_license();

	// Bail early if empty.
	if ( empty( $license['key'] ) ) {
		return false;
	}

	// Bail if URL has changed since activating license.
	if ( ! $skip_url_check && acf_pro_has_license_url_changed( $license ) ) {
		return false;
	}

	return $license['key'];
}

/**
 *  This function will update the DB license
 *
 *  @type    function
 *  @date    20/09/2016
 *  @since   5.4.0
 *
 *  @param   string $key    The license key
 *  @return  bool           The result of the update_option call
 */
function acf_pro_update_license( $key = '' ) {

	// vars
	$value = '';

	// key
	if ( $key ) {

		// vars
		$data = array(
			'key' => $key,
			'url' => acf_get_home_url(),
		);

		// encode
		$value = base64_encode( maybe_serialize( $data ) );

	}

	// re-register update (key has changed)
	acf_register_plugin_update(
		array(
			'id'       => 'pro',
			'key'      => $key,
			'slug'     => acf_get_setting( 'slug' ),
			'basename' => acf_get_setting( 'basename' ),
			'version'  => acf_get_setting( 'version' ),
		)
	);

	// update
	return update_option( 'acf_pro_license', $value );
}

/**
 * Get count of registered ACF Blocks
 *
 * @return int
 */
function acf_pro_get_registered_block_count() {
	return acf_get_store( 'block-types' )->count();
}

/**
 * Activates the submitted license key
 * Formally ACF_Admin_Updates::activate_pro_licence since 5.0.0
 *
 * @since   5.11.0
 *
 * @param   string  $license_key    License key to activate.
 * @param   boolean $silent         Return errors rather than displaying them.
 * @param   boolean $automatic      True if this activation is happening automatically.
 * @return  mixed   $response       A wp-error instance, or an array with a boolean success key, and string message key.
 */
function acf_pro_activate_license( $license_key, $silent = false, $automatic = false ) {

	// Connect to API.
	$post = array(
		'acf_license'  => trim( $license_key ),
		'acf_version'  => acf_get_setting( 'version' ),
		'wp_name'      => get_bloginfo( 'name' ),
		'wp_url'       => acf_get_home_url(),
		'wp_version'   => get_bloginfo( 'version' ),
		'wp_language'  => get_bloginfo( 'language' ),
		'wp_timezone'  => get_option( 'timezone_string' ),
		'wp_multisite' => (int) is_multisite(),
		'php_version'  => PHP_VERSION,
		'block_count'  => acf_pro_get_registered_block_count(),
	);

	$activation_url = 'v2/plugins/activate?p=pro';
	if ( $automatic ) {
		if ( ! apply_filters( 'acf/automatic_license_reactivation', true ) ) {
			return false;
		}
		$activation_url .= '&automatic=true';
	}

	$response = acf_updates()->request( $activation_url, $post );

	// Check response is expected JSON array (not string).
	if ( is_string( $response ) ) {
		$response = new WP_Error( 'server_error', esc_html( $response ) );
	}

	// Display error.
	if ( is_wp_error( $response ) ) {
		if ( ! $silent ) {
			display_wp_activation_error( $response );
		}
		return $response;
	}

	$success = false;

	// On success.
	if ( $response['status'] == 1 ) {

		// Update license and clear out existing license status.
		acf_pro_update_license( $response['license'] );
		acf_pro_remove_license_status();

		if ( ! empty( $response['license_status'] ) ) {
			acf_pro_update_license_status( $response['license_status'] );
		}

		// Refresh plugins transient to fetch new update data.
		acf_updates()->refresh_plugins_transient();

		// Show notice.
		if ( ! $silent ) {
			acf_add_admin_notice( acf_esc_html( acf_pro_get_translated_connect_message( $response['message'] ) ), 'success' );
		}

		$success = true;

		// On failure.
	} else {

		// Show notice.
		if ( ! $silent ) {
			acf_add_admin_notice( acf_esc_html( acf_pro_get_translated_connect_message( $response['message'] ) ), 'warning' );
		}
	}

	// Return status array for automated activation error notices
	return array(
		'success' => $success,
		'message' => $response['message'],
	);
}

/**
 * Deactivates the registered license key.
 * Formally ACF_Admin_Updates::deactivate_pro_licence since 5.0.0
 *
 * @date    30/09/2021
 * @since   5.11.0
 *
 * @param   bool $silent     Return errors rather than displaying them
 * @return  mixed   $response   A wp-error instance, or an array with a boolean success key, and string message key
 */
function acf_pro_deactivate_license( $silent = false ) {

	// Get license key.
	$license = acf_pro_get_license_key( true );

	// Bail early if no key.
	if ( ! $license ) {
		return false;
	}

	// Connect to API.
	$post     = array(
		'acf_license' => $license,
		'wp_url'      => acf_get_home_url(),
	);
	$response = acf_updates()->request( 'v2/plugins/deactivate?p=pro', $post );

	// Check response is expected JSON array (not string).
	if ( is_string( $response ) ) {
		$response = new WP_Error( 'server_error', esc_html( $response ) );
	}

	// Display error.
	if ( is_wp_error( $response ) ) {
		if ( ! $silent ) {
			display_wp_activation_error( $response );
		}
		return $response;
	}

	// Remove license key and status from DB.
	acf_pro_update_license( '' );
	acf_pro_remove_license_status();

	// Refresh plugins transient to fetch new update data.
	acf_updates()->refresh_plugins_transient();

	$success = $response['status'] == 1;

	if ( ! $silent ) {
		$notice_class = $success ? 'info' : 'warning';
		acf_add_admin_notice( acf_esc_html( acf_pro_get_translated_connect_message( $response['message'] ) ), $notice_class );
	}

	// Return status array for automated activation error notices
	return array(
		'success' => $success,
		'message' => $response['message'],
	);
}


/**
 * Adds an admin notice using the provided WP_Error.
 *
 * @date    14/1/19
 * @since   5.7.10
 *
 * @param   WP_Error $wp_error The error to display.
 */
function display_wp_activation_error( $wp_error ) {

	// Only show one error on page.
	if ( acf_has_done( 'display_wp_error' ) ) {
		return;
	}

	// Create new notice.
	acf_new_admin_notice(
		array(
			'text' => __( 'Could not connect to the activation server', 'acf' ) . ' <span class="description">(' . esc_html( $wp_error->get_error_message() ) . ').</span>',
			'type' => 'error',
		)
	);
}

/**
 * Returns the status of the current ACF PRO license.
 *
 * @since 6.2.2
 *
 * @param bool $force_check If we should force a call to the API.
 * @return array
 */
function acf_pro_get_license_status( $force_check = false ) {
	$license = acf_pro_get_license_key( true );

	// Defined licenses may not have a license stored in the database.
	if ( ! $license && defined( 'ACF_PRO_LICENSE' ) ) {
		$license = ACF_PRO_LICENSE;
	}

	$status     = get_option( 'acf_pro_license_status', array() );
	$next_check = isset( $status['next_check'] ) ? (int) $status['next_check'] : 0;

	// Call the API if necessary, if we have a license.
	if ( ( empty( $status ) || $force_check || time() > $next_check ) && $license ) {
		$post = array(
			'acf_license' => $license,
			'wp_url'      => acf_get_home_url(),
		);

		$response   = acf_updates()->request( 'v2/plugins/validate?p=pro', $post );
		$expiration = acf_updates()->get_expiration( $response, DAY_IN_SECONDS, MONTH_IN_SECONDS );

		if ( is_array( $response ) ) {
			if ( ! empty( $response['license_status'] ) ) {
				$status = $response['license_status'];
			}

			// Handle errors from connect.
			if ( ! empty( $response['code'] ) && 'activation_not_found' === $response['code'] ) {
				$status['error_msg'] = sprintf(
					/* translators: %s - URL to ACF updates page */
					__( 'Your license key is valid but not activated on this site. Please <a href="%s">deactivate</a> and then reactivate the license.', 'acf' ),
					esc_url( admin_url( 'edit.php?post_type=acf-field-group&page=acf-settings-updates#deactivate-license' ) )
				);
			} elseif ( ! empty( $response['message'] ) ) {
				$status['error_msg'] = acf_esc_html( $response['message'] );
			}
		}

		$status['next_check'] = time() + $expiration;
		acf_pro_update_license_status( $status );
	}

	return acf_pro_parse_license_status( $status );
}

/**
 * Makes sure the ACF PRO license status is in a format we expect.
 *
 * @since 6.2.2
 *
 * @param array $status The license status.
 * @return array
 */
function acf_pro_parse_license_status( $status = array() ) {
	$status  = is_array( $status ) ? $status : array();
	$default = array(
		'status'                  => '',
		'created'                 => 0,
		'expiry'                  => 0,
		'name'                    => '',
		'lifetime'                => false,
		'refunded'                => false,
		'view_licenses_url'       => '',
		'manage_subscription_url' => '',
		'error_msg'               => '',
		'next_check'              => 0,
	);

	return wp_parse_args( $status, $default );
}

/**
 * Updates the ACF PRO license status.
 *
 * @since 6.2.2
 *
 * @param array $status The current license status.
 * @return bool True if the value was set, false otherwise.
 */
function acf_pro_update_license_status( $status ) {
	return update_option(
		'acf_pro_license_status',
		acf_pro_parse_license_status( $status )
	);
}

/**
 * Removes the ACF PRO license status.
 *
 * @since 6.2
 *
 * @return bool True if the transient was deleted, false otherwise.
 */
function acf_pro_remove_license_status() {
	return delete_option( 'acf_pro_license_status' );
}

/**
 * Checks if the current license is active.
 *
 * @since 6.2.2
 *
 * @param array $status Optional license status array.
 * @return bool True if active, false if not.
 */
function acf_pro_is_license_active( $status = array() ) {
	if ( empty( $status ) ) {
		$status = acf_pro_get_license_status();
	}

	return 'active' === $status['status'];
}

/**
 * Checks if the current license is expired.
 *
 * @since 6.2.2
 *
 * @param array $status Optional license status array.
 * @return bool True if expired, false if not.
 */
function acf_pro_is_license_expired( $status = array() ) {
	if ( empty( $status ) ) {
		$status = acf_pro_get_license_status();
	}

	return in_array( $status['status'], array( 'expired', 'cancelled' ), true );
}

/**
 * Checks if the current license was refunded.
 *
 * @since 6.2.2
 *
 * @param array $status Optional license status array.
 * @return bool True if refunded, false if not.
 */
function acf_pro_was_license_refunded( $status = array() ) {
	if ( empty( $status ) ) {
		$status = acf_pro_get_license_status();
	}

	return ! empty( $status['refunded'] );
}

/**
 * Checks if the `home_url` has changed since license activation.
 *
 * @since 6.2.2
 *
 * @param array  $license Optional ACF license array.
 * @param string $url     An optional URL to provide.
 * @return bool           True if the URL has changed, false otherwise.
 */
function acf_pro_has_license_url_changed( $license = array(), $url = '' ) {
	$license  = ! empty( $license ) ? $license : acf_pro_get_license();
	$home_url = ! empty( $url ) ? $url : acf_get_home_url();

	// We can't know without a license, so let's assume not.
	if ( ! is_array( $license ) || empty( $license['url'] ) ) {
		return false;
	}

	// We don't care if the protocol changed.
	$license_url = acf_strip_protocol( (string) $license['url'] );
	$home_url    = acf_strip_protocol( $home_url );

	// Treat www the same as non-www.
	if ( substr( $license_url, 0, 4 ) === 'www.' ) {
		$license_url = substr( $license_url, 4 );
	}

	if ( substr( $home_url, 0, 4 ) === 'www.' ) {
		$home_url = substr( $home_url, 4 );
	}

	// URLs do not match.
	if ( $license_url !== $home_url ) {
		return true;
	}

	return false;
}

/**
 * Attempts to reactivate the license if the URL has changed.
 *
 * @since 6.2.3
 *
 * @return void
 */
function acf_pro_maybe_reactivate_license() {
	// Defined licenses have separate logic.
	if ( defined( 'ACF_PRO_LICENSE' ) ) {
		return;
	}

	// Bail if we tried this recently.
	if ( get_transient( 'acf_pro_license_reactivated' ) ) {
		return;
	}

	$license = acf_pro_get_license();

	// Nothing to do if URL hasn't changed.
	if ( empty( $license['key'] ) || empty( $license['url'] ) || ! acf_pro_has_license_url_changed( $license ) ) {
		return;
	}

	// Set a transient, so we don't keep trying this in a short period.
	set_transient( 'acf_pro_license_reactivated', true, 3 * HOUR_IN_SECONDS );

	// Prevent subsequent attempts at reactivation by updating the license URL.
	acf_pro_update_license( $license['key'] );

	// Attempt to reactivate the license with the current URL.
	$reactivation = acf_pro_activate_license( $license['key'], true, true );

	// Update license status on failure.
	if ( is_wp_error( $reactivation ) || ! is_array( $reactivation ) || empty( $reactivation['success'] ) ) {
		$license_status           = acf_pro_get_license_status();
		$license_status['status'] = 'inactive';

		if ( is_array( $reactivation ) && ! empty( $reactivation['message'] ) ) {
			$license_status['error_msg'] = sprintf(
				/* translators: %s - more details about the error received */
				__( "Your site URL has changed since last activating your license, but we weren't able to automatically reactivate it: %s", 'acf' ),
				acf_pro_get_translated_connect_message( $reactivation['message'] )
			);
		}

		acf_pro_update_license_status( $license_status );
	} else {
		acf_add_admin_notice(
			__( "Your site URL has changed since last activating your license. We've automatically activated it for this site URL.", 'acf' ),
			'success'
		);
	}
}

/**
 * Gets the URL to the "My Account" section for an ACF license.
 *
 * @since 6.2.3
 *
 * @param array $status Optional license status array.
 * @return string
 */
function acf_pro_get_manage_license_url( $status = array() ) {
	if ( empty( $status ) ) {
		$status = acf_pro_get_license_status();
	}

	$url = 'https://www.advancedcustomfields.com/my-account/view-licenses/';

	if ( ! empty( $status['manage_subscription_url'] ) ) {
		$url = $status['manage_subscription_url'];
	} elseif ( ! empty( $status['view_licenses_url'] ) ) {
		$url = $status['view_licenses_url'];
	}

	return $url;
}
