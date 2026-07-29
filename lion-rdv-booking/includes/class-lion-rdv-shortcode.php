<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcode [lion_rdv_booking] : widget public de prise de rendez-vous.
 */
class Lion_RDV_Shortcode {

	public function __construct() {
		add_shortcode( 'lion_rdv_booking', array( $this, 'render' ) );
	}

	public function render( $atts ) {
		wp_enqueue_style(
			'lion-rdv-booking',
			LION_RDV_PLUGIN_URL . 'assets/css/booking-widget.css',
			array(),
			LION_RDV_VERSION
		);

		wp_enqueue_script(
			'lion-rdv-booking',
			LION_RDV_PLUGIN_URL . 'assets/js/booking-widget.js',
			array(),
			LION_RDV_VERSION,
			true
		);

		$services = array();
		foreach ( Lion_RDV_Settings::get_settings()['services'] as $key => $service ) {
			$services[] = array(
				'key'         => $key,
				'label'       => $service['label'],
				'description' => $service['description'],
			);
		}

		wp_localize_script(
			'lion-rdv-booking',
			'lionRdvSettings',
			array(
				'restUrl'  => esc_url_raw( rest_url( 'lion-rdv/v1' ) ),
				'services' => $services,
				'i18n'    => array(
					'chooseService'   => __( 'Quel type de rendez-vous souhaitez-vous prendre ?', 'lion-rdv-booking' ),
					'loadingSlots'    => __( 'Chargement des créneaux disponibles…', 'lion-rdv-booking' ),
					'noSlots'         => __( 'Aucun créneau disponible pour le moment. Merci de nous contacter directement.', 'lion-rdv-booking' ),
					'chooseDay'       => __( 'Choisissez un jour', 'lion-rdv-booking' ),
					'chooseTime'      => __( 'Choisissez un horaire', 'lion-rdv-booking' ),
					'back'            => __( '← Retour', 'lion-rdv-booking' ),
					'yourInfo'        => __( 'Vos coordonnées', 'lion-rdv-booking' ),
					'firstName'       => __( 'Prénom', 'lion-rdv-booking' ),
					'lastName'        => __( 'Nom', 'lion-rdv-booking' ),
					'phone'           => __( 'Téléphone', 'lion-rdv-booking' ),
					'email'           => __( 'Email', 'lion-rdv-booking' ),
					'address'         => __( 'Adresse d\'intervention', 'lion-rdv-booking' ),
					'postalCode'      => __( 'Code postal', 'lion-rdv-booking' ),
					'city'            => __( 'Ville', 'lion-rdv-booking' ),
					'message'         => __( 'Précisez votre besoin (optionnel)', 'lion-rdv-booking' ),
					'confirm'         => __( 'Confirmer le rendez-vous', 'lion-rdv-booking' ),
					'sending'         => __( 'Envoi en cours…', 'lion-rdv-booking' ),
					'selectedSlot'    => __( 'Créneau sélectionné', 'lion-rdv-booking' ),
					'genericError'    => __( 'Une erreur est survenue. Merci de réessayer.', 'lion-rdv-booking' ),
					'startOver'       => __( 'Prendre un autre rendez-vous', 'lion-rdv-booking' ),
				),
			)
		);

		ob_start();
		?>
		<div id="lion-rdv-widget" class="lion-rdv-widget" aria-live="polite"></div>
		<?php
		return ob_get_clean();
	}
}
