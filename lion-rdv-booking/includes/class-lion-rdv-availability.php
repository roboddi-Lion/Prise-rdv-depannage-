<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Calcule les créneaux encore libres en combinant les horaires d'ouverture
 * configurés dans le plugin et les événements déjà planifiés dans InterFast.
 */
class Lion_RDV_Availability {

	/** @var Lion_RDV_Interfast_Client */
	private $client;

	public function __construct( Lion_RDV_Interfast_Client $client = null ) {
		$this->client = $client ?: new Lion_RDV_Interfast_Client();
	}

	/**
	 * @param string $service_type Clé d'un service configuré dans Lion_RDV_Settings::default_services().
	 * @return array{success:bool,error:?string,days:array}
	 */
	public function get_available_slots( $service_type ) {
		$settings = Lion_RDV_Settings::get_settings();
		$tz       = wp_timezone();

		if ( ! isset( $settings['services'][ $service_type ] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Type de rendez-vous inconnu.', 'lion-rdv-booking' ),
				'days'    => array(),
			);
		}

		$service           = $settings['services'][ $service_type ];
		$duration_minutes  = (int) $service['duration_minutes'];
		$lead_hours        = (int) $service['lead_time_hours'];
		$horizon_days      = (int) $settings['horizon_days'];
		$step_minutes      = (int) $settings['slot_step_minutes'];

		$now      = new DateTimeImmutable( 'now', $tz );
		$earliest = $now->modify( "+{$lead_hours} hours" );

		$range_start = $now->setTime( 0, 0, 0 );
		$range_end   = $range_start->modify( "+{$horizon_days} days" );

		$events_result = $this->client->get_events( $range_start, $range_end );

		if ( ! $events_result['success'] ) {
			return array(
				'success' => false,
				'error'   => $events_result['error'],
				'days'    => array(),
			);
		}

		$busy_periods   = $events_result['events'];
		$technician_ids = $settings['interfast_technician_ids'];
		$days           = array();
		$day_keys       = array_flip( Lion_RDV_Settings::$days );

		$cursor = $range_start;
		while ( $cursor < $range_end ) {
			$iso_weekday = (int) $cursor->format( 'N' );
			$day_key     = $day_keys[ $iso_weekday ] ?? null;
			$day_config  = $day_key ? $settings['hours'][ $day_key ] : null;

			if ( $day_config && $day_config['open'] ) {
				$windows = array();
				if ( ! empty( $day_config['matin_debut'] ) && ! empty( $day_config['matin_fin'] ) ) {
					$windows[] = array( $day_config['matin_debut'], $day_config['matin_fin'] );
				}
				if ( ! empty( $day_config['apres_midi_debut'] ) && ! empty( $day_config['apres_midi_fin'] ) ) {
					$windows[] = array( $day_config['apres_midi_debut'], $day_config['apres_midi_fin'] );
				}

				$day_slots = array();

				foreach ( $windows as $window ) {
					list( $window_start_time, $window_end_time ) = $window;

					$window_start = $this->combine_date_time( $cursor, $window_start_time, $tz );
					$window_end   = $this->combine_date_time( $cursor, $window_end_time, $tz );

					if ( $window_end <= $window_start ) {
						continue;
					}

					$slot_start = $window_start;
					while ( true ) {
						$slot_end = $slot_start->modify( "+{$duration_minutes} minutes" );

						if ( $slot_end > $window_end ) {
							break;
						}

						if ( $slot_start >= $earliest && $this->slot_is_available( $slot_start, $slot_end, $busy_periods, $technician_ids )['available'] ) {
							$day_slots[] = array(
								'start' => $slot_start->format( DateTimeInterface::ATOM ),
								'end'   => $slot_end->format( DateTimeInterface::ATOM ),
								'label' => $slot_start->format( 'H:i' ) . ' - ' . $slot_end->format( 'H:i' ),
							);
						}

						// L'intervalle s'ajoute APRÈS la fin du rendez-vous (battement),
						// jamais pendant : deux créneaux proposés ne se chevauchent
						// donc jamais, quelle que soit la durée du service.
						$slot_start = $slot_start->modify( '+' . ( $duration_minutes + $step_minutes ) . ' minutes' );
					}
				}

				if ( ! empty( $day_slots ) ) {
					$days[] = array(
						'date'  => $cursor->format( 'Y-m-d' ),
						'label' => date_i18n( 'l j F', $cursor->getTimestamp() ),
						'slots' => $day_slots,
					);
				}
			}

			$cursor = $cursor->modify( '+1 day' );
		}

		return array(
			'success' => true,
			'error'   => null,
			'days'    => $days,
		);
	}

	/**
	 * Revérifie qu'un créneau précis est toujours libre juste avant la création
	 * de l'événement InterFast (limite le risque de double réservation), et
	 * détermine quel technicien libre lui assigner (mode multi-techniciens).
	 *
	 * @return array{free:bool,error:?string,technician_id:?int}
	 */
	public function is_slot_still_free( DateTimeImmutable $start, DateTimeImmutable $end ) {
		$result = $this->client->get_events( $start->modify( '-1 minute' ), $end->modify( '+1 minute' ) );

		if ( ! $result['success'] ) {
			return array(
				'free'          => false,
				'error'         => $result['error'],
				'technician_id' => null,
			);
		}

		$availability = $this->slot_is_available( $start, $end, $result['events'], $this->client->get_technician_ids() );

		return array(
			'free'          => $availability['available'],
			'error'         => null,
			'technician_id' => $availability['technician_id'],
		);
	}

	/**
	 * Détermine si un créneau est disponible.
	 *
	 * - Si $technician_ids est vide (aucun technicien configuré pour les RDV
	 *   en ligne) : mode "planning global", le créneau est libre si AUCUN
	 *   événement ne le chevauche, quel qu'en soit le technicien.
	 * - Sinon : le créneau est libre si AU MOINS UN des techniciens listés
	 *   n'a aucun événement qui lui est assigné sur ce créneau ; ce
	 *   technicien est retourné pour lui assigner l'intervention.
	 *
	 * @return array{available:bool,technician_id:?int}
	 */
	private function slot_is_available( DateTimeImmutable $start, DateTimeImmutable $end, array $busy_periods, array $technician_ids ) {
		if ( empty( $technician_ids ) ) {
			foreach ( $busy_periods as $busy ) {
				if ( $busy['start'] < $end && $busy['end'] > $start ) {
					return array(
						'available'     => false,
						'technician_id' => null,
					);
				}
			}
			return array(
				'available'     => true,
				'technician_id' => null,
			);
		}

		foreach ( $technician_ids as $technician_id ) {
			$blocked = false;

			foreach ( $busy_periods as $busy ) {
				if ( $busy['start'] < $end && $busy['end'] > $start && in_array( (int) $technician_id, $busy['technician_ids'] ?? array(), true ) ) {
					$blocked = true;
					break;
				}
			}

			if ( ! $blocked ) {
				return array(
					'available'     => true,
					'technician_id' => (int) $technician_id,
				);
			}
		}

		return array(
			'available'     => false,
			'technician_id' => null,
		);
	}

	private function combine_date_time( DateTimeImmutable $day, $time_string, DateTimeZone $tz ) {
		list( $hours, $minutes ) = array_map( 'intval', explode( ':', $time_string ) );
		return $day->setTime( $hours, $minutes, 0 );
	}
}
