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
						'enum'     => array_keys( array_filter( Lion_RDV_Settings::get_settings()['services'], static function ( $service ) {
							return ! empty( $service['enabled'] );
						} ) ),
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

		$errors   = array();
		$settings = Lion_RDV_Settings::get_settings();

		$requested_service = $settings['services'][ $body['service'] ?? '' ] ?? null;
		$service_type      = ! empty( $requested_service['enabled'] ) ? $body['service'] : null;
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
			$expected_duration = (int) $settings['services'][ $service_type ]['duration_minutes'];
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

		// Verrou côté WordPress : ferme la fenêtre de course la plus probable
		// (deux visiteurs qui cliquent sur le même créneau au même moment sur
		// le widget). Best-effort, pas un verrou distribué garanti, mais
		// couvre le cas réel puisque toutes les réservations passent par ici.
		if ( ! $this->acquire_slot_lock( $service_type, $start, $end ) ) {
			return new WP_REST_Response(
				array(
					'success'    => false,
					'slot_taken' => true,
					'message'    => __( 'Ce créneau vient d\'être réservé par quelqu\'un d\'autre. Merci d\'en choisir un autre.', 'lion-rdv-booking' ),
				),
				409
			);
		}

		try {
			$availability = new Lion_RDV_Availability();
			$still_free   = $availability->is_slot_still_free( $start, $end, $service_type );

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
					'service_type'            => $service_type,
					'start'                   => $start,
					'end'                     => $end,
					'assigned_technician_id'  => $still_free['technician_id'],
				)
			);

			$client           = new Lion_RDV_Interfast_Client();
			$interfast_result = $client->create_event( $booking );

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

			$email_result = $interfast_result['success']
				? Lion_RDV_Notifications::send_client_confirmation( $booking )
				: array( 'sent' => false, 'error' => null ); // pas de confirmation tant que le RDV n'est pas réellement confirmé.

			Lion_RDV_DB::update_booking(
				$booking_id,
				array(
					'client_email_sent'  => $email_result['sent'] ? 1 : 0,
					'client_email_error' => $email_result['error'],
				)
			);

			Lion_RDV_Notifications::send_internal_notification( $booking, $interfast_result, $email_result );

			if ( ! $interfast_result['success'] ) {
				return new WP_REST_Response(
					array(
						'success' => false,
						'message' => __( 'Votre demande n\'a pas pu être enregistrée automatiquement. Notre équipe a été prévenue et vous recontactera pour confirmer votre rendez-vous.', 'lion-rdv-booking' ),
					),
					502
				);
			}

			return new WP_REST_Response(
				array(
					'success' => true,
					'message' => $email_result['sent']
						? __( 'Votre rendez-vous est confirmé ! Un email récapitulatif vous a été envoyé.', 'lion-rdv-booking' )
						: __( 'Votre rendez-vous est confirmé ! (L\'email récapitulatif n\'a pas pu être envoyé, mais votre RDV est bien enregistré.)', 'lion-rdv-booking' ),
				),
				200
			);
		} finally {
			$this->release_slot_lock( $service_type, $start, $end );
		}
	}

	private function acquire_slot_lock( $service_type, DateTimeImmutable $start, DateTimeImmutable $end ) {
		$key = $this->slot_lock_key( $service_type, $start, $end );

		if ( false !== get_transient( $key ) ) {
			return false;
		}

		// 30s couvre largement le temps d'un aller-retour InterFast (création
		// du client + de l'intervention) ; libéré explicitement dans le
		// `finally` de handle_book_slot() dès que la requête se termine.
		set_transient( $key, 1, 30 );

		return true;
	}

	private function release_slot_lock( $service_type, DateTimeImmutable $start, DateTimeImmutable $end ) {
		delete_transient( $this->slot_lock_key( $service_type, $start, $end ) );
	}

	private function slot_lock_key( $service_type, DateTimeImmutable $start, DateTimeImmutable $end ) {
		return 'lion_rdv_lock_' . md5( $service_type . '|' . $start->format( DateTimeInterface::ATOM ) . '|' . $end->format( DateTimeInterface::ATOM ) );
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
