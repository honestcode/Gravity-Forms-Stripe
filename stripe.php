<?php
/*
Plugin Name: Gravity Forms Stripe Add-On
Plugin URI: http://naomicbush.github.com/Gravity-Forms-Stripe
Description: Use Stripe to process credit card payments on your site, easily and securely, with Gravity Forms
Version: 0.2-beta
Author: Naomi C. Bush
Author URI: http://naomicbush.com

------------------------------------------------------------------------
Copyright 2012 Naomi C. Bush
last updated: May 8, 2012

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA 02111-1307 USA
*/

//Version Control
$gravityforms_stripe_file = __FILE__;

if ( isset( $plugin ) ) {
	$gravityforms_stripe_file = $plugin;
}
else if ( isset( $mu_plugin ) ) {
	$gravityforms_stripe_file = $mu_plugin;
}
else if ( isset( $network_plugin ) ) {
	$gravityforms_stripe_file = $network_plugin;
}

define( 'GRAVITYFORMS_STRIPE_FILE', $gravityforms_stripe_file );
define( 'GRAVITYFORMS_STRIPE_PATH', WP_PLUGIN_DIR . '/' . basename( dirname( $gravityforms_stripe_file ) ) );

add_action( 'init', array( 'GFStripe', 'init' ) );

//limits currency to US Dollars
add_filter( 'gform_currency', create_function( '', 'return "USD";' ) );
add_action( 'parse_request', array( 'GFStripe', 'parse_request' ) );

register_activation_hook( GRAVITYFORMS_STRIPE_FILE, array( 'GFStripe', 'add_permissions' ) );

class GFStripe {

	private static $version = '0.2';
	private static $min_gravityforms_version = '1.6.3.3';
	private static $transaction_response = '';
	private static $log = null;

	//Plugin starting point. Will load appropriate files
	public static function init() {
		self::$log = self::create_logger();

		if ( basename( $_SERVER[ 'PHP_SELF' ] ) == 'plugins.php' ) {

			//loading translations
			load_plugin_textdomain( 'gravityforms-stripe', FALSE, dirname( GRAVITYFORMS_STRIPE_FILE ) . '/languages' );

		}

		if ( ! self::is_gravityforms_supported() )
			return;

		if ( is_admin() ) {

			//runs the setup when version changes
			self::setup();

			//loading translations
			load_plugin_textdomain( 'gravityforms-stripe', FALSE, dirname( GRAVITYFORMS_STRIPE_FILE ) . '/languages' );

			//integrating with Members plugin
			if ( function_exists( 'members_get_capabilities' ) )
				add_filter( 'members_get_capabilities', array( 'GFStripe', 'members_get_capabilities' ) );

			//creates the subnav left menu
			add_filter( 'gform_addon_navigation', array( 'GFStripe', 'gform_addon_navigation' ) );

			//enables credit card field
			add_filter( 'gform_enable_credit_card_field', '__return_true' );

			if ( self::is_stripe_page() ) {

				//enqueueing sack for AJAX requests
				wp_enqueue_script( array( 'sack' ) );

				//loading data lib
				require_once( GRAVITYFORMS_STRIPE_PATH . '/data.php' );

				//loading Gravity Forms tooltips
				require_once( GFCommon::get_base_path() . '/tooltips.php' );
				add_filter( 'gform_tooltips', array( 'GFStripe', 'gform_tooltips' ) );

			}
			else if ( in_array( RG_CURRENT_PAGE, array( 'admin-ajax.php' ) ) ) {

				//loading data class
				require_once( GRAVITYFORMS_STRIPE_PATH . '/data.php' );

				add_action( 'wp_ajax_gf_stripe_update_feed_active', array( 'GFStripe', 'gf_stripe_update_feed_active' ) );
				add_action( 'wp_ajax_gf_select_stripe_form', array( 'GFStripe', 'gf_select_stripe_form' ) );
				add_action( 'wp_ajax_gf_cancel_stripe_subscription', array( 'GFStripe', 'cancel_stripe_subscription' ) );

			}
			else if ( 'gf_settings' == RGForms::get( 'page' ) ) {
				RGForms::add_settings_page( 'Stripe', array( 'GFStripe', 'settings_page' ), self::get_base_url() . '/images/stripe_wordpress_icon_32.png' );
				add_filter( 'gform_currency_setting_message', create_function( '', "echo '<div class=\'gform_currency_message\'>Stripe only supports US Dollars.</div>';" ) );
				add_filter( 'gform_currency_disabled', '__return_true' );

				//loading Gravity Forms tooltips
				require_once( GFCommon::get_base_path() . '/tooltips.php' );
				add_filter( 'gform_tooltips', array( 'GFStripe', 'gform_tooltips' ) );
			}
			else if ( 'gf_entries' == RGForms::get( 'page' ) ) {
				add_action( 'gform_entry_info', array( 'GFStripe', 'gform_entry_info' ), 10, 2 );
			}
		}
		else {
			//loading data class
			require_once( GRAVITYFORMS_STRIPE_PATH . '/data.php' );

			//load Stripe JS
			add_action( 'gform_enqueue_scripts', array( 'GFStripe', 'gform_enqueue_scripts' ), '', 2 );

			//remove SSL credit card warnings and input names from credit card field
			add_filter( 'gform_field_content', array( 'GFStripe', 'gform_field_content' ), 10, 5 );
			add_filter( 'gform_field_css_class', array( 'GFStripe', 'gform_field_css_class' ), 10, 3 );

			//handling post submission.
			add_filter( 'gform_field_validation', array( 'GFStripe', 'gform_field_validation' ), 10, 4 );
			add_filter( 'gform_get_form_filter', array( 'GFStripe', 'gform_get_form_filter' ), 10, 1 );
			add_filter( 'gform_validation', array( 'GFStripe', 'gform_validation' ), 10, 4 );
			add_action( 'gform_after_submission', array( 'GFStripe', 'gform_after_submission' ), 10, 2 );

		}
	}

	public static function gf_stripe_update_feed_active() {
		check_ajax_referer( 'gf_stripe_update_feed_active', 'gf_stripe_update_feed_active' );
		$id   = $_POST[ "feed_id" ];
		$feed = GFStripeData::get_feed( $id );
		GFStripeData::update_feed( $id, $feed[ "form_id" ], $_POST[ "is_active" ], $feed[ "meta" ] );
	}

	//------------------------------------------------------------------------

	//Creates Stripe left nav menu under Forms
	public static function gform_addon_navigation( $menus ) {

		// Adding submenu if user has access
		$permission = self::has_access( 'gravityforms_stripe' );
		if ( ! empty( $permission ) )
			$menus[ ] = array(
				'name'       => 'gf_stripe',
				'label'      => __( 'Stripe', 'gravityforms-stripe' ),
				'callback'   => array( 'GFStripe', 'stripe_page' ),
				'permission' => $permission );

		return $menus;
	}

	//Creates or updates database tables. Will only run when version changes
	private static function setup() {
		if ( get_option( 'gf_stripe_version' ) != self::$version ) {
			require_once( GRAVITYFORMS_STRIPE_PATH . '/data.php' );
			GFStripeData::update_table();
		}

		update_option( 'gf_stripe_version', self::$version );
	}

	private static function create_logger() {

		if ( ! class_exists( 'KLogger' ) ) {
			require_once( GRAVITYFORMS_STRIPE_PATH . '/KLogger.php' );
		}

		$settings  = get_option( 'gf_stripe_settings' );
		$log_level = rgempty( 'log_level', $settings ) ? KLogger::OFF : rgar( $settings, 'log_level' );

		$log = new KLogger( GRAVITYFORMS_STRIPE_PATH . '/log.txt', $log_level );
		return $log;
	}

	//Adds feed tooltips to the list of tooltips
	public static function gform_tooltips( $tooltips ) {
		$stripe_tooltips = array(
			'stripe_transaction_type'     => '<h6>' . __( 'Transaction Type', 'gravityforms-stripe' ) . '</h6>' . __( 'Select which Stripe transaction type should be used. Products and Services, Donations or Subscription.', 'gravityforms-stripe' ),
			'stripe_gravity_form'         => '<h6>' . __( 'Gravity Form', 'gravityforms-stripe' ) . '</h6>' . __( 'Select which Gravity Forms you would like to integrate with Stripe.', 'gravityforms-stripe' ),
			'stripe_customer'             => '<h6>' . __( 'Customer', 'gravityforms-stripe' ) . '</h6>' . __( 'Map your Form Fields to the available Stripe customer information fields.', 'gravityforms-stripe' ),
			'stripe_options'              => '<h6>' . __( 'Options', 'gravityforms-stripe' ) . '</h6>' . __( 'Turn on or off the available Stripe checkout options.', 'gravityforms-stripe' ),
			'stripe_subscription_plan'     => '<h6>' . __( 'Subscription Plan', 'gravityforms-stripe' ) . '</h6>' . __( 'Select which field determines the subscription plan.', 'gravityforms-stripe' ),
			'stripe_billing_cycle'        => '<h6>' . __( 'Billing Cycle', 'gravityforms-stripe' ) . '</h6>' . __( 'Select your billing cycle.  This determines how often the recurring payment should occur.', 'gravityforms-stripe' ),
			'stripe_recurring_times'      => '<h6>' . __( 'Recurring Times', 'gravityforms-stripe' ) . '</h6>' . __( 'Select how many times the recurring payment should be made.  The default is to bill the customer until the subscription is canceled.', 'gravityforms-stripe' ),
			'stripe_trial_period_enable'  => '<h6>' . __( 'Trial Period', 'gravityforms-stripe' ) . '</h6>' . __( 'Enable a trial period.  The users recurring payment will not begin until after this trial period.', 'gravityforms-stripe' ),
			'stripe_trial_amount'         => '<h6>' . __( 'Trial Amount', 'gravityforms-stripe' ) . '</h6>' . __( 'Enter the trial period amount or leave it blank for a free trial.', 'gravityforms-stripe' ),
			'stripe_trial_period'         => '<h6>' . __( 'Trial Recurring Times', 'gravityforms-stripe' ) . '</h6>' . __( 'Select the number of billing occurrences or payments in the trial period.', 'gravityforms-stripe' ),

			'stripe_api'                  => '<h6>' . __( 'API', 'gravityforms-stripe' ) . '</h6>' . __( 'Select the Stripe API you would like to use. Select \'Live\' to use your Live API keys. Select \'Test\' to use your Test API keys.', 'gravityforms-stripe' ),
			'stripe_test_secret_key'      => '<h6>' . __( 'API Test Secret Key', 'gravityforms-stripe' ) . '</h6>' . __( 'Enter the API Test Secret Key for your Stripe account.', 'gravityforms-stripe' ),
			'stripe_test_publishable_key' => '<h6>' . __( 'API Test Publishable Key', 'gravityforms-stripe' ) . '</h6>' . __( 'Enter the API Test Publishable Key for your Stripe account.', 'gravityforms-stripe' ),
			'stripe_live_secret_key'      => '<h6>' . __( 'API Live Secret Key', 'gravityforms-stripe' ) . '</h6>' . __( 'Enter the API Live Secret Key for your Stripe account.', 'gravityforms-stripe' ),
			'stripe_live_publishable_key' => '<h6>' . __( 'API Live Publishable Key', 'gravityforms-stripe' ) . '</h6>' . __( 'Enter the API Live Publishable Key for your Stripe account.', 'gravityforms-stripe' ),
			'stripe_conditional'          => '<h6>' . __( 'Stripe Condition', 'gravityforms-stripe' ) . '</h6>' . __( 'When the Stripe condition is enabled, form submissions will only be sent to Stripe when the condition is met. When disabled all form submissions will be sent to Stripe.', 'gravityforms-stripe' )

		);
		return array_merge( $tooltips, $stripe_tooltips );
	}

	public static function stripe_page() {
		$view = rgget( 'view' );
		if ( 'edit' == $view )
			self::edit_page( rgget( 'id' ) );
		else if ( 'stats' == $view )
			self::stats_page( rgget( 'id' ) );
		else
			self::list_page();
	}

//------------------------------------------------------
//------------- STRIPE FEED LISTS PAGE -----------------
//------------------------------------------------------
	private static function list_page() {
		if ( ! self::is_gravityforms_supported() ) {
			die( __( sprintf( 'Stripe Add-On requires Gravity Forms %s. Upgrade automatically on the %sPlugin page%s.', self::$min_gravityforms_version, '<a href="plugins.php">', '</a>' ), 'gravityforms-stripe' ) );
		}

		if ( 'delete' == rgpost( 'action' ) ) {
			check_admin_referer( 'list_action', 'gf_stripe_list' );

			$id = absint( $_POST[ "action_argument" ] );
			GFStripeData::delete_feed( $id );
			?>
		<div class="updated fade" style="padding:6px"><?php _e( 'Feed deleted.', 'gravityforms-stripe' ) ?></div>
		<?php
		}
		else if ( ! empty( $_POST[ "bulk_action" ] ) ) {
			check_admin_referer( 'list_action', 'gf_stripe_list' );
			$selected_feeds = $_POST[ "feed" ];
			if ( is_array( $selected_feeds ) ) {
				foreach ( $selected_feeds as $feed_id )
					GFStripeData::delete_feed( $feed_id );
			}
			?>
		<div class="updated fade" style="padding:6px"><?php _e( 'Feeds deleted.', 'gravityforms-stripe' ) ?></div>
		<?php
		}

		?>
	<div class="wrap">
		<img alt="<?php _e( 'Stripe Transactions', 'gravityforms-stripe' ) ?>"
				 src="<?php echo self::get_base_url()?>/images/stripe_wordpress_icon_32.png"
				 style="float:left; margin:15px 7px 0 0;"/>

		<h2><?php
			_e( 'Stripe Forms', 'gravityforms-stripe' );
			?>
			<a class="button add-new-h2"
				 href="admin.php?page=gf_stripe&view=edit&id=0"><?php _e( 'Add New', 'gravityforms-stripe' ) ?></a>

		</h2>

		<form id="feed_form" method="post">
			<?php wp_nonce_field( 'list_action', 'gf_stripe_list' ) ?>
			<input type="hidden" id="action" name="action"/>
			<input type="hidden" id="action_argument" name="action_argument"/>

			<div class="tablenav">
				<div class="alignleft actions" style="padding:8px 0 7px 0;">
					<label class="hidden" for="bulk_action"><?php _e( 'Bulk action', 'gravityforms-stripe' ) ?></label>
					<select name="bulk_action" id="bulk_action">
						<option value=''> <?php _e( 'Bulk action', 'gravityforms-stripe' ) ?> </option>
						<option value='delete'><?php _e( 'Delete', 'gravityforms-stripe' ) ?></option>
					</select>
					<?php
					echo '<input type="submit" class="button" value="' . __( 'Apply', 'gravityforms-stripe' ) . '" onclick="if( jQuery(\'#bulk_action\').val() == \'delete\' && !confirm(\'' . __( 'Delete selected feeds? ', 'gravityforms-stripe' ) . __( '\'Cancel\' to stop, \'OK\' to delete.', 'gravityforms-stripe' ) . '\')) { return false; } return true;"/>';
					?>
				</div>
			</div>
			<table class="widefat fixed" cellspacing="0">
				<thead>
				<tr>
					<th scope="col" id="cb" class="manage-column column-cb check-column" style=""><input type="checkbox"/></th>
					<th scope="col" id="active" class="manage-column check-column"></th>
					<th scope="col" class="manage-column"><?php _e( 'Form', 'gravityforms-stripe' ) ?></th>
					<th scope="col" class="manage-column"><?php _e( 'Transaction Type', 'gravityforms-stripe' ) ?></th>
				</tr>
				</thead>

				<tfoot>
				<tr>
					<th scope="col" id="cb" class="manage-column column-cb check-column" style=""><input type="checkbox"/></th>
					<th scope="col" id="active" class="manage-column check-column"></th>
					<th scope="col" class="manage-column"><?php _e( 'Form', 'gravityforms-stripe' ) ?></th>
					<th scope="col" class="manage-column"><?php _e( 'Transaction Type', 'gravityforms-stripe' ) ?></th>
				</tr>
				</tfoot>

				<tbody class="list:user user-list">
					<?php


					$settings = GFStripeData::get_feeds();
					$is_valid = self::is_valid_key();
					if ( ! $is_valid[ 0 ] ) {
						?>
					<tr>
						<td colspan="4" style="padding:20px;">
							<?php echo sprintf( __( "To get started, please configure your %sStripe Settings%s.", 'gravityforms-stripe' ), '<a href="admin.php?page=gf_settings&addon=Stripe">', '</a>' ); ?>
						</td>
					</tr>
						<?php
					}
					else if ( is_array( $settings ) && sizeof( $settings ) > 0 ) {
						foreach ( $settings as $setting ) {
							?>
						<tr class='author-self status-inherit' valign="top">
							<th scope="row" class="check-column"><input type="checkbox" name="feed[]"
																													value="<?php echo $setting[ "id" ] ?>"/></th>
							<td><img
									src="<?php echo self::get_base_url() ?>/images/active<?php echo intval( $setting[ "is_active" ] ) ?>.png"
									alt="<?php echo $setting[ "is_active" ] ? __( 'Active', 'gravityforms-stripe' ) : __( 'Inactive', 'gravityforms-stripe' );?>"
									title="<?php echo $setting[ "is_active" ] ? __( 'Active', 'gravityforms-stripe' ) : __( 'Inactive', 'gravityforms-stripe' );?>"
									onclick="ToggleActive(this, <?php echo $setting[ 'id' ] ?>); "/></td>
							<td class="column-title">
								<a href="admin.php?page=gf_stripe&view=edit&id=<?php echo $setting[ "id" ] ?>"
									 title="<?php _e( 'Edit', 'gravityforms-stripe' ) ?>"><?php echo $setting[ "form_title" ] ?></a>

								<div class="row-actions">
                                            <span class="edit">
                                            <a title="<?php _e( 'Edit', 'gravityforms-stripe' )?>"
																							 href="admin.php?page=gf_stripe&view=edit&id=<?php echo $setting[ "id" ] ?>"
																							 title="<?php _e( 'Edit', 'gravityforms-stripe' ) ?>"><?php _e( 'Edit', 'gravityforms-stripe' ) ?></a>
                                            |
                                            </span>
                                            <span>
                                            <a title="<?php _e( 'View Stats', 'gravityforms-stripe' )?>"
																							 href="admin.php?page=gf_stripe&view=stats&id=<?php echo $setting[ "id" ] ?>"
																							 title="<?php _e( 'View Stats', 'gravityforms-stripe' ) ?>"><?php _e( 'Stats', 'gravityforms-stripe' ) ?></a>
                                            |
                                            </span>
                                            <span>
                                            <a title="<?php _e( 'View Entries', 'gravityforms-stripe' )?>"
																							 href="admin.php?page=gf_entries&view=entries&id=<?php echo $setting[ "form_id" ] ?>"
																							 title="<?php _e( 'View Entries', 'gravityforms-stripe' ) ?>"><?php _e( 'Entries', 'gravityforms-stripe' ) ?></a>
                                            |
                                            </span>
                                            <span>
                                            <a title="<?php _e( "Delete", "gravityforms-stripe" ) ?>"
																							 href="javascript: if(confirm('<?php _e( 'Delete this feed? ', 'gravityforms-stripe' ) ?> <?php _e( "\'Cancel\' to stop, \'OK\' to delete.", 'gravityforms-stripe' ) ?>')){ DeleteSetting(<?php echo $setting[ "id" ] ?>);}"><?php _e( 'Delete', 'gravityforms-stripe' )?></a>
                                            </span>
								</div>
							</td>
							<td class="column-date">
								<?php
								switch ( $setting[ "meta" ][ "type" ] ) {
									case 'product' :
										_e( 'Product and Services', 'gravityforms-stripe' );
										break;

									case 'subscription' :
										_e( 'Subscription', 'gravityforms-stripe' );
										break;
								}
								?>
							</td>
						</tr>
							<?php
						}
					}
					else {
						?>
					<tr>
						<td colspan="4" style="padding:20px;">
							<?php echo sprintf( __( "You don't have any Stripe feeds configured. Let's go %screate one%s!", 'gravityforms-stripe' ), '<a href="admin.php?page=gf_stripe&view=edit&id=0">', '</a>' ); ?>
						</td>
					</tr>
						<?php
					}
					?>
				</tbody>
			</table>
		</form>
	</div>
	<script type="text/javascript">
		function DeleteSetting(id) {
			jQuery("#action_argument").val(id);
			jQuery("#action").val("delete");
			jQuery("#feed_form")[0].submit();
		}
		function ToggleActive(img, feed_id) {
			var is_active = img.src.indexOf("active1.png") >= 0
			if (is_active) {
				img.src = img.src.replace("active1.png", "active0.png");
				jQuery(img).attr('title', '<?php _e( 'Inactive', 'gravityforms-stripe' ) ?>').attr('alt', '<?php _e( 'Inactive', 'gravityforms-stripe' ) ?>');
			}
			else {
				img.src = img.src.replace("active0.png", "active1.png");
				jQuery(img).attr('title', '<?php _e( 'Active', 'gravityforms-stripe' ) ?>').attr('alt', '<?php _e( 'Active', 'gravityforms-stripe' ) ?>');
			}

			var mysack = new sack("<?php echo admin_url( "admin-ajax.php" )?>");
			mysack.execute = 1;
			mysack.method = 'POST';
			mysack.setVar("action", "gf_stripe_update_feed_active");
			mysack.setVar("gf_stripe_update_feed_active", "<?php echo wp_create_nonce( 'gf_stripe_update_feed_active' ) ?>");
			mysack.setVar("feed_id", feed_id);
			mysack.setVar("is_active", is_active ? 0 : 1);
			mysack.encVar("cookie", document.cookie, false);
			mysack.onError = function () {
				alert('<?php _e( 'Ajax error while updating feed', 'gravityforms-stripe' ) ?>')
			};
			mysack.runAJAX();

			return true;
		}


	</script>
	<?php
	}

//------------------------------------------------------
//------------- SETTINGS PAGE --------------------------
//------------------------------------------------------
	public static function settings_page() {

		if ( isset( $_POST[ "uninstall" ] ) ) {
			check_admin_referer( 'uninstall', 'gf_stripe_uninstall' );
			self::uninstall();

			?>
		<div class="updated fade"
				 style="padding:20px;"><?php _e( sprintf( "Gravity Forms Stripe Add-On has been successfully uninstalled. It can be re-activated from the %splugins page%s.", "<a href='plugins.php'>", "</a>" ), 'gravityforms-stripe' )?></div>
		<?php
			return;
		}
		else if ( isset( $_POST[ "gf_stripe_submit" ] ) ) {
			check_admin_referer( 'update', 'gf_stripe_update' );
			$settings = array(
				'test_secret_key'      			=> rgpost( 'gf_stripe_test_secret_key' ),
				'test_publishable_key' 			=> rgpost( 'gf_stripe_test_publishable_key' ),
				'live_secret_key'      			=> rgpost( 'gf_stripe_live_secret_key' ),
				'live_publishable_key' 			=> rgpost( 'gf_stripe_live_publishable_key' ),
				'mode'                 			=> rgpost( 'gf_stripe_mode' ),
				'stripe_webhook_configured'	=> rgpost( 'gf_stripe_webhook_configured' ),
				'log_level'            			=> rgpost( 'gf_stripe_log_level' )
			);


			update_option( 'gf_stripe_settings', $settings );
		}
		else {
			$settings = get_option( 'gf_stripe_settings' );
		}

		$is_valid = self::is_valid_key();

		$message = array();
		if ( $is_valid[ 0 ] )
			$message[ 0 ] = 'Valid API key.';
		else {
			foreach ( $is_valid[ 1 ] as $key => $value ) {
				if ( ! empty( $settings[ $key ] ) ) {
					if ( ! $value ) {
						$message[ 1 ][ $key ] = 'Invalid API key. Please try again.';
					}
					else {
						$message[ 1 ][ $key ] = 'Valid API key.';
					}
				}
			}
		}


		?>
	<style>
		.valid_credentials {
			color: green;
		}

		.invalid_credentials {
			color: red;
		}

		.size-1 {
			width: 400px;
		}
	</style>

	<form method="post" action="">
		<?php wp_nonce_field( 'update', 'gf_stripe_update' ) ?>

		<h3><?php _e( 'Stripe Account Information', 'gravityforms-stripe' ) ?></h3>

		<p style="text-align: left;">
			<?php _e( sprintf( "Stripe is a payment gateway for merchants. Use Gravity Forms to collect payment information and automatically integrate to your client's Stripe account. If you don't have a Stripe account, you can %ssign up for one here%s", "<a href='http://www.stripe.com' target='_blank'>", "</a>" ), 'gravityforms-stripe' ) ?>
		</p>

		<table class="form-table">
			<tr>
				<th scope="row" nowrap="nowrap"><label
						for="gf_stripe_mode"><?php _e( 'API Mode', 'gravityforms-stripe' ); ?> <?php gform_tooltip( 'stripe_api' ) ?></label>
				</th>
				<td width="88%">
					<input type="radio" name="gf_stripe_mode" id="gf_stripe_mode_live"
								 value="live" <?php echo rgar( $settings, 'mode' ) != 'test' ? "checked='checked'" : '' ?>/>
					<label class="inline" for="gf_stripe_mode_live"><?php _e( 'Live', 'gravityforms-stripe' ); ?></label>
					&nbsp;&nbsp;&nbsp;
					<input type="radio" name="gf_stripe_mode" id="gf_stripe_mode_test"
								 value="test" <?php echo 'test' == rgar( $settings, 'mode' ) ? "checked='checked'" : '' ?>/>
					<label class="inline" for="gf_stripe_mode_test"><?php _e( 'Test', 'gravityforms-stripe' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row" nowrap="nowrap"><label
						for="gf_stripe_test_secret_key"><?php _e( 'Test Secret Key', 'gravityforms-stripe' ); ?> <?php gform_tooltip( 'stripe_test_secret_key' ) ?></label>
				</th>
				<td width="88%">
					<input class="size-1" id="gf_stripe_test_secret_key" name="gf_stripe_test_secret_key"
								 value="<?php echo esc_attr( rgar( $settings, 'test_secret_key' ) ) ?>"/>
					<img
							src="<?php echo self::get_base_url() ?>/images/<?php echo $is_valid[ 1 ][ 'test_secret_key' ] ? 'tick.png' : 'stop.png' ?>"
							border="0"
							alt="<?php array_key_exists( 0, $message ) ? $message[ 0 ] : $message[ 1 ][ 'test_secret_key' ]	?>"
							title="<?php echo array_key_exists( 0, $message ) ? $message[ 0 ] : $message[ 1 ][ 'test_secret_key' ] ?>"
							style="display:<?php echo ( empty( $message[ 0 ] ) && empty( $message[ 1 ][ 'test_secret_key' ] ) ) ? 'none;' : 'inline;' ?>"/>
					<br/>
					<small><?php _e( "You can find your <strong>Test Secret Key</strong> by clicking on 'Your Account' in the top right corner of the Stripe Account Dashboard. Choose 'Account Settings' then 'API Keys'. Your API keys will be displayed.", 'gravityforms-stripe' ) ?></small>
				</td>
			</tr>
			<tr>
				<th scope="row" nowrap="nowrap"><label
						for="gf_stripe_test_publishable_key"><?php _e( 'Test Publishable Key', 'gravityforms-stripe' ); ?> <?php gform_tooltip( 'stripe_test_publishable_key' ) ?></label>
				</th>
				<td width="88%">
					<input class="size-1" id="gf_stripe_test_publishable_key" name="gf_stripe_test_publishable_key"
								 value="<?php echo esc_attr( rgar( $settings, 'test_publishable_key' ) ) ?>"/>
					<img
							src="<?php echo self::get_base_url() ?>/images/<?php echo $is_valid[ 1 ][ 'test_publishable_key' ] ? 'tick.png' : 'stop.png' ?>"
							border="0"
							alt="<?php array_key_exists( 0, $message ) ? $message[ 0 ] : $message[ 1 ][ 'test_publishable_key' ] ?>"
							title="<?php echo array_key_exists( 0, $message ) ? $message[ 0 ] : $message[ 1 ][ 'test_publishable_key' ] ?>"
							style="display:<?php echo ( empty( $message[ 0 ] ) && empty( $message[ 1 ][ 'test_publishable_key' ] ) ) ? 'none;' : 'inline;' ?>"/>
					<br/>
					<small><?php _e( "You can find your <strong>Test Publishable Key</strong> by clicking on 'Your Account' in the top right corner of the Stripe Account Dashboard. Choose 'Account Settings' then 'API Keys'. Your API keys will be displayed.", "gravityforms-stripe" ) ?></small>
				</td>
			</tr>
			<tr>
				<th scope="row" nowrap="nowrap"><label
						for="gf_stripe_live_secret_key"><?php _e( 'Live Secret Key', 'gravityforms-stripe' ); ?> <?php gform_tooltip( 'stripe_live_secret_key' ) ?></label>
				</th>
				<td width="88%">
					<input class="size-1" id="gf_stripe_live_secret_key" name="gf_stripe_live_secret_key"
								 value="<?php echo esc_attr( rgar( $settings, 'live_secret_key' ) ) ?>"/>
					<img
							src="<?php echo self::get_base_url() ?>/images/<?php echo $is_valid[ 1 ][ 'live_secret_key' ] ? 'tick.png' : 'stop.png' ?>"
							border="0"
							alt="<?php array_key_exists( 0, $message ) ? $message[ 0 ] : $message[ 1 ][ 'live_secret_key' ] ?>"
							title="<?php echo array_key_exists( 0, $message ) ? $message[ 0 ] : $message[ 1 ][ 'live_secret_key' ] ?>"
							style="display:<?php echo ( empty( $message[ 0 ] ) && empty( $message[ 1 ][ 'live_secret_key' ] ) ) ? 'none;' : 'inline;' ?>"/>
					<br/>
					<small><?php _e( "You can find your <strong>Live Secret Key</strong> by clicking on 'Your Account' in the top right corner of the Stripe Account Dashboard. Choose 'Account Settings' then 'API Keys'. Your API keys will be displayed.", "gravityforms-stripe" ) ?></small>
				</td>
			</tr>
			<tr>
				<th scope="row" nowrap="nowrap"><label
						for="gf_stripe_live_publishable_key"><?php _e( 'Live Publishable Key', 'gravityforms-stripe' ); ?> <?php gform_tooltip( 'stripe_live_publishable_key' ) ?></label>
				</th>
				<td width="88%">
					<input class="size-1" id="gf_stripe_live_publishable_key" name="gf_stripe_live_publishable_key"
								 value="<?php echo esc_attr( rgar( $settings, 'live_publishable_key' ) ) ?>"/>
					<img
							src="<?php echo self::get_base_url() ?>/images/<?php echo $is_valid[ 1 ][ 'live_publishable_key' ] ? 'tick.png' : 'stop.png' ?>"
							border="0"
							alt="<?php array_key_exists( 0, $message ) ? $message[ 0 ] : $message[ 1 ][ 'live_publishable_key' ] ?>"
							title="<?php echo array_key_exists( 0, $message ) ? $message[ 0 ] : $message[ 1 ][ 'live_publishable_key' ] ?>"
							style="display:<?php echo ( empty( $message[ 0 ] ) && empty( $message[ 1 ][ 'live_publishable_key' ] ) ) ? 'none;' : 'inline;' ?>"/>
					<br/>
					<small><?php _e( "You can find your <strong>Live Publishable Key</strong> by clicking on 'Your Account' in the top right corner of the Stripe Account Dashboard. Choose 'Account Settings' then 'API Keys'. Your API keys will be displayed.", "gravityforms-stripe" ) ?></small>
				</td>
			</tr>

			<tr>
			 <td colspan="2">
			   <h3><?php _e( 'Subscription/Recurring Billing Setup', 'gravityforms-stripe' ) ?></h3>
			   <p style="text-align: left;">
			     <?php _e( 'To receive information about a customer subscription from Stripe, you must have webhooks for this site\'s URL in your Stripe dashboard \'Account Settings\'. Follow the steps below to confirm.', 'gravityforms-stripe' ) ?>
			   </p>
				 <ol>
				  <li><?php echo sprintf( __( 'Navigate to your Stripe dashboard\'s %sAccount Settings->Webhooks page%s.', 'gravityforms-stripe' ), "<a href='https://manage.stripe.com/#account/webhooks' target='_blank'>" , "</a>") ?></li>
				  <li><?php _e( 'If the webhooks are already setup, you will see your site\'s webhook URL listed twice - once for Test mode and once for Live mode. If that is the case, just check the confirmation box below and you are ready to go!', 'gravityforms-stripe' ) ?></li>
				  <li><?php echo sprintf( __( 'If you don\'t see your site\'s webhook URLs listed, click the \'Add URL\' button and enter the following URL, once with the mode underneath the URL box set as \'Test\' and once with the mode underneath the URL box set as \'Live\': %s', 'gravityforms-stripe' ), '<strong>' . add_query_arg( 'page', 'gf_stripe_listener', get_bloginfo( 'url' ) . '/') . '</strong>') ?></li>
				 </ol>
				<br/>
			 </td>
			</tr>
			<tr>
			 <td colspan="2">
			  <input type="checkbox" name="gf_stripe_webhook_configured" id="gf_stripe_webhook_configured" <?php echo $settings[ 'stripe_webhook_configured' ] ? "checked='checked'" : ""?>/>
			  <label for="gf_stripe_webhook_configured" class="inline"><?php _e( 'Both Stripe webhooks are setup in my Stripe dashboard.', 'gravityforms-stripe' ) ?></label>
			 </td>
			</tr>


			<tr style="display:<?php echo rgempty( 'debug', $_GET ) ? 'none' : 'block' ?>;">
				<td colspan="2">
					<h3><?php _e( 'Debugging Settings', 'gravityforms-stripe' ) ?></h3>
				</td>
			</tr>
			<tr style="display:<?php echo rgempty( 'debug', $_GET ) ? 'none' : 'block' ?>;">
				<th scope="row" nowrap="nowrap"><label
						for="gf_stripe_log_level"><?php _e( 'Logging', 'gravityforms-stripe' ); ?></label></th>
				<td width="88%">
					<select id="gf_stripe_log_level" name="gf_stripe_log_level">
						<option
								value="<?php echo KLogger::OFF ?>" <?php echo rgempty( 'log_level', $settings ) ? "selected='selected'" : "" ?>><?php _e( 'Off', 'gravityforms-stripe' ) ?></option>
						<option
								value="<?php echo KLogger::DEBUG ?>" <?php echo rgar( $settings, 'log_level' ) == KLogger::DEBUG ? "selected='selected'" : "" ?>><?php _e( 'Log all messages', 'gravityforms-stripe' ) ?></option>
						<option
								value="<?php echo KLogger::ERROR ?>" <?php echo rgar( $settings, 'log_level' ) == KLogger::ERROR ? "selected='selected'" : "" ?>><?php _e( 'Log errors only', 'gravityforms-stripe' ) ?></option>
					</select>
					<?php
					if ( file_exists( GRAVITYFORMS_STRIPE_PATH . '/log.txt' ) ) {
						?>
						&nbsp;<a href="<?php echo self::get_base_url() . '/log.txt' ?>"
										 target="_blank"><?php _e( 'view log', 'gravityforms-stripe' ) ?></a>
						<?php
					}
					?>
				</td>
			</tr>
			<tr>
				<td colspan="2"><input type="submit" name="gf_stripe_submit" class="button-primary"
															 value="<?php _e( 'Save Settings', 'gravityforms-stripe' ) ?>"/></td>
			</tr>

		</table>

	</form>

	<form action="" method="post">
		<?php wp_nonce_field( 'uninstall', 'gf_stripe_uninstall' ) ?>
		<?php if ( GFCommon::current_user_can_any( 'gravityforms_stripe_uninstall' ) ) { ?>
		<div class="hr-divider"></div>

		<h3><?php _e( 'Uninstall Stripe Add-On', 'gravityforms-stripe' ) ?></h3>
		<div class="delete-alert"><?php _e( 'Warning! This operation deletes ALL Stripe Feeds.', 'gravityforms-stripe' ) ?>
			<?php
			$uninstall_button = '<input type="submit" name="uninstall" value="' . __( 'Uninstall Stripe Add-On', 'gravityforms-stripe' ) . '" class="button" onclick="return confirm(\'' . __( "Warning! ALL Stripe Feeds will be deleted. This cannot be undone. \'OK\' to delete, \'Cancel\' to stop", 'gravityforms-stripe' ) . '\');"/>';
			echo apply_filters( 'gform_stripe_uninstall_button', $uninstall_button );
			?>
		</div>
		<?php } ?>
	</form>

	<?php
	}

	private static function is_valid_key() {
		self::include_api();
		$settings  = get_option( 'gf_stripe_settings' );

		$year = date( 'Y' ) + 1;

		$valid_keys = array(
			'test_secret_key'      => false,
			'test_publishable_key' => false,
			'live_secret_key'      => false,
			'live_publishable_key' => false
		);
		$valid      = false;
		$flag_false = 0;
		foreach ( $valid_keys as $key => $value ) {
			if ( ! empty( $settings[ $key ] ) ) {
				try {
					Stripe::setApiKey( $settings[ $key ] );
					Stripe_Token::create( array(
																		 'card'     => array(
																			 'number'    => '4242424242424242',
																			 'exp_month' => 3,
																			 'exp_year'  => $year,
																			 'cvc'       => 314
																		 ),
																		 'currency' => 'usd' ) );
					$valid_keys[ $key ] = true;
				}
				catch ( Exception $e ) {
					$class = get_class( $e );
					if ( 'Stripe_CardError' == $class ) {
						$valid_keys[ $key ] = true;
					}
					else {
						$flag_false ++;
					}
				}
			}
			else {
				$flag_false ++;
			}
		}

		if ( 0 == $flag_false ) {
			$valid = true;
		}


		return array( $valid, $valid_keys );


	}

	private static function get_api_key( $type ) {
		$settings = get_option( 'gf_stripe_settings' );
		$mode     = rgar( $settings, 'mode' );
		$key = $mode . '_' . $type . '_key';

		return esc_attr( rgar( $settings, $key ) );

	}


	private static function include_api() {
		if ( ! class_exists( 'Stripe' ) )
			require_once( GRAVITYFORMS_STRIPE_PATH . '/api/lib/Stripe.php' );
	}


//------------------------------------------------------
//------------- STATS PAGE ---------------------------
//------------------------------------------------------
	private static function stats_page() {
		?>
	<style>
		.stripe_graph_container {
			clear: both;
			padding-left: 5px;
			min-width: 789px;
			margin-right: 50px;
		}

		.stripe_message_container {
			clear: both;
			padding-left: 5px;
			text-align: center;
			padding-top: 120px;
			border: 1px solid #CCC;
			background-color: #FFF;
			width: 100%;
			height: 160px;
		}

		.stripe_summary_container {
			margin: 30px 60px;
			text-align: center;
			min-width: 740px;
			margin-left: 50px;
		}

		.stripe_summary_item {
			width: 160px;
			background-color: #FFF;
			border: 1px solid #CCC;
			padding: 14px 8px;
			margin: 6px 3px 6px 0;
			display: -moz-inline-stack;
			display: inline-block;
			zoom: 1;
			*display: inline;
			text-align: center;
		}

		.stripe_summary_value {
			font-size: 20px;
			margin: 5px 0;
			font-family: Georgia, "Times New Roman", "Bitstream Charter", Times, serif
		}

		.stripe_summary_title {
		}

		#stripe_graph_tooltip {
			border: 4px solid #b9b9b9;
			padding: 11px 0 0 0;
			background-color: #f4f4f4;
			text-align: center;
			-moz-border-radius: 4px;
			-webkit-border-radius: 4px;
			border-radius: 4px;
			-khtml-border-radius: 4px;
		}

		#stripe_graph_tooltip .tooltip_tip {
			width: 14px;
			height: 14px;
			background-image: url(<?php echo self::get_base_url() ?>/images/tooltip_tip.png);
			background-repeat: no-repeat;
			position: absolute;
			bottom: -14px;
			left: 68px;
		}

		.stripe_tooltip_date {
			line-height: 130%;
			font-weight: bold;
			font-size: 13px;
			color: #21759B;
		}

		.stripe_tooltip_sales {
			line-height: 130%;
		}

		.stripe_tooltip_revenue {
			line-height: 130%;
		}

		.stripe_tooltip_revenue .stripe_tooltip_heading {
		}

		.stripe_tooltip_revenue .stripe_tooltip_value {
		}

		.stripe_trial_disclaimer {
			clear: both;
			padding-top: 20px;
			font-size: 10px;
		}
	</style>
	<script type="text/javascript" src="<?php echo self::get_base_url() ?>/flot/jquery.flot.min.js"></script>
	<script type="text/javascript" src="<?php echo self::get_base_url() ?>/js/currency.js"></script>

	<div class="wrap">
		<img alt="<?php _e( 'Stripe', 'gravityforms-stripe' ) ?>" style="margin: 15px 7px 0pt 0pt; float: left;"
				 src="<?php echo self::get_base_url() ?>/images/stripe_wordpress_icon_32.png"/>

		<h2><?php _e( 'Stripe Stats', 'gravityforms-stripe' ) ?></h2>

		<form method="post" action="">
			<ul class="subsubsub">
				<li><a class="<?php echo ( ! RGForms::get( 'tab' ) || 'daily' == RGForms::get( 'tab' ) ) ? 'current' : '' ?>"
							 href="?page=gf_stripe&view=stats&id=<?php echo $_GET[ "id" ] ?>"><?php _e( 'Daily', 'gravityforms' ); ?></a>
					|
				</li>
				<li><a class="<?php echo 'weekly' == RGForms::get( 'tab' ) ? 'current' : ''?>"
							 href="?page=gf_stripe&view=stats&id=<?php echo $_GET[ "id" ] ?>&tab=weekly"><?php _e( 'Weekly', 'gravityforms' ); ?></a>
					|
				</li>
				<li><a class="<?php echo 'monthly' == RGForms::get( 'tab' ) ? 'current' : ''?>"
							 href="?page=gf_stripe&view=stats&id=<?php echo $_GET[ "id" ] ?>&tab=monthly"><?php _e( 'Monthly', 'gravityforms' ); ?></a>
				</li>
			</ul>
			<?php
			$config = GFStripeData::get_feed( RGForms::get( 'id' ) );

			switch ( RGForms::get( 'tab' ) ) {
				case 'monthly' :
					$chart_info = self::monthly_chart_info( $config );
					break;

				case 'weekly' :
					$chart_info = self::weekly_chart_info( $config );
					break;

				default :
					$chart_info = self::daily_chart_info( $config );
					break;
			}

			if ( ! $chart_info[ "series" ] ) {
				?>
				<div
						class="stripe_message_container"><?php _e( 'No payments have been made yet.', 'gravityforms-stripe' ) ?> <?php echo $config[ "meta" ][ "trial_period_enabled" ] && empty( $config[ "meta" ][ "trial_amount" ] ) ? " **" : ""?></div>
				<?php
			}
			else {
				?>
				<div class="stripe_graph_container">
					<div id="graph_placeholder" style="width:100%;height:300px;"></div>
				</div>

				<script type="text/javascript">
					var stripe_graph_tooltips = <?php echo $chart_info[ "tooltips" ]?>;
					jQuery.plot(jQuery("#graph_placeholder"), <?php echo $chart_info[ "series" ] ?>, <?php echo $chart_info[ "options" ] ?>);
					jQuery(window).resize(function () {
						jQuery.plot(jQuery("#graph_placeholder"), <?php echo $chart_info[ "series" ] ?>, <?php echo $chart_info[ "options" ] ?>);
					});

					var previousPoint = null;
					jQuery("#graph_placeholder").bind("plothover", function (event, pos, item) {
						startShowTooltip(item);
					});

					jQuery("#graph_placeholder").bind("plotclick", function (event, pos, item) {
						startShowTooltip(item);
					});

					function startShowTooltip(item) {
						if (item) {
							if (!previousPoint || previousPoint[0] != item.datapoint[0]) {
								previousPoint = item.datapoint;

								jQuery("#stripe_graph_tooltip").remove();
								var x = item.datapoint[0].toFixed(2),
										y = item.datapoint[1].toFixed(2);

								showTooltip(item.pageX, item.pageY, stripe_graph_tooltips[item.dataIndex]);
							}
						}
						else {
							jQuery("#stripe_graph_tooltip").remove();
							previousPoint = null;
						}
					}
					function showTooltip(x, y, contents) {
						jQuery('<div id="stripe_graph_tooltip">' + contents + '<div class="tooltip_tip"></div></div>').css({
							position:'absolute',
							display:'none',
							opacity:0.90,
							width:'150px',
							height:'<?php echo "subscription" == $config[ "meta" ][ "type" ] ? "75px" : "60px";?>',
							top:y - <?php echo "subscription" == $config[ "meta" ][ "type" ] ? "100" : "89";?>,
							left:x - 79
						}).appendTo("body").fadeIn(200);
					}
					function convertToMoney(number) {
						var currency = getCurrentCurrency();
						return currency.toMoney(number);
					}
					function formatWeeks(number) {
						number = number + "";
						return "<?php _e( "Week ", "gravityforms-stripe" ) ?>" + number.substring(number.length - 2);
					}
					function getCurrentCurrency() {
						<?php
						if ( ! class_exists( 'RGCurrency' ) )
							require_once( ABSPATH . '/' . PLUGINDIR . '/gravityforms/currency.php' );

						$current_currency = RGCurrency::get_currency( GFCommon::get_currency() );
						?>
						var currency = new Currency(<?php echo GFCommon::json_encode( $current_currency )?>);
						return currency;
					}
				</script>
				<?php
			}
			$payment_totals     = RGFormsModel::get_form_payment_totals( $config[ "form_id" ] );
			$transaction_totals = GFStripeData::get_transaction_totals( $config[ "form_id" ] );

			switch ( $config[ "meta" ][ "type" ] ) {
				case 'product' :
					$total_sales = $payment_totals[ "orders" ];
					$sales_label = __( 'Total Orders', 'gravityforms-stripe' );
					break;

				case 'donation' :
					$total_sales = $payment_totals[ "orders" ];
					$sales_label = __( 'Total Donations', 'gravityforms-stripe' );
					break;

				case 'subscription' :
					$total_sales = $payment_totals[ "active" ];
					$sales_label = __( 'Active Subscriptions', 'gravityforms-stripe' );
					break;
			}

			$total_revenue = empty( $transaction_totals[ "payment" ][ "revenue" ] ) ? 0 : $transaction_totals[ "payment" ][ "revenue" ];
			?>
			<div class="stripe_summary_container">
				<div class="stripe_summary_item">
					<div class="stripe_summary_title"><?php _e( 'Total Revenue', 'gravityforms-stripe' )?></div>
					<div class="stripe_summary_value"><?php echo GFCommon::to_money( $total_revenue ) ?></div>
				</div>
				<div class="stripe_summary_item">
					<div class="stripe_summary_title"><?php echo $chart_info[ "revenue_label" ]?></div>
					<div class="stripe_summary_value"><?php echo $chart_info[ "revenue" ] ?></div>
				</div>
				<div class="stripe_summary_item">
					<div class="stripe_summary_title"><?php echo $sales_label?></div>
					<div class="stripe_summary_value"><?php echo $total_sales ?></div>
				</div>
				<div class="stripe_summary_item">
					<div class="stripe_summary_title"><?php echo $chart_info[ "sales_label" ] ?></div>
					<div class="stripe_summary_value"><?php echo $chart_info[ "sales" ] ?></div>
				</div>
			</div>
			<?php
			if ( ! $chart_info[ "series" ] && $config[ "meta" ][ "trial_period_enabled" ] && empty( $config[ "meta" ][ "trial_amount" ] ) ) {
				?>
				<div
						class="stripe_trial_disclaimer"><?php _e( '** Free trial transactions will only be reflected in the graph after the first payment is made (i.e. after trial period ends)', 'gravityforms-stripe' ) ?></div>
				<?php
			}
			?>
		</form>
	</div>
	<?php
	}

	private static function get_graph_timestamp( $local_datetime ) {
		$local_timestamp      = mysql2date( 'G', $local_datetime ); //getting timestamp with timezone adjusted
		$local_date_timestamp = mysql2date( 'G', gmdate( 'Y-m-d 23:59:59', $local_timestamp ) ); //setting time portion of date to midnight (to match the way Javascript handles dates)
		$timestamp            = ( $local_date_timestamp - ( 24 * 60 * 60 ) + 1 ) * 1000; //adjusting timestamp for Javascript (subtracting a day and transforming it to milliseconds
		$date                 = gmdate( 'Y-m-d', $timestamp );
		return $timestamp;
	}

	private static function matches_current_date( $format, $js_timestamp ) {
		$target_date = 'YW' == $format ? $js_timestamp : date( $format, $js_timestamp / 1000 );

		$current_date = gmdate( $format, GFCommon::get_local_timestamp( time() ) );
		return $target_date == $current_date;
	}

	private static function daily_chart_info( $config ) {
		global $wpdb;

		$tz_offset = self::get_mysql_tz_offset();

		$results = $wpdb->get_results( "SELECT CONVERT_TZ(t.date_created, '+00:00', '" . $tz_offset . "') as date, sum(t.amount) as amount_sold, sum(is_renewal) as renewals, sum(is_renewal=0) as new_sales
                                        FROM {$wpdb->prefix}rg_lead l
                                        INNER JOIN {$wpdb->prefix}rg_stripe_transaction t ON l.id = t.entry_id
                                        WHERE form_id={$config["form_id"]} AND t.transaction_type='payment'
                                        GROUP BY date(date)
                                        ORDER BY payment_date desc
                                        LIMIT 30" );

		$sales_today   = 0;
		$revenue_today = 0;
		$tooltips      = '';
		$series        = '';
		$options       = '';
		if ( ! empty( $results ) ) {

			$data = '[';

			foreach ( $results as $result ) {
				$timestamp = self::get_graph_timestamp( $result->date );
				if ( self::matches_current_date( 'Y-m-d', $timestamp ) ) {
					$sales_today += $result->new_sales;
					$revenue_today += $result->amount_sold;
				}
				$data .= "[{$timestamp},{$result->amount_sold}],";

				if ( 'subscription' == $config[ "meta" ][ "type" ] ) {
					$sales_line = " <div class='stripe_tooltip_subscription'><span class='stripe_tooltip_heading'>" . __( "New Subscriptions", "gravityforms-stripe" ) . ": </span><span class='stripe_tooltip_value'>" . $result->new_sales . "</span></div><div class='stripe_tooltip_subscription'><span class='stripe_tooltip_heading'>" . __( "Renewals", "gravityforms-stripe" ) . ": </span><span class='stripe_tooltip_value'>" . $result->renewals . "</span></div>";
				}
				else {
					$sales_line = "<div class='stripe_tooltip_sales'><span class='stripe_tooltip_heading'>" . __( "Orders", "gravityforms-stripe" ) . ": </span><span class='stripe_tooltip_value'>" . $result->new_sales . "</span></div>";
				}

				$tooltips .= "\"<div class='stripe_tooltip_date'>" . GFCommon::format_date( $result->date, false, "", false ) . "</div>{$sales_line}<div class='stripe_tooltip_revenue'><span class='stripe_tooltip_heading'>" . __( "Revenue", "gravityforms-stripe" ) . ": </span><span class='stripe_tooltip_value'>" . GFCommon::to_money( $result->amount_sold ) . "</span></div>\",";
			}
			$data     = substr( $data, 0, strlen( $data ) - 1 );
			$tooltips = substr( $tooltips, 0, strlen( $tooltips ) - 1 );
			$data .= "]";

			$series      = "[{data:" . $data . "}]";
			$month_names = self::get_chart_month_names();
			$options     = "
            {
                xaxis: {mode: 'time', monthnames: $month_names, timeformat: '%b %d', minTickSize:[1, 'day']},
                yaxis: {tickFormatter: convertToMoney},
                bars: {show:true, align:'right', barWidth: (24 * 60 * 60 * 1000) - 10000000},
                colors: ['#a3bcd3', '#14568a'],
                grid: {hoverable: true, clickable: true, tickColor: '#F1F1F1', backgroundColor:'#FFF', borderWidth: 1, borderColor: '#CCC'}
            }";
		}
		switch ( $config[ "meta" ][ "type" ] ) {
			case 'product' :
				$sales_label = __( 'Orders Today', 'gravityforms-stripe' );
				break;

			case 'donation' :
				$sales_label = __( 'Donations Today', 'gravityforms-stripe' );
				break;

			case 'subscription' :
				$sales_label = __( 'Subscriptions Today', 'gravityforms-stripe' );
				break;
		}
		$revenue_today = GFCommon::to_money( $revenue_today );
		return array(
			'series'        => $series,
			'options'       => $options,
			'tooltips'      => "[$tooltips]",
			'revenue_label' => __( 'Revenue Today', 'gravityforms-stripe' ),
			'revenue'       => $revenue_today,
			'sales_label'   => $sales_label,
			'sales'         => $sales_today );
	}

	private static function weekly_chart_info( $config ) {
		global $wpdb;

		$tz_offset = self::get_mysql_tz_offset();

		$results      = $wpdb->get_results( "SELECT yearweek(CONVERT_TZ(t.date_created, '+00:00', '" . $tz_offset . "')) week_number, sum(t.amount) as amount_sold, sum(is_renewal) as renewals, sum(is_renewal=0) as new_sales
                                            FROM {$wpdb->prefix}rg_lead l
                                            INNER JOIN {$wpdb->prefix}rg_stripe_transaction t ON l.id = t.entry_id
                                            WHERE form_id={$config["form_id"]} AND t.transaction_type='payment'
                                            GROUP BY week_number
                                            ORDER BY week_number desc
                                            LIMIT 30" );
		$sales_week   = 0;
		$revenue_week = 0;
		if ( ! empty( $results ) ) {
			$data = '[';

			foreach ( $results as $result ) {
				if ( self::matches_current_date( 'YW', $result->week_number ) ) {
					$sales_week += $result->new_sales;
					$revenue_week += $result->amount_sold;
				}
				$data .= "[{$result->week_number},{$result->amount_sold}],";

				if ( "subscription" == $config[ "meta" ][ "type" ] ) {
					$sales_line = " <div class='stripe_tooltip_subscription'><span class='stripe_tooltip_heading'>" . __( "New Subscriptions", "gravityforms-stripe" ) . ": </span><span class='stripe_tooltip_value'>" . $result->new_sales . "</span></div><div class='stripe_tooltip_subscription'><span class='stripe_tooltip_heading'>" . __( "Renewals", "gravityforms-stripe" ) . ": </span><span class='stripe_tooltip_value'>" . $result->renewals . "</span></div>";
				}
				else {
					$sales_line = "<div class='stripe_tooltip_sales'><span class='stripe_tooltip_heading'>" . __( "Orders", "gravityforms-stripe" ) . ": </span><span class='stripe_tooltip_value'>" . $result->new_sales . "</span></div>";
				}

				$tooltips .= "\"<div class='stripe_tooltip_date'>" . substr( $result->week_number, 0, 4 ) . ", " . __( "Week", "gravityforms-stripe" ) . " " . substr( $result->week_number, strlen( $result->week_number ) - 2, 2 ) . "</div>{$sales_line}<div class='stripe_tooltip_revenue'><span class='stripe_tooltip_heading'>" . __( "Revenue", "gravityforms-stripe" ) . ": </span><span class='stripe_tooltip_value'>" . GFCommon::to_money( $result->amount_sold ) . "</span></div>\",";
			}
			$data     = substr( $data, 0, strlen( $data ) - 1 );
			$tooltips = substr( $tooltips, 0, strlen( $tooltips ) - 1 );
			$data .= "]";

			$series      = "[{data:" . $data . "}]";
			$month_names = self::get_chart_month_names();
			$options     = "
                {
                    xaxis: {tickFormatter: formatWeeks, tickDecimals: 0},
                    yaxis: {tickFormatter: convertToMoney},
                    bars: {show:true, align:'center', barWidth:0.95},
                    colors: ['#a3bcd3', '#14568a'],
                    grid: {hoverable: true, clickable: true, tickColor: '#F1F1F1', backgroundColor:'#FFF', borderWidth: 1, borderColor: '#CCC'}
                }";
		}

		switch ( $config[ "meta" ][ "type" ] ) {
			case 'product' :
				$sales_label = __( 'Orders this Week', 'gravityforms-stripe' );
				break;

			case 'donation' :
				$sales_label = __( 'Donations this Week', 'gravityforms-stripe' );
				break;

			case 'subscription' :
				$sales_label = __( 'Subscriptions this Week', 'gravityforms-stripe' );
				break;
		}
		$revenue_week = GFCommon::to_money( $revenue_week );

		return array(
			'series'        => $series,
			'options'       => $options,
			'tooltips'      => "[$tooltips]",
			'revenue_label' => __( 'Revenue this Week', 'gravityforms-stripe' ),
			'revenue'       => $revenue_week,
			'sales_label'   => $sales_label,
			'sales'         => $sales_week );
	}

	private static function monthly_chart_info( $config ) {
		global $wpdb;
		$tz_offset = self::get_mysql_tz_offset();

		$results = $wpdb->get_results( "SELECT date_format(CONVERT_TZ(t.date_created, '+00:00', '" . $tz_offset . "'), '%Y-%m-02') date, sum(t.amount) as amount_sold, sum(is_renewal) as renewals, sum(is_renewal=0) as new_sales
                                            FROM {$wpdb->prefix}rg_lead l
                                            INNER JOIN {$wpdb->prefix}rg_stripe_transaction t ON l.id = t.entry_id
                                            WHERE form_id={$config["form_id"]} AND t.transaction_type='payment'
                                            group by date
                                            order by date desc
                                            LIMIT 30" );

		$sales_month   = 0;
		$revenue_month = 0;
		if ( ! empty( $results ) ) {

			$data = '[';

			foreach ( $results as $result ) {
				$timestamp = self::get_graph_timestamp( $result->date );
				if ( self::matches_current_date( 'Y-m', $timestamp ) ) {
					$sales_month += $result->new_sales;
					$revenue_month += $result->amount_sold;
				}
				$data .= "[{$timestamp},{$result->amount_sold}],";

				if ( "subscription" == $config[ "meta" ][ "type" ] ) {
					$sales_line = " <div class='stripe_tooltip_subscription'><span class='stripe_tooltip_heading'>" . __( "New Subscriptions", "gravityforms-stripe" ) . ": </span><span class='stripe_tooltip_value'>" . $result->new_sales . "</span></div><div class='stripe_tooltip_subscription'><span class='stripe_tooltip_heading'>" . __( "Renewals", "gravityforms-stripe" ) . ": </span><span class='stripe_tooltip_value'>" . $result->renewals . "</span></div>";
				}
				else {
					$sales_line = "<div class='stripe_tooltip_sales'><span class='stripe_tooltip_heading'>" . __( "Orders", "gravityforms-stripe" ) . ": </span><span class='stripe_tooltip_value'>" . $result->new_sales . "</span></div>";
				}

				$tooltips .= "\"<div class='stripe_tooltip_date'>" . GFCommon::format_date( $result->date, false, "F, Y", false ) . "</div>{$sales_line}<div class='stripe_tooltip_revenue'><span class='stripe_tooltip_heading'>" . __( "Revenue", "gravityforms-stripe" ) . ": </span><span class='stripe_tooltip_value'>" . GFCommon::to_money( $result->amount_sold ) . "</span></div>\",";
			}
			$data     = substr( $data, 0, strlen( $data ) - 1 );
			$tooltips = substr( $tooltips, 0, strlen( $tooltips ) - 1 );
			$data .= "]";

			$series      = "[{data:" . $data . "}]";
			$month_names = self::get_chart_month_names();
			$options     = "
                {
                    xaxis: {mode: 'time', monthnames: $month_names, timeformat: '%b %y', minTickSize: [1, 'month']},
                    yaxis: {tickFormatter: convertToMoney},
                    bars: {show:true, align:'center', barWidth: (24 * 60 * 60 * 30 * 1000) - 130000000},
                    colors: ['#a3bcd3', '#14568a'],
                    grid: {hoverable: true, clickable: true, tickColor: '#F1F1F1', backgroundColor:'#FFF', borderWidth: 1, borderColor: '#CCC'}
                }";
		}
		switch ( $config[ "meta" ][ "type" ] ) {
			case 'product' :
				$sales_label = __( 'Orders this Month', 'gravityforms-stripe' );
				break;

			case 'donation' :
				$sales_label = __( 'Donations this Month', 'gravityforms-stripe' );
				break;

			case 'subscription' :
				$sales_label = __( 'Subscriptions this Month', 'gravityforms-stripe' );
				break;
		}
		$revenue_month = GFCommon::to_money( $revenue_month );
		return array(
			'series'        => $series,
			'options'       => $options,
			'tooltips'      => "[$tooltips]",
			'revenue_label' => __( 'Revenue this Month', 'gravityforms-stripe' ),
			'revenue'       => $revenue_month,
			'sales_label'   => $sales_label,
			'sales'         => $sales_month );
	}

	private static function get_mysql_tz_offset() {
		$tz_offset = get_option( 'gmt_offset' );

		//add + if offset starts with a number
		if ( is_numeric( substr( $tz_offset, 0, 1 ) ) )
			$tz_offset = '+' . $tz_offset;

		return $tz_offset . ':00';
	}

	private static function get_chart_month_names() {
		return "['" . __( "Jan", "gravityforms-stripe" ) . "','" . __( "Feb", "gravityforms-stripe" ) . "','" . __( "Mar", "gravityforms-stripe" ) . "','" . __( "Apr", "gravityforms-stripe" ) . "','" . __( "May", "gravityforms-stripe" ) . "','" . __( "Jun", "gravityforms-stripe" ) . "','" . __( "Jul", "gravityforms-stripe" ) . "','" . __( "Aug", "gravityforms-stripe" ) . "','" . __( "Sep", "gravityforms-stripe" ) . "','" . __( "Oct", "gravityforms-stripe" ) . "','" . __( "Nov", "gravityforms-stripe" ) . "','" . __( "Dec", "gravityforms-stripe" ) . "']";
	}

//------------------------------------------------------
//------------- EDIT STRIPE FEED PAGE ------------------
//------------------------------------------------------
	private static function edit_page() {
		require_once( GFCommon::get_base_path() . "/currency.php" );
		?>
	<style>
		#stripe_submit_container {
			clear: both;
		}

		.stripe_col_heading {
			padding-bottom: 2px;
			border-bottom: 1px solid #ccc;
			font-weight: bold;
			width: 120px;
		}

		.stripe_field_cell {
			padding: 6px 17px 0 0;
			margin-right: 15px;
		}

		.stripe_validation_error {
			background-color: #FFDFDF;
			margin-top: 4px;
			margin-bottom: 6px;
			padding-top: 6px;
			padding-bottom: 6px;
			border: 1px dotted #C89797;
		}

		.stripe_validation_error span {
			color: red;
		}

		.left_header {
			float: left;
			width: 200px;
		}

		.margin_vertical_10 {
			margin: 10px 0;
			padding-left: 5px;
		}

		.margin_vertical_30 {
			margin: 30px 0;
			padding-left: 5px;
		}

		.width-1 {
			width: 300px;
		}

		.gf_stripe_invalid_form {
			margin-top: 30px;
			background-color: #FFEBE8;
			border: 1px solid #CC0000;
			padding: 10px;
			width: 600px;
		}
	</style>

	<script type="text/javascript" src="<?php echo GFCommon::get_base_url()?>/js/gravityforms.js"></script>
	<script type="text/javascript">
		var form = Array();

		window['gf_currency_config'] = <?php echo json_encode( RGCurrency::get_currency( "USD" ) ) ?>;
		function FormatCurrency(element) {
			var val = jQuery(element).val();
			jQuery(element).val(gformFormatMoney(val));
		}
	</script>

	<div class="wrap">
	<img alt="<?php _e( 'Stripe', 'gravityforms-stripe' ) ?>" style="margin: 15px 7px 0pt 0pt; float: left;"
			 src="<?php echo self::get_base_url() ?>/images/stripe_wordpress_icon_32.png"/>

	<h2><?php _e( 'Stripe Transaction Settings', 'gravityforms-stripe' ) ?></h2>

		<?php

		//getting setting id (0 when creating a new one)
		$id                  = ! empty( $_POST[ "stripe_setting_id" ] ) ? $_POST[ "stripe_setting_id" ] : absint( $_GET[ "id" ] );
		$config              = empty( $id ) ? array(
			'meta'      => array(),
			'is_active' => true ) : GFStripeData::get_feed( $id );
		$is_validation_error = false;

		//updating meta information
		if ( rgpost( 'gf_stripe_submit' ) ) {

			$config[ "form_id" ]        = absint( rgpost( 'gf_stripe_form' ) );
			$config[ "meta" ][ "type" ] = rgpost( 'gf_stripe_type' );
			$config[ "meta" ][ "update_post_action" ] = rgpost( 'gf_stripe_update_action' );

			// stripe conditional
			$config[ "meta" ][ "stripe_conditional_enabled" ]  = rgpost( 'gf_stripe_conditional_enabled' );
			$config[ "meta" ][ "stripe_conditional_field_id" ] = rgpost( 'gf_stripe_conditional_field_id' );
			$config[ "meta" ][ "stripe_conditional_operator" ] = rgpost( 'gf_stripe_conditional_operator' );
			$config[ "meta" ][ "stripe_conditional_value" ]    = rgpost( 'gf_stripe_conditional_value' );

			//subscription plan field
			$config[ "meta" ][ "subscription_plan_field" ] = rgpost( 'gf_stripe_subscription_plan' );

			//-----------------

			$customer_fields                       = self::get_customer_fields();
			$config[ "meta" ][ "customer_fields" ] = array();
			foreach ( $customer_fields as $field ) {
				$config[ "meta" ][ "customer_fields" ][ $field[ "name" ] ] = $_POST[ "stripe_customer_field_{$field["name"]}" ];
			}

			$config = apply_filters( 'gform_stripe_save_config', $config );

			$is_validation_error = apply_filters( 'gform_stripe_config_validation', false, $config );

			if ( ! $is_validation_error ) {
				$id = GFStripeData::update_feed( $id, $config[ "form_id" ], $config[ "is_active" ], $config[ "meta" ] );
				?>
			<div class="updated fade"
					 style="padding:6px"><?php echo sprintf( __( "Feed Updated. %sback to list%s", 'gravityforms-stripe' ), "<a href='?page=gf_stripe'>", '</a>' ) ?></div>
				<?php
			}
			else {
				$is_validation_error = true;
			}
		}

		$form     = isset( $config[ "form_id" ] ) && $config[ "form_id" ] ? $form = RGFormsModel::get_form_meta( $config[ "form_id" ] ) : array();
		$settings = get_option( 'gf_stripe_settings' );
		?>
	<form method="post" action="">
	<input type="hidden" name="stripe_setting_id" value="<?php echo $id ?>"/>

	<div class="margin_vertical_10 <?php echo $is_validation_error ? 'stripe_validation_error' : '' ?>">
		<?php
		if ( $is_validation_error ) {
			?>
			<span><?php _e( 'There was an issue saving your feed. Please address the errors below and try again.' ); ?></span>
			<?php
		}
		?>
	</div>
	<!-- / validation message -->

	<?php
	if( 'on' == $settings[ 'stripe_webhook_configured' ] ) {
	?>

		<div class="margin_vertical_10">
			<label class="left_header"
					 	 for="gf_stripe_type"><?php _e( 'Transaction Type', 'gravityforms-stripe' ); ?> <?php gform_tooltip( 'stripe_transaction_type' ) ?></label>

			<select id="gf_stripe_type" name="gf_stripe_type" onchange="SelectType(jQuery(this).val());">
				<option value=""><?php _e( 'Select a transaction type', 'gravityforms-stripe' ) ?></option>
				<option value="product" <?php echo 'product' == rgar( $config[ 'meta' ], 'type' ) ? "selected='selected'" : "" ?>><?php _e( 'Products and Services', 'gravityforms-stripe' ) ?></option>
				<option value="subscription" <?php echo 'subscription' == rgar( $config[ 'meta' ], 'type' ) ? "selected='selected'" : "" ?>><?php _e( 'Subscriptions', 'gravityforms-stripe' ) ?></option>
			</select>
		</div>
	<?php
	} else {
			$config[ 'meta' ][ 'type' ]= 'product' ?>

			<input id="gf_stripe_type" type="hidden" name="gf_stripe_type" value="product">


	<?php } ?>

	<div id="stripe_form_container" valign="top"
			 class="margin_vertical_10" <?php echo empty( $config[ "meta" ][ "type" ] ) ? "style='display:none;'" : '' ?>>
		<label for="gf_stripe_form"
					 class="left_header"><?php _e( 'Gravity Form', 'gravityforms-stripe' ); ?> <?php gform_tooltip( 'stripe_gravity_form' ) ?></label>

		<select id="gf_stripe_form" name="gf_stripe_form"
						onchange="SelectForm(jQuery('#gf_stripe_type').val(), jQuery(this).val(), '<?php echo rgar( $config, 'id' ) ?>');">
			<option value=""><?php _e( 'Select a form', 'gravityforms-stripe' ); ?> </option>
			<?php

			$active_form     = rgar( $config, 'form_id' );
			$available_forms = GFStripeData::get_available_forms( $active_form );

			foreach ( $available_forms as $current_form ) {
				$selected = absint( $current_form->id ) == rgar( $config, 'form_id' ) ? 'selected="selected"' : '';
				?>

				<option
						value="<?php echo absint( $current_form->id ) ?>" <?php echo $selected; ?>><?php echo esc_html( $current_form->title ) ?></option>

				<?php
			}
			?>
		</select>
		&nbsp;&nbsp;
		<img src="<?php echo GFStripe::get_base_url() ?>/images/loading.gif" id="stripe_wait" style="display: none;"/>

		<div id="gf_stripe_invalid_product_form" class="gf_stripe_invalid_form" style="display:none;">
			<?php _e( 'The form selected does not have any Product fields. Please add a Product field to the form and try again.', 'gravityforms-stripe' ) ?>
		</div>
		<div id="gf_stripe_invalid_creditcard_form" class="gf_stripe_invalid_form" style="display:none;">
			<?php _e( 'The form selected does not have a credit card field. Please add a credit card field to the form and try again.', 'gravityforms-stripe' ) ?>
		</div>
	</div>
	<div id="stripe_field_group"
			 valign="top" <?php echo strlen( rgars( $config, "meta/type" ) ) == 0 || empty( $config[ "form_id" ] ) ? "style='display:none;'" : '' ?>>

		<div id="stripe_field_container_subscription" class="stripe_field_container" valign="top" <?php echo rgars( $config, 'meta/type' ) != 'subscription' ? "style='display:none;'" : ''?>>
       <div class="margin_vertical_10">
         <label class="left_header" for="gf_stripe_subscription_plan"><?php _e( 'Subscription Plan', 'gravityforms-stripe' ); ?> <?php gform_tooltip( 'stripe_subscription_plan' ) ?></label>
         <select id="gf_stripe_subscription_plan" name="gf_stripe_subscription_plan">
           <?php echo self::get_plan_options( $form, $config[ "meta" ][ "subscription_plan_field" ] ) ?>
         </select>
       </div>
    </div>

		<div class="margin_vertical_10">
			<label
					class="left_header"><?php _e( 'Billing Information', 'gravityforms-stripe' ); ?> <?php gform_tooltip( 'stripe_customer' ) ?></label>

			<div id="stripe_customer_fields">
				<?php
				if ( ! empty( $form ) )
					echo self::get_customer_information( $form, $config );
				?>
			</div>
		</div>


		<div class="margin_vertical_10">
			<label
					class="left_header"><?php _e( 'Options', 'gravityforms-stripe' ); ?> <?php gform_tooltip( 'stripe_options' ) ?></label>

			<ul style="overflow:hidden;">
				<?php
				$display_post_fields = ! empty( $form ) ? GFCommon::has_post_field( $form[ "fields" ] ) : false;
				?>
				<li id="stripe_post_update_action" <?php echo $display_post_fields && 'subscription' == $config[ "meta" ][ "type" ] ? '' : "style='display:none;'" ?>>
					<input type="checkbox" name="gf_stripe_update_post" id="gf_stripe_update_post"
								 value="1" <?php echo rgar( $config[ "meta" ], 'update_post_action' ) ? "checked='checked'" : ""?>
								 onclick="var action = this.checked ? 'draft' : ''; jQuery('#gf_stripe_update_action').val(action);"/>
					<label class="inline"
								 for="gf_stripe_update_post"><?php _e( 'Update Post when subscription is cancelled.', 'gravityforms-stripe' ); ?> <?php gform_tooltip( 'stripe_update_post' ) ?></label>
					<select id="gf_stripe_update_action" name="gf_stripe_update_action"
									onchange="var checked = jQuery(this).val() ? 'checked' : false; jQuery('#gf_stripe_update_post').attr('checked', checked);">
						<option value=""></option>
						<option
								value="draft" <?php echo 'draft' == rgar( $config[ "meta" ], 'update_post_action' ) ? "selected='selected'" : ""?>><?php _e( 'Mark Post as Draft', 'gravityforms-stripe' ) ?></option>
						<option
								value="delete" <?php echo 'delete' == rgar( $config[ "meta" ], 'update_post_action' ) ? "selected='selected'" : ""?>><?php _e( 'Delete Post', 'gravityforms-stripe' ) ?></option>
					</select>
				</li>

				<?php do_action( 'gform_stripe_action_fields', $config, $form ) ?>
			</ul>
		</div>

		<?php do_action( 'gform_stripe_add_option_group', $config, $form ); ?>

		<div id="gf_stripe_conditional_section" valign="top" class="margin_vertical_10">
			<label for="gf_stripe_conditional_optin"
						 class="left_header"><?php _e( 'Stripe Condition', 'gravityforms-stripe' ); ?> <?php gform_tooltip( 'stripe_conditional' ) ?></label>

			<div id="gf_stripe_conditional_option">
				<table cellspacing="0" cellpadding="0">
					<tr>
						<td>
							<input type="checkbox" id="gf_stripe_conditional_enabled" name="gf_stripe_conditional_enabled" value="1"
										 onclick="if(this.checked){jQuery('#gf_stripe_conditional_container').fadeIn('fast');} else{ jQuery('#gf_stripe_conditional_container').fadeOut('fast'); }" <?php echo rgar( $config[ 'meta' ], 'stripe_conditional_enabled' ) ? "checked='checked'" : ''?>/>
							<label for="gf_stripe_conditional_enable"><?php _e( 'Enable', 'gravityforms-stripe' ); ?></label>
						</td>
					</tr>
					<tr>
						<td>
							<div
									id="gf_stripe_conditional_container" <?php echo ! rgar( $config[ 'meta' ], 'stripe_conditional_enabled' ) ? "style='display:none'" : ''?>>

								<div
										id="gf_stripe_conditional_fields" <?php echo empty( $selection_fields ) ? "style='display:none'" : ""?>>
									<?php _e( 'Send to Stripe if ', 'gravityforms-stripe' ) ?>

									<select id="gf_stripe_conditional_field_id" name="gf_stripe_conditional_field_id" class="optin_select"
													onchange='jQuery("#gf_stripe_conditional_value").html(GetFieldValues(jQuery(this).val(), "", 20));'>
										<?php echo $selection_fields ?>
									</select>
									<select id="gf_stripe_conditional_operator" name="gf_stripe_conditional_operator">
										<option
												value="is" <?php echo rgar( $config[ 'meta' ], 'stripe_conditional_operator' ) == 'is' ? "selected='selected'" : '' ?>><?php _e( 'is', 'gravityforms-stripe' ) ?></option>
										<option
												value="isnot" <?php echo rgar( $config[ 'meta' ], 'stripe_conditional_operator' ) == 'isnot' ? "selected='selected'" : '' ?>><?php _e( 'is not', 'gravityforms-stripe' ) ?></option>
									</select>
									<select id="gf_stripe_conditional_value" name="gf_stripe_conditional_value"
													class='optin_select'></select>

								</div>

								<div
										id="gf_stripe_conditional_message" <?php echo ! empty( $selection_fields ) ? "style='display:none'" : ""?>>
									<?php _e( 'To create a registration condition, your form must have a drop down, checkbox or multiple choice field', 'gravityform' ) ?>
								</div>

							</div>
						</td>
					</tr>
				</table>
			</div>

		</div>
		<!-- / stripe conditional -->

		<div id="stripe_submit_container" class="margin_vertical_30">
			<input type="submit" name="gf_stripe_submit"
						 value="<?php echo empty( $id ) ? __( '  Save  ', 'gravityforms-stripe' ) : __( 'Update', 'gravityforms-stripe' ); ?>"
						 class="button-primary"/>
			<input type="button" value="<?php _e( 'Cancel', 'gravityforms-stripe' ); ?>" class="button"
						 onclick="javascript:document.location='admin.php?page=gf_stripe'"/>
		</div>
	</div>
	</form>
	</div>

	<script type="text/javascript">

		function SelectType(type) {
			jQuery("#stripe_field_group").slideUp();

			jQuery("#stripe_field_group input[type=\"text\"], #stripe_field_group select").val("");

			jQuery("#stripe_field_group input:checked").attr("checked", false);

			if (type) {
				jQuery("#stripe_form_container").slideDown();
				jQuery("#gf_stripe_form").val("");
			}
			else {
				jQuery("#stripe_form_container").slideUp();
			}
		}

		function SelectForm(type, formId, settingId) {
			if (!formId) {
				jQuery("#stripe_field_group").slideUp();
				return;
			}

			jQuery("#stripe_wait").show();
			jQuery("#stripe_field_group").slideUp();

			var mysack = new sack("<?php bloginfo( 'wpurl' ); ?>/wp-admin/admin-ajax.php");
			mysack.execute = 1;
			mysack.method = 'POST';
			mysack.setVar("action", "gf_select_stripe_form");
			mysack.setVar("gf_select_stripe_form", "<?php echo wp_create_nonce( 'gf_select_stripe_form' ) ?>");
			mysack.setVar("type", type);
			mysack.setVar("form_id", formId);
			mysack.setVar("setting_id", settingId);
			mysack.encVar("cookie", document.cookie, false);
			mysack.onError = function () {
				jQuery("#stripe_wait").hide();
				alert('<?php _e( 'Ajax error while selecting a form', 'gravityforms-stripe' ) ?>')
			};
			mysack.runAJAX();

			return true;
		}

		function EndSelectForm(form_meta, customer_fields, subscription_plan_options) {
			//setting global form object
			form = form_meta;

			var type = jQuery("#gf_stripe_type").val();

			jQuery(".gf_stripe_invalid_form").hide();
			if ((type == "product" || type == "subscription") && GetFieldsByType(["product"]).length == 0) {
				jQuery("#gf_stripe_invalid_product_form").show();
				jQuery("#stripe_wait").hide();
				return;
			}
			else if ((type == "product" || type == "subscription") && GetFieldsByType(["creditcard"]).length == 0) {
				jQuery("#gf_stripe_invalid_creditcard_form").show();
				jQuery("#stripe_wait").hide();
				return;
			}

			jQuery(".stripe_field_container").hide();
			jQuery("#stripe_customer_fields").html(customer_fields);
			jQuery("#gf_stripe_subscription_plan").html(subscription_plan_options);

			var post_fields = GetFieldsByType(["post_title", "post_content", "post_excerpt", "post_category", "post_custom_field", "post_image", "post_tag"]);
			if (type == "subscription" && post_fields.length > 0) {
				jQuery("#stripe_post_update_action").show();
			}
			else {
				jQuery("#gf_stripe_update_post").attr("checked", false);
				jQuery("#stripe_post_update_action").hide();
			}

			//Calling callback functions
			jQuery(document).trigger('stripeFormSelected', [form]);

			jQuery("#gf_stripe_conditional_enabled").attr('checked', false);
			SetStripeCondition("", "");

			jQuery("#stripe_field_container_" + type).show();
			jQuery("#stripe_field_group").slideDown();
			jQuery("#stripe_wait").hide();
		}


		function GetFieldsByType(types) {
			var fields = new Array();
			for (var i = 0; i < form["fields"].length; i++) {
				if (IndexOf(types, form["fields"][i]["type"]) >= 0)
					fields.push(form["fields"][i]);
			}
			return fields;
		}

		function IndexOf(ary, item) {
			for (var i = 0; i < ary.length; i++)
				if (ary[i] == item)
					return i;

			return -1;
		}

	</script>

	<script type="text/javascript">

		// Stripe Conditional Functions

			<?php
			if ( ! empty( $config[ "form_id" ] ) ) {
				?>

			// initialize form object
			form = <?php echo GFCommon::json_encode( $form )?> ;

			// initializing registration condition drop downs
			jQuery(document).ready(function () {
				var selectedField = "<?php echo str_replace( '"', '\"', $config[ "meta" ][ "stripe_conditional_field_id" ] )?>";
				var selectedValue = "<?php echo str_replace( '"', '\"', $config[ "meta" ][ "stripe_conditional_value" ] )?>";
				SetStripeCondition(selectedField, selectedValue);
			});

				<?php
			}
			?>

		function SetStripeCondition(selectedField, selectedValue) {

			// load form fields
			jQuery("#gf_stripe_conditional_field_id").html(GetSelectableFields(selectedField, 20));
			var optinConditionField = jQuery("#gf_stripe_conditional_field_id").val();
			var checked = jQuery("#gf_stripe_conditional_enabled").attr('checked');

			if (optinConditionField) {
				jQuery("#gf_stripe_conditional_message").hide();
				jQuery("#gf_stripe_conditional_fields").show();
				jQuery("#gf_stripe_conditional_value").html(GetFieldValues(optinConditionField, selectedValue, 20));
			}
			else {
				jQuery("#gf_stripe_conditional_message").show();
				jQuery("#gf_stripe_conditional_fields").hide();
			}

			if (!checked) jQuery("#gf_stripe_conditional_container").hide();

		}

		function GetFieldValues(fieldId, selectedValue, labelMaxCharacters) {
			if (!fieldId)
				return "";

			var str = "";
			var field = GetFieldById(fieldId);
			if (!field || !field.choices)
				return "";

			var isAnySelected = false;

			for (var i = 0; i < field.choices.length; i++) {
				var fieldValue = field.choices[i].value ? field.choices[i].value : field.choices[i].text;
				var isSelected = fieldValue == selectedValue;
				var selected = isSelected ? "selected='selected'" : "";
				if (isSelected)
					isAnySelected = true;

				str += "<option value='" + fieldValue.replace(/'/g, "&#039;") + "' " + selected + ">" + TruncateMiddle(field.choices[i].text, labelMaxCharacters) + "</option>";
			}

			if (!isAnySelected && selectedValue) {
				str += "<option value='" + selectedValue.replace(/'/g, "&#039;") + "' selected='selected'>" + TruncateMiddle(selectedValue, labelMaxCharacters) + "</option>";
			}

			return str;
		}

		function GetFieldById(fieldId) {
			for (var i = 0; i < form.fields.length; i++) {
				if (form.fields[i].id == fieldId)
					return form.fields[i];
			}
			return null;
		}

		function TruncateMiddle(text, maxCharacters) {
			if (text.length <= maxCharacters)
				return text;
			var middle = parseInt(maxCharacters / 2);
			return text.substr(0, middle) + "..." + text.substr(text.length - middle, middle);
		}

		function GetSelectableFields(selectedFieldId, labelMaxCharacters) {
			var str = "";
			var inputType;
			for (var i = 0; i < form.fields.length; i++) {
				fieldLabel = form.fields[i].adminLabel ? form.fields[i].adminLabel : form.fields[i].label;
				inputType = form.fields[i].inputType ? form.fields[i].inputType : form.fields[i].type;
				if (inputType == "checkbox" || inputType == "radio" || inputType == "select") {
					var selected = form.fields[i].id == selectedFieldId ? "selected='selected'" : "";
					str += "<option value='" + form.fields[i].id + "' " + selected + ">" + TruncateMiddle(fieldLabel, labelMaxCharacters) + "</option>";
				}
			}
			return str;
		}

	</script>

	<?php

	}

	public static function gf_select_stripe_form() {

		check_ajax_referer( 'gf_select_stripe_form', 'gf_select_stripe_form' );

		$type       = $_POST[ "type" ];
		$form_id    = intval( $_POST[ "form_id" ] );
		$setting_id = intval( $_POST[ "setting_id" ] );

		//fields meta
		$form = RGFormsModel::get_form_meta( $form_id );

		$customer_fields         = self::get_customer_information( $form );
		$subscription_plan_fields = self::get_plan_options( $form, "" );

		die( "EndSelectForm(" . GFCommon::json_encode( $form ) . ", '" . str_replace( "'", "\'", $customer_fields ) . "', '" . str_replace( "'", "\'", $subscription_plan_fields ) . "');" );
	}

	public static function add_permissions() {
		global $wp_roles;
		$wp_roles->add_cap( 'administrator', 'gravityforms_stripe' );
		$wp_roles->add_cap( 'administrator', 'gravityforms_stripe_uninstall' );
	}

	//Target of Member plugin filter. Provides the plugin with Gravity Forms lists of capabilities
	public static function members_get_capabilities( $caps ) {
		return array_merge( $caps, array( 'gravityforms_stripe', 'gravityforms_stripe_uninstall' ) );
	}

	public static function gform_enqueue_scripts( $form = null, $ajax = null ) {

		if ( ! $form == null ) {

			if ( GFCommon::has_credit_card_field( $form ) ) {

				$form_feeds = GFStripeData::get_feed_by_form( $form['id'] );

				if ( ! empty( $form_feeds ) ) {
					wp_enqueue_script( 'stripe-js', 'https://js.stripe.com/v1/', array( 'jquery' ) );
				}
			}

		}

	}

	public static function has_stripe_condition( $form, $config ) {

		$config = $config[ "meta" ];

		$operator = $config[ "stripe_conditional_operator" ];
		$field    = RGFormsModel::get_field( $form, $config[ "stripe_conditional_field_id" ] );

		if ( empty( $field ) || ! $config[ "stripe_conditional_enabled" ] )
			return true;

		// if conditional is enabled, but the field is hidden, ignore conditional
		$is_visible = ! RGFormsModel::is_field_hidden( $form, $field, array() );

		$field_value = RGFormsModel::get_field_value( $field, array() );

		$is_value_match = RGFormsModel::is_value_match( $field_value, $config[ "stripe_conditional_value" ] );
		$is_match       = $is_value_match && $is_visible;

		$go_to_stripe = ( 'is' == $operator && $is_match ) || ( 'isnot' == $operator && ! $is_match );

		return $go_to_stripe;
	}

	public static function get_config( $form ) {
		if ( ! class_exists( 'GFStripeData' ) )
			require_once( GRAVITYFORMS_STRIPE_PATH . '/data.php' );

		//Getting stripe settings associated with this transaction
		$configs = GFStripeData::get_feed_by_form( $form[ "id" ] );
		if ( ! $configs )
			return false;

		foreach ( $configs as $config ) {
			if ( self::has_stripe_condition( $form, $config ) )
				return $config;
		}

		return false;
	}

	public static function get_creditcard_field( $form ) {
		$fields = GFCommon::get_fields_by_type( $form, array( 'creditcard' ) );
		return empty( $fields ) ? false : $fields[ 0 ];
	}

	public static function gform_field_content( $field_content, $field, $default_value, $lead_id, $form_id ) {

		$form_feeds = GFStripeData::get_feed_by_form( $form_id );


		if ( ! empty( $form_feeds ) ) {

			if ( 'creditcard' == $field[ 'type' ] ) {

				//Remove SSL warning
				$ssl_warning   = "<div class='gfield_creditcard_warning_message'>" . __( "This page is unsecured. Do not enter a real credit card number. Use this field only for testing purposes. ", "gravityforms" ) . "</div>";
				$field_content = str_ireplace( $ssl_warning, "", $field_content );

				//Remove input field name attribute so credit card information is not sent to POST variable
				$search = array();
				$exp_date_input = $field['id'] . '.2';
				foreach ( $field[ 'inputs' ] as $input ) {
					( $input[ 'id' ] == $exp_date_input ) ? ( $search[ ] = "name='input_" . $input[ 'id' ] . "[]'" ) : ( $search[ ] = "name='input_" . $input[ 'id' ] . "'" );
				}
				$field_content = str_ireplace( $search, '', $field_content );
			}

		}

		return $field_content;

	}

	public static function gform_field_css_class( $css_class, $field, $form ) {
		$form_feeds = GFStripeData::get_feed_by_form( $form[ 'id' ] );
		if ( ! empty( $form_feeds ) ) {
			$css_class = str_ireplace( 'gfield_creditcard_warning', '', $css_class );
		}
		return $css_class;

	}


	private static function is_ready_for_capture( $validation_result ) {

		//if form has already failed validation or this is not the last page, abort
		if ( false == $validation_result[ "is_valid" ] || ! self::is_last_page( $validation_result[ "form" ] ) )
			return false;

		//getting config that matches condition (if conditions are enabled)
		$config = self::get_config( $validation_result[ "form" ] );
		if ( ! $config )
			return false;

		//making sure credit card field is visible TODO: check to see if this will actually work since there are no credit card fields submitted with the form
		$creditcard_field = self::get_creditcard_field( $validation_result[ "form" ] );
		if ( RGFormsModel::is_field_hidden( $validation_result[ "form" ], $creditcard_field, array() ) )
			return false;

		return $config;
	}

	private static function is_last_page( $form ) {
		$current_page = GFFormDisplay::get_source_page( $form[ "id" ] );
		$target_page  = GFFormDisplay::get_target_page( $form, $current_page, rgpost( 'gform_field_values' ) );
		return $target_page == 0;
	}

	private static function get_trial_info( $config ) {

		$trial_amount      = false;
		$trial_occurrences = 0;
		if ( 1 == $config[ "meta" ][ "trial_period_enabled" ] ) {
			$trial_occurrences = $config[ "meta" ][ "trial_period_number" ];
			$trial_amount      = $config[ "meta" ][ "trial_amount" ];
			if ( empty( $trial_amount ) )
				$trial_amount = 0;
		}
		$trial_enabled = $trial_amount !== false;

		if ( $trial_enabled && ! empty( $trial_amount ) )
			$trial_amount = GFCommon::to_number( $trial_amount );

		return array(
			'trial_enabled'     => $trial_enabled,
			'trial_amount'      => $trial_amount,
			'trial_occurrences' => $trial_occurrences );
	}

	public static function gform_field_validation( $validation_result, $value, $form, $field ) {
		$form_feeds = GFStripeData::get_feed_by_form( $form[ 'id' ] );
		if ( ! empty( $form_feeds ) ) {
			if ( 'creditcard' == $field[ 'type' ] ) {
				$card_number_valid = rgpost( 'card_number_valid' );
				$exp_date_valid = rgpost( 'exp_date_valid' );
				$cvc_valid = rgpost( 'cvc_valid' );
				$cardholder_name_valid = rgpost( 'cardholder_name_valid' );
				$create_token_error = rgpost( 'create_token_error' );
				if ( ( 'false' == $card_number_valid ) || ( 'false' == $exp_date_valid ) || ( 'false' == $cvc_valid ) || ( 'false' == $cardholder_name_valid ) ) {
					$validation_result[ 'is_valid' ] = false;
					$message = ( 'false' == $card_number_valid ) ? __( 'Invalid credit card number.', 'gravityforms-stripe' ) : '';
					$message .= ( 'false' == $exp_date_valid ) ? __( ' Invalid expiration date.', 'gravityforms-stripe' ) : '';
					$message .= ( 'false' == $cvc_valid ) ? __( ' Invalid security code.', 'gravityforms-stripe' ) : '';
					$message .= ( 'false' == $cardholder_name_valid ) ? __( ' Invalid cardholder name.', 'gravityforms-stripe' ) : '';
					$validation_result['message'] = sprintf( __('%s', 'gravityforms-stripe'), $message );
				} else if ( ! empty( $create_token_error ) ) {
					$validation_result[ 'is_valid' ] = false;
					$validation_result['message'] = sprintf( __('%s', 'gravityforms-stripe'), $create_token_error );
				}
				else {
					$validation_result[ 'is_valid' ] = true;
					unset( $validation_result[ 'message' ] );
				}
			}
		}

		return $validation_result;
	}

	public static function gform_validation( $validation_result ) {

		$config = self::is_ready_for_capture( $validation_result );
		if ( ! $config )
			return $validation_result;

		if ( 'product' == $config[ "meta" ][ "type" ] ) {
			//making one time payment
			$validation_result = self::make_product_payment( $config, $validation_result );
			return $validation_result;
		}
		else {
    	// creating subscription
      $validation_result = self::start_subscription( $config, $validation_result );
      return $validation_result;
    }
	}

	private static function inject_gf_stripe( $injection, $inject_into, $inject_point ) {
		//$before_injection_point = stristr( $inject_into, $inject_point, true );
		$before_injection_point = substr( $inject_into, 0, strpos( $inject_into, $inject_point ) );
		$after_injection_point  = stristr( $inject_into, $inject_point );
		if ( $before_injection_point && $after_injection_point ) {
			$inject_into = $before_injection_point . $injection . $after_injection_point;
		}

		return $inject_into;
	}

	public static function gform_get_form_filter( $form_string ) {


		//Get form ID
		$form_id = stristr( $form_string, 'gform_wrapper_' );
		$form_id = str_ireplace( 'gform_wrapper_', '', $form_id );
		//$form_id = stristr( $form_id, "'", true );
		$form_id = strtok( $form_id, "'" );

		//Check for credit card field
		$form = RGFormsModel::get_form_meta( $form_id );
		if ( GFCommon::has_credit_card_field( $form ) ) {

			//Check for Stripe feed
			$form_feeds = GFStripeData::get_feed_by_form( $form_id );
			if ( ! empty( $form_feeds ) ) {

				//Get Stripe API key
				$settings = get_option( 'gf_stripe_settings' );
				$mode     = rgar( $settings, 'mode' );
				switch ( $mode ) {
					case 'test':
						$publishable_key = esc_attr( rgar( $settings, 'test_publishable_key' ) );
						break;
					case 'live':
						$publishable_key = esc_attr( rgar( $settings, 'live_publishable_key' ) );
						break;
					default:
						//something is wrong TODO better error handling here
						return $form_string;
				}

				//Get credit card field ID and address fields if they exist
				foreach ( $form['fields'] as $field ) {
					if ( 'creditcard' == $field[ 'type' ] ) {
						$field_id = $field['id'];
					}
					else if ( ( 'address' == $field[ 'type' ] )  ) {
						$address_required = $field['isRequired'];
						if ( $address_required ) {
							foreach ( $field['inputs'] as $input ) {
								if ( ( $field['id'] . '.1' ) == $input['id'] ) {
									$street_input_id = $form_id . '_' . $field['id'] . '_1';
								}
								else if ( ( ( $field['id'] . '.4' ) == $input['id'] ) && ( ! $field['hideState'] ) ) {
									$state_input_id = $form_id . '_' . $field['id'] . '_4';
								}
								else if ( ( $field['id'] . '.5' ) == $input['id'] ) {
									$zip_input_id = $form_id . '_' . $field['id'] . '_5';
								}
								else if ( ( ( $field['id'] . '.6' ) == $input['id'] ) && ( ! $field['hideCountry'] ) ) {
									$country_input_id = $form_id . '_' . $field['id'] . '_6';
								}
							}
						}
					}
				}

				//Make sure JS gets added for multi-page forms
				$needle      = "<meta charset='UTF-8' /></head>";
				$js          = "<script type='text/javascript' src='https://js.stripe.com/v1/'></script>";
				$form_string = self::inject_gf_stripe( $js, $form_string, $needle );

				//Output JS to create token
				$needle      = "function gformInitSpinner_{$form_id}(){";
				$js          = "function stripeResponseHandler(status, response) {" .
					"if (response.error) {" .
						"var param = response.error.param;" .
						"var form$ = jQuery('#gform_{$form_id}');" .
						"form$.append(\"<input type='hidden' name='create_token_error' value='\" + message + \"' />\");" .
					"} else {" .
						"var form$ = jQuery('#gform_{$form_id}');" .
						"var token = response['id'];" .
						"form$.append(\"<input type='hidden' name='stripeToken' value='\" + token + \"' />\");" .
					"}" .
					"form$.get(0).submit();" .
				"}";
				$form_string = self::inject_gf_stripe( $js, $form_string, $needle );

				$needle      = "});" .
					"}" .
					"jQuery(document).ready(function($){" .
					"gformInitSpinner_{$form_id}();";
				$js          = "var last_page = jQuery('#gform_target_page_number_{$form_id}').val();" .

					"if ( last_page === '0' ){" .
						"var form$ = jQuery('#gform_{$form_id}');" .
						"Stripe.setPublishableKey('" . $publishable_key . "');" .
						"var card_number = jQuery('#gform_{$form_id} span.ginput_cardextras').prev().children(':input').val();" .
						"var exp_month = jQuery('#gform_{$form_id} .ginput_card_expiration_month').val();" .
						"var exp_year = jQuery('#gform_{$form_id} .ginput_card_expiration_year').val();" .
						"var cvc = jQuery('#gform_{$form_id} .ginput_card_security_code').val();" .
						"var cardholder_name = jQuery('#gform_{$form_id} #input_{$form_id}_{$field_id}_5').val();";

				if ( isset( $address_required ) && $address_required) {
					$js .= ( !empty( $street_input_id) ) ? "var address_line1 = jQuery('#gform_{$form_id} #input_{$street_input_id}').val();" : "var address_line1 = '';";
					if ( isset( $state_input_id) ) {
						$js .= ( !empty( $state_input_id ) )  ? "var address_state = jQuery('#gform_{$form_id} #input_{$state_input_id}').val();" : "var address_state = '';";
					}
					$js .= ( !empty( $zip_input_id) ) ? "var address_zip = jQuery('#gform_{$form_id} #input_{$zip_input_id}').val();" : "var address_zip = '';";
					if ( isset( $country_input_id) ) {
						$js .= ( !empty( $country_input_id) ) ? "var address_country = jQuery('#gform_{$form_id} #input_{$country_input_id}').val();" : "var address_country = '';";
					}
				}

				$js .=
						"var card_number_valid = Stripe.validateCardNumber(card_number);" .
						"var exp_date_valid = Stripe.validateExpiry(exp_month, exp_year);" .
						"var cvc_valid = Stripe.validateCVC(cvc);" .
						"var cardholder_name_valid = (cardholder_name.length > 0 ) ? true : false;" .
						"if ( !card_number_valid || !exp_date_valid || !cvc_valid || !cardholder_name_valid ) {".
							"form$.append(\"<input type='hidden' name='card_number_valid' value='\" + card_number_valid + \"' /><input type='hidden' name='exp_date_valid' value='\" + exp_date_valid + \"' /><input type='hidden' name='cvc_valid' value='\" + cvc_valid + \"' /><input type='hidden' name='cardholder_name_valid' value='\" + cardholder_name_valid + \"' />\");" .
						"} else if ( ( ! ( typeof address_line1 === 'undefined' ) ) && ( ( ! ( address_line1.length > 0 ) ) || ( ! ( address_zip.length > 0 ) ) || ( ( ! ( typeof address_state === 'undefined' ) ) && ( ! ( address_state.length > 0 ) ) ) || ( ( ! ( typeof address_country === 'undefined' ) ) && ( ! ( address_country.length > 0 ) ) ) ) ) { " .

						"} else {" .
							"var token = Stripe.createToken({" .
								"number: card_number," .
								"exp_month: exp_month," .
								"exp_year: exp_year," .
								"cvc: cvc," .
								"name: cardholder_name," .
								"address_line1: ( ! ( typeof address_line1 === 'undefined' ) ) ? address_line1 : ''," .
								"address_zip: ( ! ( typeof address_zip === 'undefined' ) ) ? address_zip : ''," .
								"address_state: ( ! ( typeof address_state === 'undefined' ) ) ? address_state : ''," .
								"address_country: ( ! ( typeof address_country === 'undefined' ) ) ? address_country : ''," .
								"}, stripeResponseHandler);" .
							"return false;" .
						"}" .

					"}";
				$form_string = self::inject_gf_stripe( $js, $form_string, $needle );
			}
		}


		return $form_string;
	}

	private static function make_product_payment( $config, $validation_result ) {


		$form = $validation_result[ "form" ];

		self::$log->LogDebug( "Starting to make a product payment for form: {$form["id"]}" );

		$form_data = self::get_form_data( $form, $config );

		//don't process payment if total less than $0.50, but act as if the transaction was successful
		if ( $form_data[ "amount" ] < 0.5 ) {
			self::$log->LogDebug( 'Amount is less than $0.50. No need to process payment, but act as if transaction was successful' );

			//blank out credit card field if this is the last page
			if ( self::is_last_page( $form ) ) {
				$card_field                             = self::get_creditcard_field( $form );
				$_POST[ "input_{$card_field["id"]}_1" ] = '';
			}
			//creating dummy transaction response
			$products = self::get_product_fields( $form );
			if ( ! empty( $products[ "products" ] ) )
				self::$transaction_response = array(
					'transaction_id'   => 'N/A',
					'amount'           => $form_data[ "amount" ],
					'transaction_type' => 1 );

			return $validation_result;
		}

		//create charge
		self::$log->LogDebug( 'Creating the charge' );
		self::include_api();
		Stripe::setApiKey( self::get_api_key( 'secret' ) );
		try {
			$customer = Stripe_Customer::create( array(
																					 	'description'	=> $form_data[ 'name' ],
																					 	'card' 				=> $form_data[ 'credit_card' ]
																					 ) );
		}
		catch( Exception $e ){
			$customer = '';
		}
		try {
			if ( ! empty( $customer ) ) {
				$response = Stripe_Charge::create( array(
																						'amount'      => ( $form_data[ 'amount' ] * 100 ),
																						'currency'    => 'usd',
																						'customer'    => $customer,
																						'description' => implode( '\n', $form_data[ 'line_items' ] )
																					) );
			}
			else {
				$response = Stripe_Charge::create( array(
																							'amount'      => ( $form_data[ 'amount' ] * 100 ),
																							'currency'    => 'usd',
																							'card'        => $form_data[ 'credit_card' ],
																							'description' => ( $form_data[ 'name' ] . '(' . $form_data[ 'email' ] . ' ): ' . implode( "\n", $form_data[ 'line_items' ] ) )
																				 ) );
			}
			//self::$log->LogDebug(print_r($response, true));
			self::$log->LogDebug( "Charge successful. ID: {$response['id']} - Amount: {$response['amount']}" );

			self::$transaction_response = array(
				'transaction_id'   => $response[ 'id' ],
				'amount'           => $response[ 'amount' ] / 100,
				'transaction_type' => 1 );

			$validation_result[ "is_valid" ] = true;
			return $validation_result;
		}
		catch ( Exception $e ) {
			self::$log->LogError( 'Charge failed' );
			$error_class   = get_class( $e );
			$error_message = $e->getMessage();
			$response      = $error_class . ': ' . $error_message;
			self::$log->LogError( print_r( $response, true ) );

			// Payment for single transaction was not successful
			return self::set_validation_result( $validation_result, $_POST, $error_message );
		}

	}

	private static function start_subscription( $config, $validation_result ) {

			$form = $validation_result[ 'form' ];

			self::$log->LogDebug( "Starting subscription for form: {$form['id']}" );

			$form_data      = self::get_form_data( $form, $config );
			if ( isset( $form_data[ 'plan' ] ) ) {

				self::$log->LogDebug( "Sending create subscription request" );

				self::include_api();
				Stripe::setApiKey( self::get_api_key( 'secret' ) );
				try {
					$response = Stripe_Customer::create( array(
																									'card'        => $form_data[ 'credit_card' ],
																									'email' 			=> $form_data[ 'email' ],
																									'description' => $form_data[ 'name' ],
																									'plan' 				=> $form_data[ 'plan' ]
																						 ) );
				}
				catch ( Exception $e ) {
					self::$log->LogError( 'Subscription failed' );
					$error_class   = get_class( $e );
					$error_message = $e->getMessage();
					$response      = $error_class . ': ' . $error_message;
					self::$log->LogError( print_r( $response, true ) );

					// Payment for single transaction was not successful
					return self::set_validation_result( $validation_result, $_POST, $error_message );
				}

				$subscription = array(
													'customer_id' 	=> $response[ 'id' ],
													'customer_email'=> $response[ 'email' ],
													'customer_name' => $response->active_card[ 'name' ],
													'payment_method'=> array(
																							'type' 	=> $response->active_card[ 'type' ],
																							'last4' => $response->active_card[ 'last4' ]
																							),
													'status' 				=> $response->subscription[ 'status' ],
													'start' 				=> gmdate( 'Y-m-d H:i:s', $response->subscription[ 'current_period_start' ] ),
													'end' 					=> gmdate( 'Y-m-d H:i:s', $response->subscription[ 'current_period_end' ] ),
													'next_payment' 	=> array(
																							'amount'	=> $response->next_recurring_charge[ 'amount' ] / 100,
																							'date' 		=> $response->next_recurring_charge[ 'date' ]
																							),
													'plan' 					=> array(
																							'id' 			=> $response->subscription->plan[ 'id' ],
																							'amount' 	=> $response->subscription->plan[ 'amount' ] / 100,
																							'period' 	=> $response->subscription->plan[ 'interval' ],
																							'name' 		=> $response->subscription->plan[ 'name' ]
																							)
						);

					//self::$log->LogDebug(print_r($response, true));
					self::$log->LogDebug( "Subscription created successfully. ID: {$subscription[ 'customer_id' ]} - Amount: {$subscription[ 'plan' ][ 'amount' ]}" );

					try{
						$last_invoice = Stripe_Invoice::all( array(
																											'customer' => $subscription[ 'customer_id' ],
																											'count' => 1
																								 )
						);
					}
					catch( Exception $e ) {
						$last_invoice = '';
					}


					try{
						$upcoming_invoice = Stripe_Invoice::upcoming( array( 'customer' => $subscription[ 'customer_id' ] ) );
					}
					catch( Exception $e ){
						$upcoming_invoice = '';
					}

					$subscription[ 'next_payment' ][ 'invoice_id' ] = empty( $upcoming_invoice ) ? $upcoming_invoice : $upcoming_invoice[ 'date' ];

					self::$transaction_response = array(
																					'transaction_id'   => empty( $last_invoice ) ? $subscription[ 'customer_id' ] : $last_invoice[ 'data' ][ 0 ][ 'id' ],
																					'amount'           => $subscription[ 'plan' ][ 'amount' ],
																					'transaction_type' => 2,
																					'subscription'   => $subscription
																				);

					$validation_result[ 'is_valid' ] = true;
					return $validation_result;


			}
			else {
				self::$log->LogError( 'Unable to send create subscription request.' );
				$error_message = 'This form cannot process subscription payments. Please contact site owner';
				return self::set_validation_result( $validation_result, $_POST, $error_message );
			}

		}

	private static function get_form_data( $form, $config ) {

		// get products
		$products  = self::get_product_fields( $form );
		$form_data = array();

		// getting billing information
		$form_data[ 'form_title' ] 	= $form[ 'title' ];
		$form_data[ 'name' ]      	= rgpost( 'input_' . str_replace( '.', '_', $config[ 'meta' ][ 'customer_fields' ][ 'first_name' ] ) ) . ' ' . rgpost( 'input_' . str_replace( '.', '_', $config[ 'meta' ][ 'customer_fields' ][ 'last_name' ] ) );
		$form_data[ 'email' ]      	= rgpost( 'input_' . str_replace( '.', '_', $config[ 'meta' ][ 'customer_fields' ][ 'email' ] ) );
		$form_data[ 'address1' ]   	= rgpost( 'input_' . str_replace( '.', '_', $config[ 'meta' ][ 'customer_fields' ][ 'address1' ] ) );
		$form_data[ 'address2' ]   	= rgpost( 'input_' . str_replace( '.', '_', $config[ 'meta' ][ 'customer_fields' ][ 'address2' ] ) );
		$form_data[ 'city' ]       	= rgpost( 'input_' . str_replace( '.', '_', $config[ 'meta' ][ 'customer_fields' ][ 'city' ] ) );
		$form_data[ 'state' ]      	= rgpost( 'input_' . str_replace( '.', '_', $config[ 'meta' ][ 'customer_fields' ][ 'state' ] ) );
		$form_data[ 'zip' ]        	= rgpost( 'input_' . str_replace( '.', '_', $config[ 'meta' ][ 'customer_fields' ][ 'zip' ] ) );
		$form_data[ 'country' ]    	= rgpost( 'input_' . str_replace( '.', '_', $config[ 'meta' ][ 'customer_fields' ][ 'country' ] ) );
		$form_data[ "credit_card" ] = rgpost( 'stripeToken' );
		if ( array_key_exists( 'subscription_plan_field', $config[ 'meta' ] ) &&  ( 1 == sizeof( $products[ 'products' ] ) ) && array_key_exists( 'value', $products[ 'products' ][ $config[ 'meta' ][ 'subscription_plan_field' ] ] ) ) {
			$form_data[ 'plan' ] = $products[ 'products' ][ $config[ 'meta' ][ 'subscription_plan_field' ] ][ 'value' ];
		}

		$order_info = self::get_order_info( $products, rgar( $config[ "meta" ], 'subscription_plan_field' ) );

		$form_data[ "line_items" ] = $order_info[ "line_items" ];
		$form_data[ "amount" ]     = $order_info[ "amount" ];

		return $form_data;
	}


	private static function get_order_info( $products, $subscription_plan_field ) {
		$amount     = 0;
		$line_items = array();
		$item       = 1;
		foreach ( $products[ "products" ] as $field_id => $product )
		{
			if ( is_numeric( $subscription_plan_field ) && $subscription_plan_field != $field_id )
			  continue;

			$quantity = $product[ "quantity" ];
			if ( '' == $quantity )
				$quantity = 1;

			$product_total = self::get_product_unit_price( $product );
			$options       = array();

			foreach ( $product[ "options" ] as $option ) {
				$options[ ] = $option[ "option_label" ];
			}

			$amount += $product_total * $quantity;

			$description = '';
			if ( ! empty( $options ) )
				$description = __( 'options: ', 'gravityforms-stripe' ) . ' ' . implode( ', ', $options );

			$line_items[ ] = "(" . $quantity . ")\t" . $product[ "name" ] . "\t" . $description . "\tx\t$" . $product_total;
			$item ++;
		}

		if ( ! empty( $products[ "shipping" ][ "name" ] )  && !is_numeric( $subscription_plan_field ) ) {
			$line_items[ ] = $item . "\t" . $products[ "shipping" ][ "name" ] . "\t" . "1" . "\t" . $products[ "shipping" ][ "price" ];
			$amount += $products[ "shipping" ][ "price" ];
		}

		return array(
			'amount'     => $amount,
			'line_items' => $line_items );
	}

	public static function gform_after_submission( $entry, $form ) {
		$entry_id = rgar( $entry, 'id' );

		if ( ! empty( self::$transaction_response ) ) {
			//Current Currency
			$currency            = GFCommon::get_currency();
			$transaction_id      = self::$transaction_response[ 'transaction_id' ];
			$transaction_type    = self::$transaction_response[ 'transaction_type' ];
			$amount = self::$transaction_response[ 'amount' ];
			$payment_date        = gmdate( 'Y-m-d H:i:s' );
			$entry[ 'currency' ] = $currency;
			if ( '1' == $transaction_type ) {
				$entry[ 'payment_status' ] = 'Approved';
			}
			else {
				$entry[ 'payment_status' ] = 'Active';
			}
			$entry[ 'payment_amount' ]   = $amount;
			$entry[ 'payment_date' ]     = $payment_date;
			$entry[ 'transaction_id' ]   = $transaction_id;
			$entry[ 'transaction_type' ] = $transaction_type;
			$entry[ 'is_fulfilled' ]     = true;

			RGFormsModel::update_lead( $entry );

			//saving feed id
			$config = self::get_config( $form );
			gform_update_meta( $entry_id, 'Stripe_feed_id', $config[ 'id' ] );

			$subscriber_id = '';
			if ( '2' == $transaction_type ) {
				$subscriber_id  = self::$transaction_response[ 'subscription' ][ 'customer_id' ];
				gform_update_meta( $entry[ 'id' ], 'subscription_amount', $amount );
				gform_update_meta( $entry[ 'id' ], 'subscription_payment_count', '1' );
				gform_update_meta( $entry[ 'id' ], 'subscription_payment_date', $payment_date );
				gform_update_meta( $entry[ 'id' ], 'Stripe_subscription', self::$transaction_response[ 'subscription' ] );
			}
			GFStripeData::insert_transaction( $entry[ 'id' ], 'payment', $subscriber_id, $transaction_id, $amount );
		}

	}

	private static function get_product_fields( $form ) {
		$products = array();

		foreach ( $form[ "fields" ] as $field ) {
			$id = $field[ "id" ];

			switch ( $field[ "type" ] ) {

				case 'product' :

					if ( RGFormsModel::is_field_hidden( $form, $field, array() ) )
						continue;

					$product    = array();
					$lead_value = rgpost( "input_{$id}" );

					$quantity_field = GFCommon::get_product_fields_by_type( $form, array( 'quantity' ), $id );
					$quantity       = sizeof( $quantity_field ) > 0 ? rgpost( "input_{$quantity_field[0]["id"]}" ) : 1;

					// if single product, get values from the multiple inputs
					if ( 'singleproduct' == $field[ "inputType" ] ) {

						$product_quantity = sizeof( $quantity_field ) == 0 && ! $field[ "disableQuantity" ] ? rgpost( "input_{$id}_3" ) : $quantity;
						if ( empty( $product_quantity ) )
							continue;

						$product[ "name" ]     = rgpost( "input_{$id}_1" );
						$product[ "price" ]    = GFCommon::to_number( rgpost( "input_{$id}_2" ) );
						$product[ "quantity" ] = $product_quantity;
						$product[ "options" ]  = array();
					}
					// handle hidden products
					else if ( 'hiddenproduct' == $field[ "inputType" ] ) {
						$product[ "name" ]     = $field[ "label" ];
						$product[ "price" ]    = GFCommon::to_number( $field[ "basePrice" ] );
						$product[ "quantity" ] = $quantity;
						$product[ "options" ]  = array();
					}
					// handle user defined price products
					else if ( "price" == $field[ "inputType" ] ) {
						$product[ "name" ]     = $field[ "label" ];
						$product[ "price" ]    = GFCommon::to_number( $lead_value );
						$product[ "quantity" ] = $quantity;
						$product[ "options" ]  = array();
					}
					// handle drop down and radio products
					else if ( ! empty( $lead_value ) ) {

						if ( empty( $quantity ) )
							continue;

						list( $name, $price ) = rgexplode( "|", $lead_value, 2 );

						$product[ "name" ]		= RGFormsModel::get_choice_text( $field, $name );
						$product[ 'value' ]		= $name;
						$product[ "price" ]   = $price;
						$product[ "quantity" ]= $quantity;
						$product[ "options" ] = array();
					}

					if ( ! empty( $product ) ) {
						$options = GFCommon::get_product_fields_by_type( $form, array( 'option' ), $id );
						foreach ( $options as $option ) {

							if ( RGFormsModel::is_field_hidden( $form, $option, array() ) )
								continue;

							$option_label = empty( $option[ "adminLabel" ] ) ? $option[ "label" ] : $option[ "adminLabel" ];
							$option_value = rgpost( "input_{$option["id"]}" );

							if ( is_array( rgar( $option, 'inputs' ) ) ) {
								foreach ( $option[ "inputs" ] as $input ) {
									$input_value = rgpost( "input_" . str_replace( ".", "_", $input[ "id" ] ) );
									$option_info = GFCommon::get_option_info( $input_value, $option, true );
									if ( ! empty( $option_info ) )
										$product[ "options" ][ ] = array(
											'field_label'  => rgar( $option, 'label' ),
											'option_name'  => rgar( $option_info, 'name' ),
											'option_label' => $option_label . ': ' . rgar( $option_info, 'name' ),
											'price'        => GFCommon::to_number( rgar( $option_info, 'price' ) ) );
								}
							}
							else if ( ! empty( $option_value ) ) {
								$option_info             = GFCommon::get_option_info( $option_value, $option, true );
								$product[ "options" ][ ] = array(
									'field_label'  => rgar( $option, 'label' ),
									'option_name'  => rgar( $option_info, 'name' ),
									'option_label' => $option_label . ': ' . rgar( $option_info, 'name' ),
									'price'        => GFCommon::to_number( rgar( $option_info, 'price' ) ) );
							}

						}
					}

					// if product unit price is not greater than zero, skip it
					if ( ! empty( $product ) && self::get_product_unit_price( $product ) > 0 )
						$products[ $id ] = $product;

					break;
			}
		}

		$shipping_field = GFCommon::get_fields_by_type( $form, array( 'shipping' ) );
		$shipping_price = $shipping_name = "";

		if ( ! empty( $shipping_field ) ) {
			$shipping_price = rgpost( "input_{$shipping_field[0]["id"]}" );
			$shipping_name  = $shipping_field[ 0 ][ "label" ];
			if ( $shipping_field[ 0 ][ "inputType" ] != 'singleshipping' ) {
				list( $shipping_method, $shipping_price ) = rgexplode( "|", $shipping_price, 2 );
				$shipping_name = $shipping_field[ 0 ][ "label" ] . " ({$shipping_method})";
			}
		}
		$shipping_price = GFCommon::to_number( $shipping_price );

		$product_info = array(
			'products' => $products,
			'shipping' => array(
				'name'  => $shipping_name,
				'price' => $shipping_price ) );
		$product_info = apply_filters( "gform_product_info_{$form["id"]}", apply_filters( 'gform_product_info', $product_info, $form, array() ), $form, array() );

		return $product_info;
	}

	public static function get_product_unit_price( $product ) {

		$product_total = $product[ "price" ];

		foreach ( $product[ "options" ] as $option ) {
			$options[ ] = $option[ "option_label" ];
			$product_total += $option[ "price" ];
		}

		return $product_total;
	}

	private static function set_validation_result( $validation_result, $post, $error_message ) {

		$credit_card_page = 0;
		foreach ( $validation_result[ "form" ][ "fields" ] as &$field )
		{
			if ( 'creditcard' == $field[ "type" ] ) {
				$field[ "failed_validation" ]  = true;
				$field[ "validation_message" ] = $error_message;
				$credit_card_page              = $field[ "pageNumber" ];
				break;
			}

		}
		$validation_result[ "is_valid" ] = false;

		GFFormDisplay::set_current_page( $validation_result[ "form" ][ "id" ], $credit_card_page );

		return $validation_result;
	}

	public static function parse_request() {
		//Ignore requests that are not Stripe
		if( RGForms::get( 'page' ) != 'gf_stripe_listener' )
		 return;

		if( ! isset( self::$log ) )
		 self::$log = self::create_logger();

		self::$log->LogDebug( 'Stripe webhook received. Starting to process...' );
		self::$log->LogDebug( print_r( $_POST, true ) );

		self::include_api();
		Stripe::setApiKey( self::get_api_key( 'secret' ) );

		$body = @file_get_contents( 'php://input' );
		$stripe_event = json_decode( $body, true );

		//Verify webhook came from Stripe
		self::$log->LogDebug( 'Sending webhook ID request to Stripe for validation.' );

			//Request event information from Stripe to validate
				try {
					$stripe_event = Stripe_Event::retrieve( $stripe_event[ 'id' ] );
				}
				catch( Exception $e ) {
					self::$log->LogError( 'Webhook could not be verified by Stripe. Aborting.' );
					return;
				}

		self::$log->LogDebug( 'Webhook successfully verified by Stripe' );

		//HTTP status code 200 should automatically return to Stripe

		if ( 'customer.subscription.deleted' == $stripe_event[ 'type' ] ) {
			$subscription = $stripe_event[ 'data' ]->subscription;
			$customer_id = $subscription[ 'customer' ];

			//Get all Stripe subscriptions where ['customer_id'] == $customer_id
			global $wpdb;
			$lead_meta_table = RGFormsModel::get_lead_meta_table_name();
			$sql = "SELECT lead_id, meta_value
							FROM {$lead_meta_table}
							WHERE meta_key = 'Stripe_subscription'";
			$results = $wpdb->get_results( $sql, ARRAY_A );
			$matches = array();
			foreach ( $results as $result ) {
				if ( $customer_id == $result[ 'meta_value' ][ 'customer_id' ] ) {
					$matches = array( 'lead_id' => $result[ 'lead_id' ] );
				}
			}

			if ( ( ! empty ( $matches ) ) && count( $matches ) < 2 ) {
				//update payment_status to canceled
				$entry_id = $matches[0][ 'lead_id' ];
				$entry = RGFormsModel::get_lead( $entry_id );
				self::$log->LogDebug("Entry has been found." . print_r($entry, true));

				//Pre Stripe webhook processing filter. Allows users to cancel webhook processing
				$cancel = apply_filters( 'gform_stripe_pre_webhook', false, $entry );

				if( ! $cancel ) {
					self::$log->LogDebug( 'Setting payment status...' );
					self::cancel_subscription( $entry );
					$user_id   = 0;
					$user_name = 'System';
					RGFormsModel::add_note( $entry_id, $user_id, $user_name, sprintf( __( "Subscription has been canceled. Customer Id: %s", "gravityforms" ), $customer_id ) );
				}
				else {
					self::$log->LogDebug( 'Stripe webhook processing cancelled by the gform_stripe_pre_webhook filter. Aborting.' );
				}

				self::$log->LogDebug( 'Before gform_stripe_post_webhook.' );
				//Post Stripe webhook processing action
				do_action( 'gform_stripe_post_webhook', $entry, $cancel );

				self::$log->LogDebug( 'Stripe webhook processing complete.' );

			}
		}
	}

	public static function gform_entry_info( $form_id, $lead ) {

		// adding cancel subscription button and script to entry info section
		$lead_id          = $lead[ "id" ];
		$payment_status   = $lead[ "payment_status" ];
		$transaction_type = $lead[ "transaction_type" ];
		$cancelsub_button = '';
		if ( 2 == $transaction_type && $payment_status <> 'Canceled' ) {
			$cancelsub_button .= '<input id="cancelsub" type="button" name="cancelsub" value="' . __( 'Cancel Subscription', 'gravityforms-stripe' ) . '" class="button" onclick=" if( confirm(\'' . __( "Warning! This Stripe Subscription will be canceled. This cannot be undone. \'OK\' to cancel subscription, \'Cancel\' to stop", 'gravityforms-stripe' ) . '\')){cancel_stripe_subscription();};"/>';

			$cancelsub_button .= '<img src="' . self::get_base_url() . '/images/loading.gif" id="stripe_wait" style="display: none;"/>';

			$cancelsub_button .= '<script type="text/javascript">
                function cancel_stripe_subscription(){
                    jQuery("#stripe_wait").show();
                    jQuery("#cancelsub").attr("disabled", true);
                    var lead_id = ' . $lead_id . '
                    jQuery.post(ajaxurl, {
                            action:"gf_cancel_stripe_subscription",
                            leadid:lead_id,
                            gf_cancel_subscription: "' . wp_create_nonce( 'gf_cancel_subscription' ) . '"},
                            function(response){

                                jQuery("#stripe_wait").hide();

                                if(response == "1")
                                {
                                    jQuery("#gform_payment_status").html("' . __( "Canceled", "gravityforms-stripe" ) . '");
                                    jQuery("#cancelsub").hide();
                                }
                                else
                                {
                                    jQuery("#cancelsub").attr("disabled", false);
                                    alert("' . __( "The subscription could not be canceled. Please try again later." ) . '");
                                }
                            }
                            );
                }
            </script>';

			echo $cancelsub_button;
		}
	}

	public static function cancel_stripe_subscription() {
		check_ajax_referer( "gf_cancel_subscription", "gf_cancel_subscription" );

		$lead_id = $_POST[ 'leadid' ];
		$lead    = RGFormsModel::get_lead( $lead_id );
		$subscription = gform_get_meta( $lead_id, 'Stripe_subscription' );

		self::include_api();
		Stripe::setApiKey( self::get_api_key( 'secret' ) );

		// cancel the subscription
		try {
			$customer = Stripe_Customer::retrieve( $subscription[ 'customer_id' ] );
		}
		catch( Exception $e ){
			die( '0' );
		}

		try {
			$canceled_subscription = $customer->cancelSubscription( array( 'at_period_end' => true ) );
			//$canceled_subscription = $customer->cancelSubscription();	//for testing
		}
		catch( Exception $e ) {
			die( '0' );
		}

		if ( true == $canceled_subscription[ 'cancel_at_period_end' ] ) {
		//if ( 'canceled' == $canceled_subscription[ 'status' ] ) {	//for testing
					self::cancel_subscription( $lead );
					die( '1' );
		}
		else {
			die( '0' );
		}

	}

	private static function cancel_subscription( $lead ) {

		$lead[ "payment_status" ] = "Canceled";
		RGFormsModel::update_lead( $lead );

		//loading data class
		$feed_id = gform_get_meta( $lead[ "id" ], "stripe_feed_id" );

		require_once( GRAVITYFORMS_STRIPE_PATH . "/data.php" );
		$config = GFStripeData::get_feed( $feed_id );
		if ( ! $config )
			return;

		//1- delete post or mark it as a draft based on configuration
		if ( "draft" == rgars( $config, "meta/update_post_action" ) && ! rgempty( "post_id", $lead ) ) {
			$post              = get_post( $lead[ "post_id" ] );
			$post->post_status = 'draft';
			wp_update_post( $post );
		}
		else if ( "delete" == rgars( $config, "meta/update_post_action" ) && ! rgempty( "post_id", $lead ) ) {
			wp_delete_post( $lead[ "post_id" ] );
		}

		//2- call subscription canceled hook
		do_action( "gform_subscription_canceled", $lead, $config, $lead[ "transaction_id" ], "stripe" );

	}

	public static function delete_stripe_meta() {
		// delete lead meta data
		global $wpdb;
		$table_name = RGFormsModel::get_lead_meta_table_name();
		$wpdb->query( $wpdb->prepare( "DELETE FROM $table_name WHERE meta_key in ( 'subscription_amount', 'subscription_payment_count', 'subscription_payment_date', 'Stripe_subscription' )" ) );

	}

	public static function uninstall() {
		//loading data lib
		require_once( GRAVITYFORMS_STRIPE_PATH . '/data.php' );

		if ( ! GFStripe::has_access( 'gravityforms_stripe_uninstall' ) )
			die( __( 'You don\'t have adequate permission to uninstall the Stripe Add-On.', 'gravityforms-stripe' ) );

		//dropping all tables
		GFStripeData::drop_tables();

		//removing options
		delete_option( 'gf_stripe_site_name' );
		delete_option( 'gf_stripe_auth_token' );
		delete_option( 'gf_stripe_version' );
		delete_option( 'gf_stripe_settings' );

		//delete lead meta data
		self::delete_stripe_meta();

		//Deactivating plugin
		$plugin = self::get_base_url() . '/stripe.php';
		deactivate_plugins( $plugin );
		update_option( 'recently_activated', array( $plugin => time() ) + (array) get_option( 'recently_activated' ) );
	}

	private static function is_gravityforms_installed() {
		return class_exists( 'RGForms' );
	}

	private static function is_gravityforms_supported() {
		if ( class_exists( 'GFCommon' ) ) {
			$is_correct_version = version_compare( GFCommon::$version, self::$min_gravityforms_version, '>=' );
			return $is_correct_version;
		}
		else {
			return false;
		}
	}

	protected static function has_access( $required_permission ) {
		$has_members_plugin = function_exists( 'members_get_capabilities' );
		$has_access         = $has_members_plugin ? current_user_can( $required_permission ) : current_user_can( 'level_7' );
		if ( $has_access )
			return $has_members_plugin ? $required_permission : 'level_7';
		else
			return false;
	}

	private static function get_customer_information( $form, $config = null ) {

		//getting list of all fields for the selected form
		$form_fields = self::get_form_fields( $form );

		$str             = "<table cellpadding='0' cellspacing='0'><tr><td class='stripe_col_heading'>" . __( 'Stripe Fields', 'gravityforms-stripe' ) . "</td><td class='stripe_col_heading'>" . __( 'Form Fields', 'gravityforms-stripe' ) . '</td></tr>';
		$customer_fields = self::get_customer_fields();
		foreach ( $customer_fields as $field ) {
			$selected_field = $config ? $config[ "meta" ][ "customer_fields" ][ $field[ "name" ] ] : "";
			$str .= "<tr><td class='stripe_field_cell'>" . $field[ "label" ] . "</td><td class='stripe_field_cell'>" . self::get_mapped_field_list( $field[ "name" ], $selected_field, $form_fields ) . '</td></tr>';
		}
		$str .= '</table>';

		return $str;
	}

	private static function get_customer_fields() {
		return
				array(
					array(
						'name'  => 'first_name',
						'label' => __( 'First Name', 'gravityforms-stripe' ) ),
					array(
						'name'  => 'last_name',
						'label' => __( 'Last Name', 'gravityforms-stripe' ) ),
					array(
						'name'  => 'email',
						'label' => __( 'Email', 'gravityforms-stripe' ) ),
					array(
					'name'  => 'address1',
					'label' => __( 'Address', 'gravityforms-stripe' ) ),
					array(
					'name'  => 'address2',
					'label' => __( 'Address 2', 'gravityforms-stripe' ) ),
					array(
						'name'  => 'city',
						'label' => __( 'City', 'gravityforms-stripe' ) ),
					array(
					'name'  => 'state',
					'label' => __( 'State', 'gravityforms-stripe' ) ),
					array(
					'name'  => 'zip',
					'label' => __( 'Zip', 'gravityforms-stripe' ) ),
					array(
						'name'  => 'country',
						'label' => __( 'Country', 'gravityforms-stripe' ) ) );
	}

	private static function get_mapped_field_list( $variable_name, $selected_field, $fields ) {
		$field_name = 'stripe_customer_field_' . $variable_name;
		$str        = "<select name='$field_name' id='$field_name'><option value=''></option>";
		foreach ( $fields as $field ) {
			$field_id    = $field[ 0 ];
			$field_label = esc_html( GFCommon::truncate_middle( $field[ 1 ], 40 ) );

			$selected = $field_id == $selected_field ? "selected='selected'" : "";
			$str .= "<option value='" . $field_id . "' " . $selected . ">" . $field_label . '</option>';
		}
		$str .= '</select>';
		return $str;
	}


	private static function get_plan_options( $form, $selected_field ) {
			$str    = "<option value=''>" . __( 'Select a field', 'gravityforms-stripe' ) . '</option>';
			$fields = GFCommon::get_fields_by_type( $form, array( 'product' ) );
			foreach ( $fields as $field ) {
					$field_id    = $field[ "id" ];
					$field_label = RGFormsModel::get_label( $field );

					$selected = $field_id == $selected_field ? "selected='selected'" : "";
					$str .= "<option value='" . $field_id . "' " . $selected . ">" . $field_label . '</option>';
			}

			return $str;
	}

	private static function get_form_fields( $form ) {
		$fields = array();

		if ( is_array( $form[ "fields" ] ) ) {
			foreach ( $form[ "fields" ] as $field ) {
				if ( is_array( rgar( $field, 'inputs' ) ) ) {

					foreach ( $field[ "inputs" ] as $input )
						$fields[ ] = array( $input[ "id" ], GFCommon::get_label( $field, $input[ "id" ] ) );
				}
				else if ( ! rgar( $field, 'displayOnly' ) ) {
					$fields[ ] = array( $field[ "id" ], GFCommon::get_label( $field ) );
				}
			}
		}
		return $fields;
	}

	private static function is_stripe_page() {
		$current_page = trim( strtolower( RGForms::get( 'page' ) ) );
		return in_array( $current_page, array( 'gf_stripe' ) );
	}

	//Returns the url of the plugin's root folder
	private static function get_base_url() {
		return plugins_url( null, GRAVITYFORMS_STRIPE_FILE );
	}

	//Returns the physical path of the plugin's root folder
	private static function get_base_path() {
		$folder = basename( dirname( __FILE__ ) );
		return WP_PLUGIN_DIR . '/' . $folder;
	}
}


if ( ! function_exists( 'rgget' ) ) {
	function rgget( $name, $array = null ) {
		if ( ! isset( $array ) )
			$array = $_GET;

		if ( isset( $array[ $name ] ) )
			return $array[ $name ];

		return "";
	}
}

if ( ! function_exists( 'rgpost' ) ) {
	function rgpost( $name, $do_stripslashes = true ) {
		if ( isset( $_POST[ $name ] ) )
			return $do_stripslashes ? stripslashes_deep( $_POST[ $name ] ) : $_POST[ $name ];

		return '';
	}
}

if ( ! function_exists( 'rgar' ) ) {
	function rgar( $array, $name ) {
		if ( isset( $array[ $name ] ) )
			return $array[ $name ];

		return '';
	}
}

if ( ! function_exists( 'rgars' ) ) {
	function rgars( $array, $name ) {
		$names = explode( '/', $name );
		$val   = $array;
		foreach ( $names as $current_name ) {
			$val = rgar( $val, $current_name );
		}
		return $val;
	}
}

if ( ! function_exists( 'rgempty' ) ) {
	function rgempty( $name, $array = null ) {
		if ( ! $array )
			$array = $_POST;

		$val = rgget( $name, $array );
		return empty( $val );
	}
}


if ( ! function_exists( 'rgblank' ) ) {
	function rgblank( $text ) {
		return empty( $text ) && strval( $text ) != '0';
	}
}

?>