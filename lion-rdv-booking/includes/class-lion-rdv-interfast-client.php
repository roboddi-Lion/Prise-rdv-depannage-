<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Client HTTP pour l'API InterFast (module Opérations / Événements-Interventions).
 *
 * ⚠️ À VÉRIFIER AVANT MISE EN PRODUCTION ⚠️
 * La documentation officielle (https://developers.inter-fast.fr/) n'a pas pu
 * être consultée automatiquement depuis cet environnement (accès bloqué).
 * Les chemins d'endpoints et les noms de champs ci-dessous sont construits à
 * partir des informations publiques connues (authentification par en-tête
 * X-API-KEY, module "Opérations" exposant Événements / Interventions) mais
 * DOIVENT être confirmés avec le support InterFast ou la doc développeur,
 * puis ajustés dans ce fichier si besoin — en particulier dans
 * `get_events()` et `build_event_payload()`. Un bouton "Tester la
 * connexion" est disponible dans les réglages du plugin pour valider
 * rapidement les ajustements.
 */
class Lion_RDV_Interfast_Client {

	private $api_key;
	private $base_url;
	private $resource_id;

	public function __construct( $api_key = null, $base_url = null, $resource_id = null ) {
		$settings = Lion_RDV_Settings::get_settings();

		$this->api_key     = null !== $api_key ? $api_key : $settings['interfast_api_key'];
		$this->base_url    = rtrim( null !== $base_url ? $base_url : $settings['interfast_api_base_url'], '/' );
		$this->resource_id = null !== $resource_id ? $resource_id : $settings['interfast_resource_id'];
	}

	public function is_configured() {
		return ! empty( $this->api_key ) && ! empty( $this->base_url );
	}

	/**
	 * Récupère les événements (interventions/RDV déjà planifiés) sur une plage de dates,
	 * pour pouvoir en déduire les créneaux encore libres.
	 *
	 * @return array{success:bool,events:array,error:?string} events = liste de ['start' => DateTimeImmutable, 'end' => DateTimeImmutable]
	 */
	public function get_events( DateTimeImmutable $start, DateTimeImmutable $end ) {
		$query = array(
			'date_start' => $start->format( DateTimeInterface::ATOM ),
			'date_end'   => $end->format( DateTimeInterface::ATOM ),
		);

		if ( ! empty( $this->resource_id ) ) {
			$query['resource_id'] = $this->resource_id;
		}

		$response = $this->request( 'GET', '/operations/events', $query );

		if ( ! $response['success'] ) {
			return $response;
		}

		$events = array();
		$raw    = $response['data'];

		// La plupart des API listant des ressources renvoient soit un tableau
		// brut, soit un objet avec une clé "data"/"items"/"results".
		if ( isset( $raw['data'] ) && is_array( $raw['data'] ) ) {
			$items = $raw['data'];
		} elseif ( isset( $raw['items'] ) && is_array( $raw['items'] ) ) {
			$items = $raw['items'];
		} elseif ( is_array( $raw ) && ( empty( $raw ) || array_keys( $raw ) === range( 0, count( $raw ) - 1 ) ) ) {
			$items = $raw;
		} else {
			$items = array();
		}

		foreach ( $items as $item ) {
			$item_start = $item['date_start'] ?? $item['start'] ?? null;
			$item_end   = $item['date_end'] ?? $item['end'] ?? null;

			if ( ! $item_start || ! $item_end ) {
				continue;
			}

			try {
				$events[] = array(
					'start' => new DateTimeImmutable( $item_start ),
					'end'   => new DateTimeImmutable( $item_end ),
				);
			} catch ( Exception $e ) {
				continue;
			}
		}

		return array(
			'success' => true,
			'events'  => $events,
			'error'   => null,
		);
	}

	/**
	 * Crée l'intervention/le rendez-vous dans InterFast.
	 *
	 * @param array $booking Voir Lion_RDV_Rest_Controller::handle_book_slot() pour la forme exacte.
	 * @return array{success:bool,event_id:?string,error:?string,raw:mixed}
	 */
	public function create_event( array $booking ) {
		$payload = $this->build_event_payload( $booking );

		$response = $this->request( 'POST', '/operations/events', array(), $payload );

		if ( ! $response['success'] ) {
			return array(
				'success'  => false,
				'event_id' => null,
				'error'    => $response['error'],
				'raw'      => $response['data'] ?? null,
			);
		}

		$data     = $response['data'];
		$event_id = $data['id'] ?? $data['event_id'] ?? $data['data']['id'] ?? null;

		return array(
			'success'  => true,
			'event_id' => $event_id ? (string) $event_id : null,
			'error'    => null,
			'raw'      => $data,
		);
	}

	/**
	 * Vérification de connexion utilisée par le bouton "Tester la connexion" des réglages.
	 */
	public function test_connection() {
		$now = new DateTimeImmutable( 'now' );
		return $this->get_events( $now, $now->modify( '+1 day' ) );
	}

	private function build_event_payload( array $booking ) {
		$title = sprintf(
			'%s - %s %s',
			'entretien' === $booking['service_type'] ? 'Entretien' : 'Dépannage',
			$booking['first_name'],
			$booking['last_name']
		);

		$payload = array(
			'title'       => $title,
			'type'        => $booking['service_type'],
			'date_start'  => $booking['start']->format( DateTimeInterface::ATOM ),
			'date_end'    => $booking['end']->format( DateTimeInterface::ATOM ),
			'description' => $booking['message'],
			'client'      => array(
				'first_name'  => $booking['first_name'],
				'last_name'   => $booking['last_name'],
				'phone'       => $booking['phone'],
				'email'       => $booking['email'],
				'address'     => $booking['address'],
				'postal_code' => $booking['postal_code'],
				'city'        => $booking['city'],
			),
		);

		if ( ! empty( $this->resource_id ) ) {
			$payload['resource_id'] = $this->resource_id;
		}

		return apply_filters( 'lion_rdv_interfast_event_payload', $payload, $booking );
	}

	private function request( $method, $path, array $query = array(), array $body = null ) {
		if ( ! $this->is_configured() ) {
			return array(
				'success' => false,
				'data'    => null,
				'error'   => __( 'La clé API ou l\'URL InterFast ne sont pas configurées.', 'lion-rdv-booking' ),
			);
		}

		$url = $this->base_url . $path;

		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}

		$args = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => array(
				'X-API-KEY'    => $this->api_key,
				'Accept'       => 'application/json',
				'Content-Type' => 'application/json',
			),
		);

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'data'    => null,
				'error'   => $response->get_error_message(),
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code < 200 || $code >= 300 ) {
			$message = is_array( $data ) && isset( $data['message'] ) ? $data['message'] : $raw;
			return array(
				'success' => false,
				'data'    => $data,
				'error'   => sprintf( 'InterFast HTTP %d : %s', $code, $message ),
			);
		}

		return array(
			'success' => true,
			'data'    => is_array( $data ) ? $data : array(),
			'error'   => null,
		);
	}
}
