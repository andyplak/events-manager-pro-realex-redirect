<?php
/*
Plugin Name: Events Manager Pro - RealEx Redirect Gateway
Plugin URI: http://wp-events-plugin.com
Description: RealEx Redirect gateway pluging for Events Manager Pro
Version: 1.2
Depends: Events Manager Pro
Author: Andy Place
Author URI: http://www.andyplace.co.uk
*/

include_once( ABSPATH . 'wp-admin/includes/plugin.php' );

class EM_Pro_Realex_Redirect {

	function EM_Pro_Realex_Redirect() {
		global $wpdb;

		// Some rewite pre-requesits
		add_action('init', array(&$this,'rewrite_init') );

		//Set when to run the plugin : after EM is loaded.
		add_action( 'plugins_loaded', array(&$this,'init'), 100 );

	}

	function init() {
		if( is_plugin_active('events-manager/events-manager.php') && is_plugin_active('events-manager-pro/events-manager-pro.php') ) {
			//add-ons
			include('add-ons/gateways/gateway.realex.redirect.php');
		}else{
			add_action( 'admin_notices', array(&$this,'not_activated_error_notice') );
		}
	}

	/*
	 * Url rewrite to cope with RealEx return limitations in RealEx RealAuth code
	 * Hopefully this will just be temporary while RealEx get their act together
	 * Two issues. URL is limited to 100 chars & url is stripped after first GET var (at first &)
	 */
	function rewrite_init() {
		global $wp, $wp_rewrite;
		$wp->add_query_var('action');
		$wp->add_query_var('em_payment_gateway');
		$wp_rewrite->add_rule('^realex-redirect-return$', '/wp-admin/admin-ajax.php?action=em_payment&em_payment_gateway=realex', 'top');
	}

	/**
	 * Display error message if Events Manager or Events Manager Pro are not active
	 */
	function not_activated_error_notice() {
		$class = "error";
		$message = __('Please ensure both Events Manager and Events Manager Pro are enabled for the Realex Redirect Gateway to work.', 'em-pro');
		echo '<div class="'.$class.'"> <p>'.$message.'</p></div>';
	}
}

// Start plugin
global $EM_Pro_Realex_Redirect;
$EM_Pro_Realex_Redirect = new EM_Pro_Realex_Redirect();