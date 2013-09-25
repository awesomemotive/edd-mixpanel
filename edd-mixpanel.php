<?php
/**
 * Plugin Name: Easy Digital Downloads - Mixpanel Tracking
 * Description: Tracks Easy Digital Downloads data through Mixpanel
 * Author: Pippin Williamson
 * Author URI: http://pippinsplugins.com
 * Version: 1.0
 */


if( ! class_exists( 'Mixpanel' ) ) {
	require dirname( __FILE__ ) . '/mixpanel/lib/Mixpanel.php';
}

final class EDD_Mixpanel {

	private $track = false;

	private $api;

	function __construct() {

		if( ! function_exists( 'EDD' ) )
			return;

		// Track customers landing on the checkout page
		add_action( 'template_redirect', array( $this, 'track_checkout_loaded' ) );

		// Track when customers add items to the cart
		add_action( 'edd_post_add_to_cart', array( $this, 'track_added_to_cart' ) );

		// Track completed purchases
		add_action( 'edd_update_payment_status', array( $this, 'track_purchase' ), 100, 3 );

		// register our settings
		add_filter( 'edd_settings_general', array( $this, 'settings' ), 1 );

	}

	private function set_token() {
		global $edd_options;

		$token = isset( $edd_options['edd_mixpanel_api_key'] ) ? trim( $edd_options['edd_mixpanel_api_key'] ) : false;

		$this->api = Mixpanel::getInstance( $token );

		if( ! empty( $token ) )
			$this->track = true;
	}

	public function track_added_to_cart( $download_id = 0, $options = array() ) {

		$this->set_token();

		if( ! $this->track )
			return;

		if( is_user_logged_in() ) {

			$person_props       = array();
			$person_props['ip'] = edd_get_ip();
			$this->api->people->set( get_current_user_id(), $person_props );
		}

		$event_props = array();

		if( is_user_logged_in() )
			$event_props['distinct_id'] = get_current_user_id();

		$event_props['ip']           = edd_get_ip();
		$event_props['session_id']   = EDD()->session->get_id();
		$event_props['product_name'] = get_the_title( $download_id );
		$event_props['product_price']= edd_get_cart_item_price( $download_id, $options );
		if( function_exists( 'rcp_get_subscription' ) && is_user_logged_in() ) {
			$event_props['subscription'] = rcp_get_subscription( get_current_user_id() );
		}

		$this->api->track( 'EDD Added to Cart', $event_props );
	}

	public function track_checkout_loaded() {

		// Only track the checkout page when the cart is not empty
		if( ! edd_is_checkout() || ! edd_get_cart_contents() )
			return;

		$this->set_token();

		if( ! $this->track )
			return;

		if( is_user_logged_in() ) {

			$person_props       = array();
			$person_props['ip'] = edd_get_ip();

			$this->api->people->set( get_current_user_id(), $person_props );
		}

		$event_props = array();

		if( is_user_logged_in() )
			$event_props['distinct_id'] = get_current_user_id();

		$event_props['ip']         = edd_get_ip();
		$event_props['session_id'] = EDD()->session->get_id();

		$products = array();
		foreach( edd_get_cart_contents() as $download ) {
			$products[] = get_the_title( $download['id'] );
		}
		$event_props['products']   = implode( ', ', $products );
		$event_props['cart_count'] = edd_get_cart_quantity();
		$event_props['cart_sum']   = edd_get_cart_subtotal();
		if( function_exists( 'rcp_get_subscription' ) && is_user_logged_in() ) {
			$event_props['subscription'] = rcp_get_subscription( get_current_user_id() );
		}

		$this->api->track( 'EDD Checkout Loaded', $event_props );

	}


	public function track_purchase( $payment_id, $new_status, $old_status ) {

		$this->set_token();

		if( ! $this->track )
			return;

		if ( $old_status == 'publish' || $old_status == 'complete' )
			return; // Make sure that payments are only completed once

		// Make sure the payment completion is only processed when new status is complete
		if ( $new_status != 'publish' && $new_status != 'complete' )
			return;

		$user_info = edd_get_payment_meta_user_info( $payment_id );
		$user_id   = edd_get_payment_user_id( $payment_id );
		$downloads = edd_get_payment_meta_cart_details( $payment_id );
		$amount    = edd_get_payment_amount( $payment_id );

		if( $user_id <= 0 ) {
			$distinct = $user_info['email'];
		} else {
			$distinct = $user_id;
		}

		$person_props                  = array();
		$person_props['$first_name']   = $user_info['first_name'];
		$person_props['$last_name']    = $user_info['last_name'];
		$person_props['$email']        = $user_info['email'];
		$person_props['ip']            = edd_get_ip();

		$this->api->people->set( $distinct, $person_props );

		$event_props                  = array();
		$event_props['distinct_id']   = $distinct;
		$event_props['amount']        = $amount;
		$event_props['session_id']    = EDD()->session->get_id();
		$event_props['purchase_date'] = strtotime( get_post_field( 'post_date', $payment_id ) );
		$event_props['cart_count']    = edd_get_cart_quantity();

		$products = array();
		foreach( $downloads as $download ) {
			$products[] = get_the_title( $download['id'] );
		}
		$event_props['products'] = implode( ', ', $products );

		$this->api->track( 'EDD Sale', $event_props );

		$this->api->people->trackCharge( $distinct, $amount );
	}

	/**
	 * Add our extension settings
	 *
	 * @since 1.0
	 *
	 * @access public
	 * @return array
	 */
	public function settings( $settings ) {
		$mixpanel_settings = array(
			array(
				'id' => 'edd_mixpanel_heading',
				'name' => '<strong>' . __( 'Mixpanel', 'edd' ) . '</strong>',
				'desc' => '',
				'type' => 'header',
				'size' => 'regular'
			),
			array(
				'id' => 'edd_mixpanel_api_key',
				'name' => __( 'Project Token', 'edd' ),
				'desc' => __( 'Enter the Token for the Mixpanel Project you want to track data for.', 'edd' ),
				'type'  => 'text',
				'size'  => 'regular'
			)
		);

		return array_merge( $settings, $mixpanel_settings );
	}
}

function edd_mixpanel_instantiate() {
	$mixpanel = new EDD_Mixpanel;
}
add_action( 'plugins_loaded', 'edd_mixpanel_instantiate' );