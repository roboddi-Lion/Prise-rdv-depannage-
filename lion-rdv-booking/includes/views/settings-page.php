<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$settings   = Lion_RDV_Settings::get_settings();
$test_result = null;

if ( isset( $_GET['lion_rdv_tested'] ) ) {
	$stored = get_transient( 'lion_rdv_test_connection_result' );
	delete_transient( 'lion_rdv_test_connection_result' );

	// Le paramètre `lion_rdv_tested` peut se retrouver dans l'URL après un
	// simple enregistrement des réglages (WordPress réutilise l'URL de
	// retour du formulaire) : on n'affiche le résultat que s'il a
	// effectivement été retrouvé (le transient a pu être déjà consommé/expiré).
	$test_result = is_array( $stored ) ? $stored : null;
}

$day_labels = array(
	'lundi'    => __( 'Lundi', 'lion-rdv-booking' ),
	'mardi'    => __( 'Mardi', 'lion-rdv-booking' ),
	'mercredi' => __( 'Mercredi', 'lion-rdv-booking' ),
	'jeudi'    => __( 'Jeudi', 'lion-rdv-booking' ),
	'vendredi' => __( 'Vendredi', 'lion-rdv-booking' ),
	'samedi'   => __( 'Samedi', 'lion-rdv-booking' ),
	'dimanche' => __( 'Dimanche', 'lion-rdv-booking' ),
);
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Prise de RDV Lion Rénovation - Réglages', 'lion-rdv-booking' ); ?></h1>

	<p>
		<?php esc_html_e( 'Insérez le shortcode suivant sur la page de prise de rendez-vous de votre site :', 'lion-rdv-booking' ); ?>
		<code>[lion_rdv_booking]</code>
	</p>

	<p>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=lion-rdv-bookings' ) ); ?>" class="button">
			<?php esc_html_e( 'Voir les réservations récentes', 'lion-rdv-booking' ); ?>
		</a>
	</p>

	<?php if ( null !== $test_result ) : ?>
		<div class="notice <?php echo $test_result['success'] ? 'notice-success' : 'notice-error'; ?>" style="padding:10px;">
			<?php if ( $test_result['success'] ) : ?>
				<p><strong><?php esc_html_e( 'Connexion InterFast réussie.', 'lion-rdv-booking' ); ?></strong>
				<?php
				printf(
					/* translators: %d: number of events found */
					esc_html__( '%d événement(s) trouvé(s) sur les prochaines 24h.', 'lion-rdv-booking' ),
					count( $test_result['events'] )
				);
				?>
				</p>
				<?php if ( ! empty( $test_result['report_types'] ) ) : ?>
					<p><strong><?php esc_html_e( 'Modèles de rapport disponibles (à copier dans la colonne « ID de modèle de rapport » du tableau ci-dessous, pour chaque type de rendez-vous) :', 'lion-rdv-booking' ); ?></strong></p>
					<ul style="list-style:disc;margin-left:20px;">
						<?php foreach ( $test_result['report_types'] as $type ) : ?>
							<li><code><?php echo esc_html( $type['id'] ); ?></code> — <?php echo esc_html( $type['name'] ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php else : ?>
					<p><?php esc_html_e( 'Aucun modèle de rapport récupéré automatiquement : renseignez l\'ID de modèle de rapport manuellement (Modèles de rapports dans InterFast).', 'lion-rdv-booking' ); ?></p>
				<?php endif; ?>
			<?php else : ?>
				<p><strong><?php esc_html_e( 'Échec de la connexion InterFast :', 'lion-rdv-booking' ); ?></strong> <?php echo esc_html( $test_result['error'] ); ?></p>
				<p><?php esc_html_e( 'Vérifiez la clé API, l\'URL de base, et au besoin les noms d\'endpoints dans includes/class-lion-rdv-interfast-client.php (voir le README).', 'lion-rdv-booking' ); ?></p>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<form method="post" action="options.php">
		<?php settings_fields( 'lion_rdv_settings_group' ); ?>

		<h2><?php esc_html_e( 'Connexion InterFast', 'lion-rdv-booking' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="interfast_api_key"><?php esc_html_e( 'Clé API InterFast', 'lion-rdv-booking' ); ?></label></th>
				<td>
					<input type="password" id="interfast_api_key" name="<?php echo esc_attr( Lion_RDV_Settings::OPTION_KEY ); ?>[interfast_api_key]" value="<?php echo esc_attr( $settings['interfast_api_key'] ); ?>" class="regular-text" autocomplete="off" />
					<p class="description"><?php esc_html_e( 'Générée dans InterFast : Profil > Sécurité.', 'lion-rdv-booking' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="interfast_api_base_url"><?php esc_html_e( 'URL de base de l\'API', 'lion-rdv-booking' ); ?></label></th>
				<td>
					<input type="text" id="interfast_api_base_url" name="<?php echo esc_attr( Lion_RDV_Settings::OPTION_KEY ); ?>[interfast_api_base_url]" value="<?php echo esc_attr( $settings['interfast_api_base_url'] ); ?>" class="regular-text" />
					<p class="description"><?php esc_html_e( 'À confirmer auprès du support InterFast ou de developers.inter-fast.fr si la connexion échoue.', 'lion-rdv-booking' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="interfast_resource_id"><?php esc_html_e( 'ID technicien InterFast (optionnel)', 'lion-rdv-booking' ); ?></label></th>
				<td>
					<input type="text" id="interfast_resource_id" name="<?php echo esc_attr( Lion_RDV_Settings::OPTION_KEY ); ?>[interfast_resource_id]" value="<?php echo esc_attr( $settings['interfast_resource_id'] ); ?>" class="regular-text" />
					<p class="description"><?php esc_html_e( 'Identifiant numérique d\'un utilisateur InterFast (technicien). Laissez vide pour vérifier la disponibilité sur l\'ensemble du planning ; renseigné, les créneaux seront limités à ce technicien et les nouvelles interventions lui seront assignées.', 'lion-rdv-booking' ); ?></p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Types de rendez-vous proposés', 'lion-rdv-booking' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Chaque type de rendez-vous a son propre modèle de rapport InterFast (reportTypeId, obligatoire). Cliquez sur « Tester la connexion » plus bas pour voir la liste de vos modèles disponibles et copier le bon ID.', 'lion-rdv-booking' ); ?></p>
		<table class="widefat" style="max-width:1100px;margin-bottom:1.5em;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Nom affiché', 'lion-rdv-booking' ); ?></th>
					<th><?php esc_html_e( 'Description affichée', 'lion-rdv-booking' ); ?></th>
					<th><?php esc_html_e( 'Durée (min)', 'lion-rdv-booking' ); ?></th>
					<th><?php esc_html_e( 'Préavis min. (h)', 'lion-rdv-booking' ); ?></th>
					<th><?php esc_html_e( 'Priorité', 'lion-rdv-booking' ); ?></th>
					<th><?php esc_html_e( 'ID de modèle de rapport (reportTypeId)', 'lion-rdv-booking' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $settings['services'] as $service_key => $service ) : ?>
				<tr>
					<td><input type="text" name="<?php echo esc_attr( Lion_RDV_Settings::OPTION_KEY ); ?>[services][<?php echo esc_attr( $service_key ); ?>][label]" value="<?php echo esc_attr( $service['label'] ); ?>" class="regular-text" /></td>
					<td><input type="text" name="<?php echo esc_attr( Lion_RDV_Settings::OPTION_KEY ); ?>[services][<?php echo esc_attr( $service_key ); ?>][description]" value="<?php echo esc_attr( $service['description'] ); ?>" class="regular-text" /></td>
					<td><input type="number" min="15" step="15" name="<?php echo esc_attr( Lion_RDV_Settings::OPTION_KEY ); ?>[services][<?php echo esc_attr( $service_key ); ?>][duration_minutes]" value="<?php echo esc_attr( $service['duration_minutes'] ); ?>" class="small-text" /></td>
					<td><input type="number" min="0" name="<?php echo esc_attr( Lion_RDV_Settings::OPTION_KEY ); ?>[services][<?php echo esc_attr( $service_key ); ?>][lead_time_hours]" value="<?php echo esc_attr( $service['lead_time_hours'] ); ?>" class="small-text" /></td>
					<td>
						<select name="<?php echo esc_attr( Lion_RDV_Settings::OPTION_KEY ); ?>[services][<?php echo esc_attr( $service_key ); ?>][importance_level]">
							<option value="normal" <?php selected( $service['importance_level'], 'normal' ); ?>><?php esc_html_e( 'Normale', 'lion-rdv-booking' ); ?></option>
							<option value="high" <?php selected( $service['importance_level'], 'high' ); ?>><?php esc_html_e( 'Haute', 'lion-rdv-booking' ); ?></option>
						</select>
					</td>
					<td><input type="text" required name="<?php echo esc_attr( Lion_RDV_Settings::OPTION_KEY ); ?>[services][<?php echo esc_attr( $service_key ); ?>][report_type_id]" value="<?php echo esc_attr( $service['report_type_id'] ); ?>" class="regular-text" /></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="horizon_days"><?php esc_html_e( 'Réservable jusqu\'à (jours à l\'avance)', 'lion-rdv-booking' ); ?></label></th>
				<td><input type="number" min="1" max="180" id="horizon_days" name="<?php echo esc_attr( Lion_RDV_Settings::OPTION_KEY ); ?>[horizon_days]" value="<?php echo esc_attr( $settings['horizon_days'] ); ?>" class="small-text" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="slot_step_minutes"><?php esc_html_e( 'Intervalle entre créneaux proposés (min)', 'lion-rdv-booking' ); ?></label></th>
				<td><input type="number" min="5" step="5" id="slot_step_minutes" name="<?php echo esc_attr( Lion_RDV_Settings::OPTION_KEY ); ?>[slot_step_minutes]" value="<?php echo esc_attr( $settings['slot_step_minutes'] ); ?>" class="small-text" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="notification_email"><?php esc_html_e( 'Email de notification interne', 'lion-rdv-booking' ); ?></label></th>
				<td><input type="email" id="notification_email" name="<?php echo esc_attr( Lion_RDV_Settings::OPTION_KEY ); ?>[notification_email]" value="<?php echo esc_attr( $settings['notification_email'] ); ?>" class="regular-text" /></td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Horaires d\'ouverture', 'lion-rdv-booking' ); ?></h2>
		<table class="widefat" style="max-width:900px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Jour', 'lion-rdv-booking' ); ?></th>
					<th><?php esc_html_e( 'Ouvert', 'lion-rdv-booking' ); ?></th>
					<th><?php esc_html_e( 'Matin début', 'lion-rdv-booking' ); ?></th>
					<th><?php esc_html_e( 'Matin fin', 'lion-rdv-booking' ); ?></th>
					<th><?php esc_html_e( 'Après-midi début', 'lion-rdv-booking' ); ?></th>
					<th><?php esc_html_e( 'Après-midi fin', 'lion-rdv-booking' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $day_labels as $day_key => $day_label ) : $day = $settings['hours'][ $day_key ]; ?>
				<tr>
					<td><?php echo esc_html( $day_label ); ?></td>
					<td><input type="checkbox" name="<?php echo esc_attr( Lion_RDV_Settings::OPTION_KEY ); ?>[hours][<?php echo esc_attr( $day_key ); ?>][open]" <?php checked( $day['open'] ); ?> /></td>
					<td><input type="time" name="<?php echo esc_attr( Lion_RDV_Settings::OPTION_KEY ); ?>[hours][<?php echo esc_attr( $day_key ); ?>][matin_debut]" value="<?php echo esc_attr( $day['matin_debut'] ); ?>" /></td>
					<td><input type="time" name="<?php echo esc_attr( Lion_RDV_Settings::OPTION_KEY ); ?>[hours][<?php echo esc_attr( $day_key ); ?>][matin_fin]" value="<?php echo esc_attr( $day['matin_fin'] ); ?>" /></td>
					<td><input type="time" name="<?php echo esc_attr( Lion_RDV_Settings::OPTION_KEY ); ?>[hours][<?php echo esc_attr( $day_key ); ?>][apres_midi_debut]" value="<?php echo esc_attr( $day['apres_midi_debut'] ); ?>" /></td>
					<td><input type="time" name="<?php echo esc_attr( Lion_RDV_Settings::OPTION_KEY ); ?>[hours][<?php echo esc_attr( $day_key ); ?>][apres_midi_fin]" value="<?php echo esc_attr( $day['apres_midi_fin'] ); ?>" /></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description"><?php esc_html_e( 'Laissez les champs après-midi vides pour une journée continue ou une demi-journée.', 'lion-rdv-booking' ); ?></p>

		<?php submit_button( __( 'Enregistrer les réglages', 'lion-rdv-booking' ) ); ?>
	</form>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="lion_rdv_test_connection" />
		<?php wp_nonce_field( 'lion_rdv_test_connection' ); ?>
		<?php submit_button( __( 'Tester la connexion InterFast', 'lion-rdv-booking' ), 'secondary' ); ?>
	</form>
</div>
