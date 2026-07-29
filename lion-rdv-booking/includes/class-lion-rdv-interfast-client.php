<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Client HTTP pour l'API InterFast (module Opérations / Événements-Interventions).
 *
 * Confirmé via https://developers.inter-fast.fr/ (référence OpenAPI) :
 * - Serveur : https://app.inter-fast.fr (les chemins incluent déjà /v1)
 * - Auth par en-tête X-API-KEY
 * - GET  /v1/events        → vue unifiée du planning (sert à calculer les créneaux libres)
 * - POST /v1/intervention  → création d'une intervention
 *
 * ⚠️ ENCORE À CONFIRMER ⚠️
 * La doc de référence n'a listé que les chemins d'endpoints, pas le détail
 * des paramètres/schémas (accès direct à developers.inter-fast.fr bloqué
 * depuis cet environnement). Restent à vérifier :
 * - les noms exacts des paramètres de filtrage par date sur GET /v1/events
 * - le schéma exact du corps attendu par POST /v1/intervention (en
 *   particulier : réfère-t-on le client via `client_id` — vu la présence
 *   d'un module CRM/Clients séparé — ou peut-on l'envoyer en objet inline
 *   comme fait ci-dessous ?)
 * Ajustez `get_events()` et `build_event_payload()` en conséquence. Le
 * bouton "Tester la connexion" des réglages permet de valider rapidement.
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
	 * Nombre maximum d'événements récupérés en une seule page. Largement
	 * suffisant pour l'horizon de réservation d'une PME ; si votre planning
	 * dépasse ce volume sur la période, augmentez cette valeur (voir aussi
	 * le champ `count` retourné par l'API pour détecter une troncature).
	 */
	const EVENTS_PAGE_SIZE = 1000;

	/**
	 * Récupère les événements (interventions/RDV/absences/tâches déjà planifiés)
	 * sur une plage de dates, pour pouvoir en déduire les créneaux encore libres.
	 *
	 * Paramètres confirmés via GET /v1/events dans developers.inter-fast.fr.
	 *
	 * @return array{success:bool,events:array,error:?string} events = liste de ['start' => DateTimeImmutable, 'end' => DateTimeImmutable]
	 */
	public function get_events( DateTimeImmutable $start, DateTimeImmutable $end ) {
		$query = array(
			'start'    => $start->format( DateTimeInterface::ATOM ),
			'end'      => $end->format( DateTimeInterface::ATOM ),
			'page'     => 0,
			'count'    => self::EVENTS_PAGE_SIZE,
			'archived' => 'false',
			// meeting=false : pas de restriction aux seuls rendez-vous, on veut
			// la vue unifiée (interventions + RDV + absences + tâches) pour
			// calculer correctement les créneaux occupés.
			'meeting'  => 'false',
		);

		if ( ! empty( $this->resource_id ) ) {
			$query['technicians'] = array( $this->resource_id );
		}

		$response = $this->request( 'GET', '/v1/events', $query );

		if ( ! $response['success'] ) {
			return $response;
		}

		$events = array();
		$raw    = $response['data'];
		$items  = isset( $raw['items'] ) && is_array( $raw['items'] ) ? $raw['items'] : array();

		foreach ( $items as $item ) {
			if ( empty( $item['start'] ) || empty( $item['end'] ) ) {
				continue;
			}

			try {
				$events[] = array(
					'start' => new DateTimeImmutable( $item['start'] ),
					'end'   => new DateTimeImmutable( $item['end'] ),
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

		$response = $this->request( 'POST', '/v1/intervention', array(), $payload );

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
			// http_build_query() encode correctement les valeurs (nos dates ISO
			// contiennent des `+` et `:`), contrairement à add_query_arg() de WP.
			$url .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
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
