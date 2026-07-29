# Lion Rénovation – Prise de RDV Dépannage & Entretien

Plugin WordPress permettant aux visiteurs du site de réserver eux-mêmes un
créneau de dépannage ou d'entretien, en fonction des disponibilités réelles
de l'agenda **InterFast**.

## Installation

1. Copiez le dossier `lion-rdv-booking/` dans `wp-content/plugins/` de votre
   site WordPress (ou zippez-le et installez-le depuis Extensions > Ajouter).
2. Activez l'extension **« Lion Rénovation - Prise de RDV Dépannage &
   Entretien »**.
3. Allez dans **Réglages > Prise de RDV Lion** et renseignez :
   - votre **clé API InterFast** (générée dans InterFast : *Profil > Sécurité*)
   - l'**URL de base de l'API** InterFast
   - les **horaires d'ouverture** par jour (matin / après-midi)
   - les **durées** et **délais de préavis** pour le dépannage et l'entretien
   - l'**email de notification interne** (par défaut : l'email admin du site)
4. Cliquez sur **« Tester la connexion InterFast »** pour vérifier que tout
   fonctionne.
5. Ajoutez le shortcode `[lion_rdv_booking]` sur la page « Prise de rendez-vous »
   de votre site.

## Intégration InterFast

Confirmé via `https://developers.inter-fast.fr/` (référence OpenAPI) :

- Serveur API : `https://app.inter-fast.fr` (les chemins incluent déjà `/v1`,
  pas de préfixe `/api`)
- Authentification par en-tête `X-API-KEY`
- `GET /v1/events` : vue unifiée du planning (interventions, RDV, absences,
  tâches), sert à calculer les créneaux libres
- `POST /v1/client/particular` : création du client CRM associé à la
  réservation (InterFast référence les interventions à un client existant,
  pas à des coordonnées en texte libre)
- `POST /v1/intervention` : création de l'intervention (`clientId` +
  `addressId` obtenus à l'étape précédente)

**Important** : l'accès à l'API InterFast est réservé aux comptes avec
l'abonnement **Business**.

**Limite connue à améliorer** : chaque réservation en ligne crée un nouveau
client dans le CRM InterFast, même si le client existe déjà (pas de
recherche/déduplication préalable — l'endpoint `GET /v1/client/search`
existe mais son schéma exact n'a pas été vérifié). Si les doublons
deviennent gênants, complétez `create_particular_client()` dans
`lion-rdv-booking/includes/class-lion-rdv-interfast-client.php` pour
rechercher un client existant (par email/téléphone) avant d'en créer un
nouveau.

Pour filtrer les disponibilités sur un technicien précis (et lui assigner
automatiquement les nouvelles interventions), renseignez son identifiant
numérique InterFast dans le champ **« ID technicien InterFast »** des
réglages.

## Fonctionnement

1. Le visiteur choisit **Dépannage** ou **Entretien**.
2. Le plugin interroge InterFast pour connaître les événements déjà planifiés
   sur les prochains jours, puis calcule les créneaux encore libres à partir
   de vos horaires d'ouverture.
3. Le visiteur choisit un jour puis un horaire, renseigne ses coordonnées et
   valide.
4. Le plugin revérifie que le créneau est toujours libre (pour limiter les
   doubles réservations), puis crée l'intervention dans InterFast.
5. Un email de confirmation est envoyé au client, et une notification
   interne est envoyée à l'adresse configurée. En cas d'échec de
   synchronisation avec InterFast, le client est informé qu'il sera
   recontacté, et la réservation est journalisée avec l'erreur dans
   **Réglages > Prise de RDV Lion > Voir les réservations récentes** pour
   suivi manuel.

## Anti-spam

Le formulaire de réservation étant public, il inclut un champ piège
(honeypot) invisible ainsi qu'une limite de 5 réservations par heure et par
adresse IP.

## Personnalisation visuelle

Les couleurs du widget sont définies via des variables CSS en haut de
`assets/css/booking-widget.css` (`--lion-rdv-primary`, etc.) : modifiez-les
pour correspondre à la charte graphique de Lion Rénovation.
