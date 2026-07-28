<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orchestrateur principal : câble les settings, la REST API et le shortcode.
 */
class Lion_RDV_Plugin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this, 'maybe_upgrade_db' ) );

		new Lion_RDV_Settings();
		new Lion_RDV_Rest_Controller();
		new Lion_RDV_Shortcode();
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'lion-rdv-booking', false, dirname( plugin_basename( LION_RDV_PLUGIN_FILE ) ) . '/languages' );
	}

	public function maybe_upgrade_db() {
		if ( get_option( 'lion_rdv_db_version' ) !== LION_RDV_DB_VERSION ) {
			Lion_RDV_DB::activate();
		}
	}
}
