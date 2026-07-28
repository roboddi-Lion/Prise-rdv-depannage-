<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'Action non autorisée.', 'lion-rdv-booking' ) );
}

$bookings = Lion_RDV_DB::get_recent_bookings( 100 );

$status_labels = array(
	'confirmed' => __( 'Confirmée', 'lion-rdv-booking' ),
	'failed'    => __( 'Échec InterFast', 'lion-rdv-booking' ),
	'pending'   => __( 'En attente', 'lion-rdv-booking' ),
);
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Réservations récentes', 'lion-rdv-booking' ); ?></h1>
	<p><a href="<?php echo esc_url( admin_url( 'options-general.php?page=lion-rdv-booking' ) ); ?>">&larr; <?php esc_html_e( 'Retour aux réglages', 'lion-rdv-booking' ); ?></a></p>

	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Créé le', 'lion-rdv-booking' ); ?></th>
				<th><?php esc_html_e( 'Créneau', 'lion-rdv-booking' ); ?></th>
				<th><?php esc_html_e( 'Service', 'lion-rdv-booking' ); ?></th>
				<th><?php esc_html_e( 'Client', 'lion-rdv-booking' ); ?></th>
				<th><?php esc_html_e( 'Coordonnées', 'lion-rdv-booking' ); ?></th>
				<th><?php esc_html_e( 'Adresse', 'lion-rdv-booking' ); ?></th>
				<th><?php esc_html_e( 'Statut', 'lion-rdv-booking' ); ?></th>
				<th><?php esc_html_e( 'InterFast', 'lion-rdv-booking' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php if ( empty( $bookings ) ) : ?>
			<tr><td colspan="8"><?php esc_html_e( 'Aucune réservation pour le moment.', 'lion-rdv-booking' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $bookings as $booking ) : ?>
			<tr>
				<td><?php echo esc_html( mysql2date( 'd/m/Y H:i', $booking->created_at ) ); ?></td>
				<td><?php echo esc_html( mysql2date( 'd/m/Y H:i', $booking->slot_start ) . ' - ' . mysql2date( 'H:i', $booking->slot_end ) ); ?></td>
				<td><?php echo esc_html( 'entretien' === $booking->service_type ? __( 'Entretien', 'lion-rdv-booking' ) : __( 'Dépannage', 'lion-rdv-booking' ) ); ?></td>
				<td><?php echo esc_html( $booking->first_name . ' ' . $booking->last_name ); ?></td>
				<td><?php echo esc_html( $booking->phone ); ?><br /><?php echo esc_html( $booking->email ); ?></td>
				<td><?php echo esc_html( $booking->address . ', ' . $booking->postal_code . ' ' . $booking->city ); ?></td>
				<td>
					<?php $status = $booking->status; ?>
					<span style="font-weight:600;color:<?php echo 'confirmed' === $status ? '#1a7f37' : ( 'failed' === $status ? '#c62828' : '#996800' ); ?>;">
						<?php echo esc_html( $status_labels[ $status ] ?? $status ); ?>
					</span>
				</td>
				<td>
					<?php if ( $booking->interfast_event_id ) : ?>
						#<?php echo esc_html( $booking->interfast_event_id ); ?>
					<?php elseif ( $booking->interfast_error ) : ?>
						<span title="<?php echo esc_attr( $booking->interfast_error ); ?>" style="color:#c62828;">⚠ <?php esc_html_e( 'voir erreur', 'lion-rdv-booking' ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
</div>
