<?php
/**
 * Plugin Name: Lion Rénovation - Prise de RDV Dépannage & Entretien
 * Description: Permet aux visiteurs du site de réserver eux-mêmes un créneau de dépannage ou d'entretien, synchronisé avec l'agenda InterFast.
 * Version: 1.0.0
 * Author: Lion Rénovation
 * Text Domain: lion-rdv-booking
 * Requires at least: 5.9
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LION_RDV_VERSION', '1.0.0' );
define( 'LION_RDV_PLUGIN_FILE', __FILE__ );
define( 'LION_RDV_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LION_RDV_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'LION_RDV_DB_VERSION', '1.0.0' );

require_once LION_RDV_PLUGIN_DIR . 'includes/class-lion-rdv-settings.php';
require_once LION_RDV_PLUGIN_DIR . 'includes/class-lion-rdv-db.php';
require_once LION_RDV_PLUGIN_DIR . 'includes/class-lion-rdv-interfast-client.php';
require_once LION_RDV_PLUGIN_DIR . 'includes/class-lion-rdv-availability.php';
require_once LION_RDV_PLUGIN_DIR . 'includes/class-lion-rdv-notifications.php';
require_once LION_RDV_PLUGIN_DIR . 'includes/class-lion-rdv-rest-controller.php';
require_once LION_RDV_PLUGIN_DIR . 'includes/class-lion-rdv-shortcode.php';
require_once LION_RDV_PLUGIN_DIR . 'includes/class-lion-rdv-plugin.php';

register_activation_hook( __FILE__, array( 'Lion_RDV_DB', 'activate' ) );

Lion_RDV_Plugin::instance();
