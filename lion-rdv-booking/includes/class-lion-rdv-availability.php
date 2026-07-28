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
	 * @param string $service_type 'depannage' ou 'entretien'
	 * @return array{success:bool,error:?string,days:array}
	 */
	public function get_available_slots( $service_type ) {
		$settings = Lion_RDV_Settings::get_settings();
		$tz       = wp_timezone();

		$duration_minutes = 'entretien' === $service_type ? (int) $settings['duration_entretien'] : (int) $settings['duration_depannage'];
		$lead_hours        = 'entretien' === $service_type ? (int) $settings['lead_time_entretien'] : (int) $settings['lead_time_depannage'];
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

		$busy_periods = $events_result['events'];
		$days         = array();
		$day_keys     = array_flip( Lion_RDV_Settings::$days );

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

						if ( $slot_start >= $earliest && ! $this->overlaps_any( $slot_start, $slot_end, $busy_periods ) ) {
							$day_slots[] = array(
								'start' => $slot_start->format( DateTimeInterface::ATOM ),
								'end'   => $slot_end->format( DateTimeInterface::ATOM ),
								'label' => $slot_start->format( 'H:i' ) . ' - ' . $slot_end->format( 'H:i' ),
							);
						}

						$slot_start = $slot_start->modify( "+{$step_minutes} minutes" );
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
	 * de l'événement InterFast (limite le risque de double réservation).
	 */
	public function is_slot_still_free( DateTimeImmutable $start, DateTimeImmutable $end ) {
		$result = $this->client->get_events( $start->modify( '-1 minute' ), $end->modify( '+1 minute' ) );

		if ( ! $result['success'] ) {
			return array(
				'free'  => false,
				'error' => $result['error'],
			);
		}

		return array(
			'free'  => ! $this->overlaps_any( $start, $end, $result['events'] ),
			'error' => null,
		);
	}

	private function overlaps_any( DateTimeImmutable $start, DateTimeImmutable $end, array $busy_periods ) {
		foreach ( $busy_periods as $busy ) {
			if ( $busy['start'] < $end && $busy['end'] > $start ) {
				return true;
			}
		}
		return false;
	}

	private function combine_date_time( DateTimeImmutable $day, $time_string, DateTimeZone $tz ) {
		list( $hours, $minutes ) = array_map( 'intval', explode( ':', $time_string ) );
		return $day->setTime( $hours, $minutes, 0 );
	}
}
