<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Page de réglages (Réglages > Prise de RDV Lion) et accès centralisé aux options.
 */
class Lion_RDV_Settings {

	const OPTION_KEY = 'lion_rdv_settings';

	public static $days = array(
		'lundi'     => 1,
		'mardi'     => 2,
		'mercredi'  => 3,
		'jeudi'     => 4,
		'vendredi'  => 5,
		'samedi'    => 6,
		'dimanche'  => 7,
	);

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_lion_rdv_test_connection', array( $this, 'handle_test_connection' ) );
	}

	public static function default_settings() {
		$default_hours = array();
		foreach ( array_keys( self::$days ) as $day ) {
			$is_open                 = 'dimanche' !== $day;
			$default_hours[ $day ] = array(
				'open'             => $is_open,
				'matin_debut'      => '08:00',
				'matin_fin'        => '12:00',
				'apres_midi_debut' => 'samedi' === $day ? '' : '14:00',
				'apres_midi_fin'   => 'samedi' === $day ? '' : '18:00',
			);
		}

		return array(
			'interfast_api_key'      => '',
			'interfast_api_base_url' => 'https://api.inter-fast.fr/v1',
			'interfast_resource_id'  => '',
			'duration_depannage'     => 60,
			'duration_entretien'     => 90,
			'lead_time_depannage'    => 4,
			'lead_time_entretien'    => 24,
			'horizon_days'           => 30,
			'slot_step_minutes'      => 30,
			'notification_email'     => get_option( 'admin_email' ),
			'hours'                  => $default_hours,
		);
	}

	public static function get_settings() {
		$settings = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( $settings, self::default_settings() );
	}

	public function add_menu() {
		add_options_page(
			__( 'Prise de RDV Lion', 'lion-rdv-booking' ),
			__( 'Prise de RDV Lion', 'lion-rdv-booking' ),
			'manage_options',
			'lion-rdv-booking',
			array( $this, 'render_settings_page' )
		);

		add_submenu_page(
			null,
			__( 'Réservations - Prise de RDV Lion', 'lion-rdv-booking' ),
			__( 'Réservations', 'lion-rdv-booking' ),
			'manage_options',
			'lion-rdv-bookings',
			array( $this, 'render_bookings_page' )
		);
	}

	public function register_settings() {
		register_setting(
			'lion_rdv_settings_group',
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => self::default_settings(),
			)
		);
	}

	public function sanitize_settings( $input ) {
		$defaults = self::default_settings();
		$clean    = array();

		$clean['interfast_api_key']      = isset( $input['interfast_api_key'] ) ? sanitize_text_field( $input['interfast_api_key'] ) : '';
		$clean['interfast_api_base_url'] = isset( $input['interfast_api_base_url'] ) ? esc_url_raw( trim( $input['interfast_api_base_url'] ) ) : $defaults['interfast_api_base_url'];
		$clean['interfast_resource_id']  = isset( $input['interfast_resource_id'] ) ? sanitize_text_field( $input['interfast_resource_id'] ) : '';

		$clean['duration_depannage']  = max( 15, (int) ( $input['duration_depannage'] ?? $defaults['duration_depannage'] ) );
		$clean['duration_entretien']  = max( 15, (int) ( $input['duration_entretien'] ?? $defaults['duration_entretien'] ) );
		$clean['lead_time_depannage'] = max( 0, (int) ( $input['lead_time_depannage'] ?? $defaults['lead_time_depannage'] ) );
		$clean['lead_time_entretien'] = max( 0, (int) ( $input['lead_time_entretien'] ?? $defaults['lead_time_entretien'] ) );
		$clean['horizon_days']        = max( 1, min( 180, (int) ( $input['horizon_days'] ?? $defaults['horizon_days'] ) ) );
		$clean['slot_step_minutes']   = max( 5, (int) ( $input['slot_step_minutes'] ?? $defaults['slot_step_minutes'] ) );

		$email                        = isset( $input['notification_email'] ) ? sanitize_email( $input['notification_email'] ) : '';
		$clean['notification_email']  = $email ? $email : $defaults['notification_email'];

		$clean['hours'] = array();
		foreach ( array_keys( self::$days ) as $day ) {
			$day_input = $input['hours'][ $day ] ?? array();
			$clean['hours'][ $day ] = array(
				'open'             => ! empty( $day_input['open'] ),
				'matin_debut'      => $this->sanitize_time( $day_input['matin_debut'] ?? '' ),
				'matin_fin'        => $this->sanitize_time( $day_input['matin_fin'] ?? '' ),
				'apres_midi_debut' => $this->sanitize_time( $day_input['apres_midi_debut'] ?? '' ),
				'apres_midi_fin'   => $this->sanitize_time( $day_input['apres_midi_fin'] ?? '' ),
			);
		}

		return $clean;
	}

	private function sanitize_time( $value ) {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $value ) ? $value : '';
	}

	public function handle_test_connection() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'lion_rdv_test_connection' ) ) {
			wp_die( esc_html__( 'Action non autorisée.', 'lion-rdv-booking' ) );
		}

		$client = new Lion_RDV_Interfast_Client();
		$result = $client->test_connection();

		set_transient( 'lion_rdv_test_connection_result', $result, MINUTE_IN_SECONDS * 5 );

		wp_safe_redirect( add_query_arg( array( 'page' => 'lion-rdv-booking', 'lion_rdv_tested' => '1' ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	public function render_settings_page() {
		require LION_RDV_PLUGIN_DIR . 'includes/views/settings-page.php';
	}

	public function render_bookings_page() {
		require LION_RDV_PLUGIN_DIR . 'includes/views/bookings-page.php';
	}
}
