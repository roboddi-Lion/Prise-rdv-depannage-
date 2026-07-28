<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Emails de confirmation (client) et de notification interne (Lion Rénovation).
 */
class Lion_RDV_Notifications {

	public static function send_client_confirmation( array $booking ) {
		$service_label = 'entretien' === $booking['service_type'] ? __( 'entretien', 'lion-rdv-booking' ) : __( 'dépannage', 'lion-rdv-booking' );

		$subject = sprintf(
			/* translators: %s: service type */
			__( 'Confirmation de votre rendez-vous %s - Lion Rénovation', 'lion-rdv-booking' ),
			$service_label
		);

		$message = sprintf(
			/* translators: 1: first name, 2: service type, 3: date, 4: time range, 5: address */
			__(
				"Bonjour %1\$s,\n\nVotre rendez-vous de %2\$s avec Lion Rénovation est confirmé :\n\nDate : %3\$s\nHeure : %4\$s\nAdresse d'intervention : %5\$s\n\nSi vous avez besoin de modifier ou d'annuler ce rendez-vous, contactez-nous directement.\n\nÀ bientôt,\nL'équipe Lion Rénovation",
				'lion-rdv-booking'
			),
			$booking['first_name'],
			$service_label,
			date_i18n( 'l j F Y', $booking['start']->getTimestamp() ),
			$booking['start']->format( 'H:i' ) . ' - ' . $booking['end']->format( 'H:i' ),
			$booking['address'] . ', ' . $booking['postal_code'] . ' ' . $booking['city']
		);

		wp_mail( $booking['email'], $subject, $message );
	}

	public static function send_internal_notification( array $booking, $interfast_result ) {
		$settings = Lion_RDV_Settings::get_settings();
		$to       = $settings['notification_email'];

		if ( empty( $to ) ) {
			return;
		}

		$service_label = 'entretien' === $booking['service_type'] ? __( 'Entretien', 'lion-rdv-booking' ) : __( 'Dépannage', 'lion-rdv-booking' );
		$status_label  = $interfast_result['success']
			? __( 'synchronisé avec InterFast', 'lion-rdv-booking' )
			: __( 'ÉCHEC de synchronisation InterFast - à créer manuellement', 'lion-rdv-booking' );

		$subject = sprintf(
			/* translators: %s: service type */
			__( 'Nouveau RDV %s réservé en ligne', 'lion-rdv-booking' ),
			$service_label
		);

		$lines = array(
			sprintf( '%s: %s', __( 'Service', 'lion-rdv-booking' ), $service_label ),
			sprintf( '%s: %s', __( 'Statut', 'lion-rdv-booking' ), $status_label ),
			sprintf( '%s: %s', __( 'Date', 'lion-rdv-booking' ), date_i18n( 'l j F Y', $booking['start']->getTimestamp() ) ),
			sprintf( '%s: %s - %s', __( 'Heure', 'lion-rdv-booking' ), $booking['start']->format( 'H:i' ), $booking['end']->format( 'H:i' ) ),
			sprintf( '%s: %s %s', __( 'Client', 'lion-rdv-booking' ), $booking['first_name'], $booking['last_name'] ),
			sprintf( '%s: %s', __( 'Téléphone', 'lion-rdv-booking' ), $booking['phone'] ),
			sprintf( '%s: %s', __( 'Email', 'lion-rdv-booking' ), $booking['email'] ),
			sprintf( '%s: %s, %s %s', __( 'Adresse', 'lion-rdv-booking' ), $booking['address'], $booking['postal_code'], $booking['city'] ),
			sprintf( '%s: %s', __( 'Message', 'lion-rdv-booking' ), $booking['message'] ? $booking['message'] : '-' ),
		);

		if ( ! $interfast_result['success'] ) {
			$lines[] = sprintf( '%s: %s', __( 'Erreur InterFast', 'lion-rdv-booking' ), $interfast_result['error'] );
		}

		wp_mail( $to, $subject, implode( "\n", $lines ) );
	}
}
