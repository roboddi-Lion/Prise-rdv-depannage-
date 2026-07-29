<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Client HTTP pour l'API InterFast (Événements, Interventions, Clients CRM).
 *
 * Confirmé via https://developers.inter-fast.fr/ (référence OpenAPI) :
 * - Serveur : https://app.inter-fast.fr (les chemins incluent déjà /v1)
 * - Auth par en-tête X-API-KEY
 * - GET  /v1/events            → vue unifiée du planning (sert à calculer les créneaux libres)
 * - GET  /v1/crm/search        → recherche d'un client existant (par email) avant d'en créer un
 * - POST /v1/client/particular → création du client CRM si aucun existant ne correspond
 * - POST /v1/intervention      → création de l'intervention (référence clientId/addressId/reportTypeId)
 */
class Lion_RDV_Interfast_Client {

	private $api_key;
	private $base_url;
	private $resource_id;
	private $report_type_ids;

	public function __construct( $api_key = null, $base_url = null, $resource_id = null ) {
		$settings = Lion_RDV_Settings::get_settings();

		$this->api_key        = null !== $api_key ? $api_key : $settings['interfast_api_key'];
		$this->base_url       = rtrim( null !== $base_url ? $base_url : $settings['interfast_api_base_url'], '/' );
		$this->resource_id    = null !== $resource_id ? $resource_id : $settings['interfast_resource_id'];
		$this->report_type_ids = array(
			'depannage' => $settings['interfast_report_type_id_depannage'],
			'entretien' => $settings['interfast_report_type_id_entretien'],
		);
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
	 * Crée l'intervention dans InterFast pour la réservation du client.
	 *
	 * InterFast référence les interventions à un client du CRM (`clientId` /
	 * `addressId`) plutôt que d'accepter des coordonnées en texte libre : on
	 * recherche donc d'abord un client existant par email, et on n'en crée un
	 * nouveau que si aucun ne correspond.
	 *
	 * @param array $booking Voir Lion_RDV_Rest_Controller::handle_book_slot() pour la forme exacte.
	 * @return array{success:bool,event_id:?string,error:?string,raw:mixed}
	 */
	public function create_event( array $booking ) {
		if ( empty( $this->report_type_ids[ $booking['service_type'] ] ) ) {
			return array(
				'success'  => false,
				'event_id' => null,
				'error'    => __( 'Le réglage "ID de modèle de rapport InterFast" n\'est pas configuré pour ce type d\'intervention.', 'lion-rdv-booking' ),
				'raw'      => null,
			);
		}

		$client_result = $this->find_or_create_client( $booking );

		if ( ! $client_result['success'] ) {
			return array(
				'success'  => false,
				'event_id' => null,
				'error'    => $client_result['error'],
				'raw'      => $client_result['raw'],
			);
		}

		if ( empty( $client_result['client_id'] ) || empty( $client_result['address_id'] ) ) {
			return array(
				'success'  => false,
				'event_id' => null,
				'error'    => __( 'Client ou adresse InterFast introuvable après création/recherche du client (addressId requis pour créer l\'intervention).', 'lion-rdv-booking' ),
				'raw'      => $client_result['raw'],
			);
		}

		$payload = $this->build_intervention_payload( $booking, $client_result['client_id'], $client_result['address_id'] );

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
		$event_id = $data['id'] ?? $data['data']['id'] ?? null;

		return array(
			'success'  => true,
			'event_id' => $event_id ? (string) $event_id : null,
			'error'    => null,
			'raw'      => $data,
		);
	}

	/**
	 * Recherche un client existant par email (GET /v1/crm/search) et le
	 * réutilise si trouvé ; sinon crée un nouveau client "particulier".
	 *
	 * @return array{success:bool,client_id:?int,address_id:?int,error:?string,raw:mixed}
	 */
	private function find_or_create_client( array $booking ) {
		$existing = $this->find_client_by_email( $booking['email'] );

		if ( null !== $existing ) {
			return array(
				'success'    => true,
				'client_id'  => $existing['client_id'],
				'address_id' => $existing['address_id'],
				'error'      => null,
				'raw'        => $existing['raw'],
			);
		}

		return $this->create_particular_client( $booking );
	}

	/**
	 * Cherche un client dont l'email correspond exactement (insensible à la
	 * casse) à celui de la réservation.
	 *
	 * Confirmé via GET /v1/crm/search dans developers.inter-fast.fr. Le
	 * paramètre `name` y est documenté pour rechercher par nom ; on l'utilise
	 * ici avec l'email et on ne retient le résultat que s'il correspond
	 * exactement au champ `email` retourné — sans risque si `name` ne
	 * matche pas sur l'email (dans ce cas on ne trouve simplement rien et on
	 * crée un nouveau client, comportement inchangé).
	 *
	 * @return array{client_id:int,address_id:?int,raw:mixed}|null
	 */
	private function find_client_by_email( $email ) {
		$response = $this->request(
			'GET',
			'/v1/crm/search',
			array(
				'name'     => $email,
				'page'     => 0,
				'size'     => 20,
				'archived' => 'false',
			)
		);

		if ( ! $response['success'] ) {
			// Recherche indisponible : on se rabat sur la création, plutôt que
			// de bloquer toute la réservation pour une optimisation.
			return null;
		}

		$items = isset( $response['data']['items'] ) && is_array( $response['data']['items'] ) ? $response['data']['items'] : array();

		foreach ( $items as $item ) {
			if ( isset( $item['email'] ) && is_string( $item['email'] ) && 0 === strcasecmp( trim( $item['email'] ), trim( $email ) ) ) {
				$detail = $this->request( 'GET', '/v1/client/' . rawurlencode( $item['id'] ) );

				if ( ! $detail['success'] || empty( $detail['data']['id'] ) ) {
					continue;
				}

				return array(
					'client_id'  => (int) $detail['data']['id'],
					'address_id' => isset( $detail['data']['primaryAddressId'] ) ? (int) $detail['data']['primaryAddressId'] : null,
					'raw'        => $detail['data'],
				);
			}
		}

		return null;
	}

	/**
	 * Crée le client "particulier" associé à la réservation.
	 * Confirmé via POST /v1/client/particular dans developers.inter-fast.fr.
	 *
	 * @return array{success:bool,client_id:?int,address_id:?int,error:?string,raw:mixed}
	 */
	private function create_particular_client( array $booking ) {
		$payload = array(
			'category'    => 'client',
			'firstName'   => $booking['first_name'],
			'lastName'    => $booking['last_name'],
			'email'       => $booking['email'],
			'phoneNumber' => $this->normalize_french_phone( $booking['phone'] ),
			'address'     => $booking['address'],
			'zipCode'     => $booking['postal_code'],
			'city'        => $booking['city'],
			'addressCountry' => 'FR',
			'commentary'  => __( 'Client créé automatiquement via la prise de RDV en ligne.', 'lion-rdv-booking' ),
		);

		$payload = apply_filters( 'lion_rdv_interfast_client_payload', $payload, $booking );

		$response = $this->request( 'POST', '/v1/client/particular', array(), $payload );

		if ( ! $response['success'] ) {
			return array(
				'success'    => false,
				'client_id'  => null,
				'address_id' => null,
				'error'      => $response['error'],
				'raw'        => $response['data'] ?? null,
			);
		}

		$data = $response['data'];

		return array(
			'success'    => true,
			'client_id'  => isset( $data['id'] ) ? (int) $data['id'] : null,
			'address_id' => isset( $data['primaryAddressId'] ) ? (int) $data['primaryAddressId'] : null,
			'error'      => null,
			'raw'        => $data,
		);
	}

	/**
	 * Convertit un numéro français en format international basique
	 * (l'API InterFast attend le format international, ex : +33612345678).
	 */
	private function normalize_french_phone( $phone ) {
		$digits = preg_replace( '/[^0-9+]/', '', $phone );

		if ( 0 === strpos( $digits, '+' ) ) {
			return $digits;
		}

		if ( 0 === strpos( $digits, '0' ) && 10 === strlen( $digits ) ) {
			return '+33' . substr( $digits, 1 );
		}

		return $digits;
	}

	/**
	 * Vérification de connexion utilisée par le bouton "Tester la connexion" des réglages.
	 * Remonte aussi la liste des modèles de rapport disponibles pour faciliter
	 * la configuration du réglage "ID de modèle de rapport" (reportTypeId).
	 */
	public function test_connection() {
		$now    = new DateTimeImmutable( 'now' );
		$result = $this->get_events( $now, $now->modify( '+1 day' ) );

		$result['report_types'] = $result['success'] ? $this->get_report_types() : array();

		return $result;
	}

	/**
	 * Liste les modèles de rapport (GET /v1/report/types), nécessaires pour
	 * renseigner `reportTypeId` sur chaque intervention créée.
	 *
	 * ⚠️ Le schéma exact de cette réponse n'a pas été confirmé dans la doc
	 * (contrairement aux autres endpoints utilisés par ce plugin) : le
	 * parsing ci-dessous essaie plusieurs noms de champs plausibles, et
	 * affiche le JSON brut de l'entrée en dernier recours plutôt qu'une
	 * ligne vide, pour que l'ID reste repérable à l'oeil dans tous les cas.
	 *
	 * @return array Liste de ['id' => string, 'name' => string] (best-effort, tableau vide si indisponible).
	 */
	public function get_report_types() {
		$response = $this->request( 'GET', '/v1/report/types' );

		if ( ! $response['success'] ) {
			return array();
		}

		$raw   = $response['data'];
		$items = isset( $raw['items'] ) && is_array( $raw['items'] ) ? $raw['items'] : ( is_array( $raw ) ? $raw : array() );

		$types = array();
		foreach ( $items as $item ) {
			if ( is_array( $item ) ) {
				$id = $item['id'] ?? $item['reportTypeId'] ?? $item['_id'] ?? null;

				if ( null !== $id && '' !== $id ) {
					$types[] = array(
						'id'   => (string) $id,
						'name' => (string) ( $item['name'] ?? $item['title'] ?? $item['label'] ?? '' ),
					);
					continue;
				}

				// Champ id introuvable sous les noms attendus : on affiche
				// l'entrée brute pour que l'ID reste visible/copiable.
				$types[] = array(
					'id'   => '',
					'name' => wp_json_encode( $item ),
				);
			} elseif ( is_scalar( $item ) ) {
				$types[] = array(
					'id'   => (string) $item,
					'name' => '',
				);
			}
		}

		return $types;
	}

	/**
	 * Construit le corps de POST /v1/intervention.
	 * Confirmé via developers.inter-fast.fr (CreateInterventionDto) — 7 champs
	 * obligatoires : addressId, clientId, importanceLevel, isDescriptionInReport,
	 * reportTypeId, secondReference, title.
	 */
	private function build_intervention_payload( array $booking, $client_id, $address_id ) {
		$title = sprintf(
			'%s - %s %s',
			'entretien' === $booking['service_type'] ? 'Entretien' : 'Dépannage',
			$booking['first_name'],
			$booking['last_name']
		);

		$payload = array(
			'clientId'               => $client_id,
			'addressId'              => $address_id,
			'reportTypeId'           => $this->report_type_ids[ $booking['service_type'] ],
			'title'                  => $title,
			// Référence libre pour retrouver facilement la réservation d'origine.
			'secondReference'        => 'WEB-' . $booking['start']->format( 'Ymd-Hi' ) . '-' . strtoupper( substr( md5( $booking['email'] . $booking['start']->format( DateTimeInterface::ATOM ) ), 0, 4 ) ),
			'isDescriptionInReport'  => true,
			'description'            => $booking['message'],
			'start'                  => $booking['start']->format( DateTimeInterface::ATOM ),
			'end'                    => $booking['end']->format( DateTimeInterface::ATOM ),
			// Le dépannage est traité en priorité "high", l'entretien en "normal".
			'importanceLevel'        => 'depannage' === $booking['service_type'] ? 'high' : 'normal',
		);

		if ( ! empty( $this->resource_id ) ) {
			$payload['primaryTechnicianId'] = (int) $this->resource_id;
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
