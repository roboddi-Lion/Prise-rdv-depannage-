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
			'interfast_api_base_url' => 'https://app.inter-fast.fr',
			'horizon_days'           => 30,
			'slot_step_minutes'      => 30,
			'notification_email'     => get_option( 'admin_email' ),
			'hours'                  => $default_hours,
			'services'               => self::default_services(),
		);
	}

	/**
	 * Liste des types de rendez-vous proposés dans le widget. Chaque service a
	 * sa propre durée, préavis, priorité, modèle de rapport InterFast
	 * (reportTypeId — obligatoire pour créer une intervention) et liste de
	 * techniciens éligibles (technician_ids — IDs numériques InterFast).
	 *
	 * ⚠️ Un créneau n'est proposé que si AU MOINS UN technicien de la liste
	 * du service est libre ; laisser technician_ids vide bascule ce service
	 * en mode "planning global" (voir Lion_RDV_Availability), à éviter dès
	 * que plusieurs techniciens existent chez vous.
	 *
	 * Pour ajouter un nouveau service, ajoutez une entrée ici avec une clé
	 * unique ; elle apparaîtra automatiquement dans les réglages et le widget.
	 */
	public static function default_services() {
		return array(
			'entretien_clim'            => array(
				'enabled'          => true,
				'label'            => __( 'Entretien climatisation', 'lion-rdv-booking' ),
				'description'      => __( 'Entretien de climatisation', 'lion-rdv-booking' ),
				'duration_minutes' => 90,
				'lead_time_hours'  => 24,
				'importance_level' => 'normal',
				// "Entretien de climatisation"
				'report_type_id'   => '31817e50-c605-4fd9-ac93-c47ba7a0a8bc',
				'technician_ids'   => array(),
			),
			'entretien_pac'             => array(
				'enabled'          => true,
				'label'            => __( 'Entretien PAC', 'lion-rdv-booking' ),
				'description'      => __( 'Entretien de pompe à chaleur', 'lion-rdv-booking' ),
				'duration_minutes' => 90,
				'lead_time_hours'  => 24,
				'importance_level' => 'normal',
				// "Entretien de PAC"
				'report_type_id'   => 'e6783f26-a4b0-4f5b-845b-0e7622ca66ae',
				'technician_ids'   => array(),
			),
			'entretien_chaudiere_gaz'   => array(
				'enabled'          => true,
				'label'            => __( 'Entretien chaudière gaz', 'lion-rdv-booking' ),
				'description'      => __( 'Entretien annuel de chaudière à gaz', 'lion-rdv-booking' ),
				'duration_minutes' => 90,
				'lead_time_hours'  => 24,
				'importance_level' => 'normal',
				// "Entretien de chaudière à gaz"
				'report_type_id'   => 'd6c59ab3-085b-4436-8f1e-602904a62fba',
				'technician_ids'   => array(),
			),
			'entretien_chaudiere_fioul' => array(
				'enabled'          => true,
				'label'            => __( 'Entretien chaudière fioul', 'lion-rdv-booking' ),
				'description'      => __( 'Entretien annuel de chaudière à fioul', 'lion-rdv-booking' ),
				'duration_minutes' => 90,
				'lead_time_hours'  => 24,
				'importance_level' => 'normal',
				// "Entretien chaudière fioul"
				'report_type_id'   => 'fa100fc5-d9a3-452e-b0cf-303a84fbba5f',
				'technician_ids'   => array(),
			),
			'depannage_clim_pac'        => array(
				'enabled'          => true,
				'label'            => __( 'Dépannage climatisation / PAC', 'lion-rdv-booking' ),
				'description'      => __( 'Panne de climatisation ou de pompe à chaleur', 'lion-rdv-booking' ),
				'duration_minutes' => 60,
				'lead_time_hours'  => 4,
				'importance_level' => 'high',
				// Pas de modèle "dépannage clim/PAC" dédié trouvé dans le catalogue :
				// "Dépannage" générique par défaut, à ajuster si besoin.
				'report_type_id'   => '8ce790fa-a7d7-413a-a2c9-4d6cc822b93d',
				'technician_ids'   => array(),
			),
			'depannage_chaudiere'       => array(
				'enabled'          => true,
				'label'            => __( 'Dépannage chaudière', 'lion-rdv-booking' ),
				'description'      => __( 'Panne de chaudière', 'lion-rdv-booking' ),
				'duration_minutes' => 60,
				'lead_time_hours'  => 4,
				'importance_level' => 'high',
				// Pas de modèle "dépannage chaudière" dédié trouvé : "Dépannage" générique.
				'report_type_id'   => '8ce790fa-a7d7-413a-a2c9-4d6cc822b93d',
				'technician_ids'   => array(),
			),
			'depannage_plomberie'       => array(
				'enabled'          => true,
				'label'            => __( 'Dépannage plomberie', 'lion-rdv-booking' ),
				'description'      => __( 'Panne de plomberie', 'lion-rdv-booking' ),
				'duration_minutes' => 60,
				'lead_time_hours'  => 4,
				'importance_level' => 'high',
				// Pas de modèle "dépannage plomberie" dédié trouvé : "Dépannage" générique.
				'report_type_id'   => '8ce790fa-a7d7-413a-a2c9-4d6cc822b93d',
				'technician_ids'   => array(),
			),
		);
	}

	public static function get_settings() {
		$settings = get_option( self::OPTION_KEY, array() );
		$settings = wp_parse_args( $settings, self::default_settings() );

		// Fusion clé par clé plutôt qu'un simple wp_parse_args (superficiel) :
		// si un nouveau service est ajouté au code après que l'utilisateur ait
		// déjà enregistré ses réglages, il apparaît quand même avec ses valeurs
		// par défaut au lieu d'être silencieusement absent.
		$saved_services   = is_array( $settings['services'] ?? null ) ? $settings['services'] : array();
		$default_services = self::default_services();
		$merged_services  = array();
		foreach ( $default_services as $key => $default_service ) {
			$merged_services[ $key ] = isset( $saved_services[ $key ] ) && is_array( $saved_services[ $key ] )
				? wp_parse_args( $saved_services[ $key ], $default_service )
				: $default_service;
		}
		$settings['services'] = $merged_services;

		return $settings;
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

	/**
	 * Convertit une liste d'ID techniciens séparés par des virgules (ex. "12, 34, 56")
	 * en tableau d'entiers uniques, sans les valeurs vides/invalides.
	 */
	private function parse_technician_ids( $raw ) {
		return array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( $id ) {
							return (int) trim( $id );
						},
						explode( ',', (string) $raw )
					)
				)
			)
		);
	}

	public function sanitize_settings( $input ) {
		$defaults = self::default_settings();
		$clean    = array();

		$clean['interfast_api_key']      = isset( $input['interfast_api_key'] ) ? sanitize_text_field( $input['interfast_api_key'] ) : '';
		$clean['interfast_api_base_url'] = isset( $input['interfast_api_base_url'] ) ? esc_url_raw( trim( $input['interfast_api_base_url'] ) ) : $defaults['interfast_api_base_url'];

		$clean['horizon_days']       = max( 1, min( 180, (int) ( $input['horizon_days'] ?? $defaults['horizon_days'] ) ) );
		$clean['slot_step_minutes']  = max( 0, (int) ( $input['slot_step_minutes'] ?? $defaults['slot_step_minutes'] ) );

		$email                       = isset( $input['notification_email'] ) ? sanitize_email( $input['notification_email'] ) : '';
		$clean['notification_email'] = $email ? $email : $defaults['notification_email'];

		$clean['services'] = array();
		foreach ( $defaults['services'] as $key => $default_service ) {
			$service_input = $input['services'][ $key ] ?? array();

			$clean['services'][ $key ] = array(
				'enabled'          => ! empty( $service_input['enabled'] ),
				'label'            => isset( $service_input['label'] ) && '' !== trim( $service_input['label'] ) ? sanitize_text_field( $service_input['label'] ) : $default_service['label'],
				'description'      => isset( $service_input['description'] ) ? sanitize_text_field( $service_input['description'] ) : $default_service['description'],
				'duration_minutes' => max( 15, (int) ( $service_input['duration_minutes'] ?? $default_service['duration_minutes'] ) ),
				'lead_time_hours'  => max( 0, (int) ( $service_input['lead_time_hours'] ?? $default_service['lead_time_hours'] ) ),
				'importance_level' => in_array( $service_input['importance_level'] ?? '', array( 'normal', 'high' ), true ) ? $service_input['importance_level'] : $default_service['importance_level'],
				'report_type_id'   => isset( $service_input['report_type_id'] ) ? sanitize_text_field( $service_input['report_type_id'] ) : $default_service['report_type_id'],
				'technician_ids'   => $this->parse_technician_ids( $service_input['technician_ids'] ?? '' ),
			);
		}

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
