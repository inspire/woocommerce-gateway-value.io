<?php
/*
Plugin Name: WooCommerce ValueIO Payments Gateway
Plugin URI: http://woothemes.com/woocommerce/
Description: A payment gateway for ValueIO Payments (https://api.value.io/v1). A ValueIO account is required for this gateway to function.
Version: 1.0
Author: Inspire Commerce
Author URI: http://inspirecommerce.com
Text Domain: wc_valueio

ValueIO Docs: https://api.value.io/v1
*/

/**
 * Required functions
 */
if ( ! function_exists( 'is_woocommerce_active' ) ) require_once( 'woo-includes/woo-functions.php' );

/**
 * Plugin updates
 */
if ( is_admin() ) {
  $woo_plugin_updater_ppa = new WooThemes_Plugin_Updater( __FILE__ );
  $woo_plugin_updater_ppa->api_key = '62a2fd885cce6be5ca3086f3d308af22';
  $woo_plugin_updater_ppa->init();
}

add_action( 'woocommerce_loaded', 'woocommerce_valueio_init' ) ;

function woocommerce_valueio_init() {
  global $woocommerce;

  if ( ! class_exists( 'WC_Payment_Gateway' ) ) return;

  DEFINE ('PLUGIN_DIR', plugins_url( basename( plugin_dir_path( __FILE__ ) ), basename( __FILE__ ) ) . '/' );

  require_once( plugin_dir_path( __FILE__ ) . "class-wc-valueio.php" ); //core class
}
