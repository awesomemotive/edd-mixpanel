<?php
/**
 * Plugin Name: Easy Digital Downloads - Mixpanel Tracking
 * Description: Tracks Easy Digital Downloads data through Mixpanel
 * Author: Pippin Williamson
 * Author URI: http://pippinsplugins.com
 * Version: 1.0
 */


final class EDD_Mixpanel {

	private $track = false;

	function __construct() {

		if( ! function_exists( 'wp_mixpanel' ) || ! function_exists( 'EDD' ) )
			return;

		add_action( 'edd_update_payment_status', array( $this, 'track_purchase' ), 100, 3 );

		// register our settings
		add_filter( 'edd_settings_general', array( $this, 'settings' ), 1 );

	}

	private function set_token() {
		global $edd_options;

		$token = isset( $edd_options['edd_mixpanel_api_key'] ) ? trim( $edd_options['edd_mixpanel_api_key'] ) : false;

		wp_mixpanel()->set_api_key( $token );

		if( ! empty( $token ) )
			$this->track = true;
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
		$user_id   = $user_info['id'];
		$downloads = edd_get_payment_meta_cart_details( $payment_id );
		$amount    = edd_get_payment_amount( $payment_id );

		if( $user_id <= 0 ) {
			$distinct = $user_info['email'];
		} else {
			$distinct = $user_id;
		}

		$person_props                 = array();
		$person_props['first_name']   = $user_info['first_name'];
		$person_props['last_name']    = $user_info['last_name'];
		$person_props['email']        = $user_info['email'];
		$person_props['ip']           = edd_get_ip();

		wp_mixpanel()->track_person( $distinct, $person_props );

		$event_props                 = array();
		$event_props['distinct_id']  = $distinct;
		$event_props['amount']       = $amount;

		$products = array();
		foreach( $downloads as $download ) {
			$products[] = get_the_title( $download['id'] );
		}
		$event_props['products'] = implode( ', ', $products );

		wp_mixpanel()->track_event( 'EDD Sale', $event_props );

		$trans_props = array(
			'amount' => $amount
		);
		wp_mixpanel()->track_transaction( $distinct, $trans_props );

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