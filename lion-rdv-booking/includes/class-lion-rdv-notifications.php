<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Emails de confirmation (client) et de notification interne (Lion Rénovation).
 *
 * ⚠️ wp_mail() utilise par défaut la fonction PHP mail() du serveur, souvent
 * bloquée ou fortement filtrée par les hébergeurs mutualisés (aucun MTA
 * configuré, ou emails partant sans SPF/DKIM valides et donc rejetés côté
 * destinataire). Si les emails de confirmation n'arrivent pas alors que
 * cette classe ne journalise aucune erreur ci-dessous, c'est le signe le
 * plus probable : installez un plugin SMTP (ex. "WP Mail SMTP") relié à un
 * vrai service d'envoi (Brevo, Mailgun, Gmail...) plutôt que mail() natif.
 */
class Lion_RDV_Notifications {

	private static $last_mail_error = null;

	public static function init() {
		add_action( 'wp_mail_failed', array( __CLASS__, 'capture_mail_error' ) );
		add_filter( 'wp_mail_from_name', array( __CLASS__, 'filter_mail_from_name' ) );
	}

	public static function capture_mail_error( WP_Error $error ) {
		self::$last_mail_error = $error->get_error_message();
	}

	public static function filter_mail_from_name( $name ) {
		return 'wordpress' === strtolower( (string) $name ) ? 'Lion Rénovation' : $name;
	}

	/**
	 * @return array{sent:bool,error:?string}
	 */
	public static function send_client_confirmation( array $booking ) {
		$services      = Lion_RDV_Settings::get_settings()['services'];
		$service_label = $services[ $booking['service_type'] ]['label'] ?? $booking['service_type'];

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
			wp_date( 'l j F Y', $booking['start']->getTimestamp(), $booking['start']->getTimezone() ),
			$booking['start']->format( 'H:i' ) . ' - ' . $booking['end']->format( 'H:i' ),
			$booking['address'] . ', ' . $booking['postal_code'] . ' ' . $booking['city']
		);

		self::$last_mail_error = null;
		$sent                  = wp_mail( $booking['email'], $subject, $message );

		if ( ! $sent ) {
			$error = self::$last_mail_error ?: __( 'wp_mail() a échoué sans détail (aucun serveur d\'envoi mail configuré sur l\'hébergement ?).', 'lion-rdv-booking' );
			error_log( sprintf( '[Lion RDV] Échec envoi email de confirmation à %s : %s', $booking['email'], $error ) );

			return array(
				'sent'  => false,
				'error' => $error,
			);
		}

		return array(
			'sent'  => true,
			'error' => null,
		);
	}

	/**
	 * ⚠️ Cette notification utilise elle-même wp_mail() : si l'envoi de mail
	 * est cassé sur l'hébergement (voir note en haut de fichier), ni le
	 * client ni vous ne recevrez d'email. Le statut d'envoi est donc
	 * toujours consultable dans Réglages > Prise de RDV Lion > Voir les
	 * réservations récentes, indépendamment des emails.
	 */
	public static function send_internal_notification( array $booking, $interfast_result, $email_result = array() ) {
		$settings = Lion_RDV_Settings::get_settings();
		$to       = $settings['notification_email'];

		if ( empty( $to ) ) {
			return;
		}

		$service_label = $settings['services'][ $booking['service_type'] ]['label'] ?? $booking['service_type'];
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
			sprintf( '%s: %s', __( 'Date', 'lion-rdv-booking' ), wp_date( 'l j F Y', $booking['start']->getTimestamp(), $booking['start']->getTimezone() ) ),
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

		if ( isset( $email_result['sent'] ) && ! $email_result['sent'] ) {
			$lines[] = sprintf(
				'%s: %s',
				__( 'Email client', 'lion-rdv-booking' ),
				__( 'ÉCHEC d\'envoi - pensez à contacter le client par téléphone', 'lion-rdv-booking' ) . ( $email_result['error'] ? ' (' . $email_result['error'] . ')' : '' )
			);
		}

		wp_mail( $to, $subject, implode( "\n", $lines ) );
	}
}
