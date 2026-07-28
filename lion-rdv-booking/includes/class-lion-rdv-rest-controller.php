<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Endpoints REST publics utilisés par le widget de prise de RDV.
 */
class Lion_RDV_Rest_Controller {

	const NAMESPACE_ = 'lion-rdv/v1';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_,
			'/creneaux',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_get_slots' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'service' => array(
						'required' => true,
						'type'     => 'string',
						'enum'     => array( 'depannage', 'entretien' ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_,
			'/reserver',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_book_slot' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public function handle_get_slots( WP_REST_Request $request ) {
		$service      = $request->get_param( 'service' );
		$availability = new Lion_RDV_Availability();
		$result       = $availability->get_available_slots( $service );

		if ( ! $result['success'] ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Impossible de récupérer les créneaux disponibles pour le moment. Merci de réessayer plus tard ou de nous appeler directement.', 'lion-rdv-booking' ),
				),
				503
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'days'    => $result['days'],
			),
			200
		);
	}

	public function handle_book_slot( WP_REST_Request $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = array();
		}

		// Piège à robots : champ caché qui ne doit jamais être rempli par un humain.
		if ( ! empty( $body['site_web'] ) ) {
			return new WP_REST_Response( array( 'success' => true ), 200 ); // Réponse neutre pour ne pas aider le bot à s'auto-corriger.
		}

		$ip_check = $this->check_rate_limit();
		if ( ! $ip_check ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Trop de tentatives. Merci de réessayer plus tard ou de nous appeler directement.', 'lion-rdv-booking' ),
				),
				429
			);
		}

		$errors = array();

		$service_type = in_array( $body['service'] ?? '', array( 'depannage', 'entretien' ), true ) ? $body['service'] : null;
		if ( ! $service_type ) {
			$errors[] = __( 'Type d\'intervention invalide.', 'lion-rdv-booking' );
		}

		$required_text_fields = array(
			'first_name'  => __( 'Prénom', 'lion-rdv-booking' ),
			'last_name'   => __( 'Nom', 'lion-rdv-booking' ),
			'phone'       => __( 'Téléphone', 'lion-rdv-booking' ),
			'email'       => __( 'Email', 'lion-rdv-booking' ),
			'address'     => __( 'Adresse', 'lion-rdv-booking' ),
			'postal_code' => __( 'Code postal', 'lion-rdv-booking' ),
			'city'        => __( 'Ville', 'lion-rdv-booking' ),
		);

		$clean = array();
		foreach ( $required_text_fields as $key => $label ) {
			$value = isset( $body[ $key ] ) ? sanitize_text_field( $body[ $key ] ) : '';
			if ( '' === $value ) {
				/* translators: %s: field label */
				$errors[] = sprintf( __( 'Le champ « %s » est requis.', 'lion-rdv-booking' ), $label );
			}
			$clean[ $key ] = $value;
		}

		if ( ! empty( $clean['email'] ) && ! is_email( $clean['email'] ) ) {
			$errors[] = __( 'Adresse email invalide.', 'lion-rdv-booking' );
		}

		$clean['message'] = isset( $body['message'] ) ? sanitize_textarea_field( $body['message'] ) : '';

		$tz    = wp_timezone();
		$start = null;
		$end   = null;

		try {
			if ( empty( $body['start'] ) || empty( $body['end'] ) ) {
				throw new Exception( __( 'Créneau manquant.', 'lion-rdv-booking' ) );
			}
			$start = new DateTimeImmutable( $body['start'], $tz );
			$end   = new DateTimeImmutable( $body['end'], $tz );
		} catch ( Exception $e ) {
			$errors[] = __( 'Créneau invalide.', 'lion-rdv-booking' );
		}

		if ( $start && $end && $service_type ) {
			$settings          = Lion_RDV_Settings::get_settings();
			$expected_duration = 'entretien' === $service_type ? (int) $settings['duration_entretien'] : (int) $settings['duration_depannage'];
			$actual_duration   = ( $end->getTimestamp() - $start->getTimestamp() ) / 60;

			if ( (int) $actual_duration !== $expected_duration ) {
				$errors[] = __( 'La durée du créneau ne correspond pas au type d\'intervention sélectionné.', 'lion-rdv-booking' );
			}
		}

		if ( ! empty( $errors ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => implode( ' ', $errors ),
				),
				400
			);
		}

		$availability = new Lion_RDV_Availability();
		$still_free   = $availability->is_slot_still_free( $start, $end );

		if ( null !== $still_free['error'] ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Impossible de vérifier la disponibilité pour le moment. Merci de réessayer.', 'lion-rdv-booking' ),
				),
				503
			);
		}

		if ( ! $still_free['free'] ) {
			return new WP_REST_Response(
				array(
					'success'    => false,
					'slot_taken' => true,
					'message'    => __( 'Ce créneau vient d\'être réservé par quelqu\'un d\'autre. Merci d\'en choisir un autre.', 'lion-rdv-booking' ),
				),
				409
			);
		}

		$booking = array_merge(
			$clean,
			array(
				'service_type' => $service_type,
				'start'        => $start,
				'end'          => $end,
			)
		);

		$client            = new Lion_RDV_Interfast_Client();
		$interfast_result  = $client->create_event( $booking );

		$booking_id = Lion_RDV_DB::insert_booking(
			array(
				'created_at'         => current_time( 'mysql' ),
				'service_type'       => $service_type,
				'slot_start'         => $start->format( 'Y-m-d H:i:s' ),
				'slot_end'           => $end->format( 'Y-m-d H:i:s' ),
				'first_name'         => $clean['first_name'],
				'last_name'          => $clean['last_name'],
				'phone'              => $clean['phone'],
				'email'              => $clean['email'],
				'address'            => $clean['address'],
				'postal_code'        => $clean['postal_code'],
				'city'               => $clean['city'],
				'message'            => $clean['message'],
				'status'             => $interfast_result['success'] ? 'confirmed' : 'failed',
				'interfast_event_id' => $interfast_result['event_id'],
				'interfast_error'    => $interfast_result['error'],
				'ip_address'         => $this->get_client_ip(),
			)
		);

		Lion_RDV_Notifications::send_internal_notification( $booking, $interfast_result );

		if ( ! $interfast_result['success'] ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Votre demande n\'a pas pu être enregistrée automatiquement. Notre équipe a été prévenue et vous recontactera pour confirmer votre rendez-vous.', 'lion-rdv-booking' ),
				),
				502
			);
		}

		Lion_RDV_Notifications::send_client_confirmation( $booking );

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Votre rendez-vous est confirmé ! Un email récapitulatif vous a été envoyé.', 'lion-rdv-booking' ),
			),
			200
		);
	}

	private function check_rate_limit() {
		$ip  = $this->get_client_ip();
		$key = 'lion_rdv_rate_' . md5( $ip );

		$count = (int) get_transient( $key );

		if ( $count >= 5 ) {
			return false;
		}

		set_transient( $key, $count + 1, HOUR_IN_SECONDS );

		return true;
	}

	private function get_client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return $ip ? $ip : '0.0.0.0';
	}
}
