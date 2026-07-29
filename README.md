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
   - l'**URL de base de l'API** InterFast (`https://app.inter-fast.fr`)
   - les **horaires d'ouverture** par jour (matin / après-midi)
   - les **durées** et **délais de préavis** pour le dépannage et l'entretien
   - l'**email de notification interne** (par défaut : l'email admin du site)
   - les **ID de modèle de rapport** Dépannage et Entretien (`reportTypeId`,
     obligatoires) — préremplis avec les modèles « Dépannage » et « Entretien
     de chaudière à gaz », à ajuster si besoin (voir étape suivante)
4. Cliquez sur **« Tester la connexion InterFast »** : la liste complète de
   vos modèles de rapport InterFast s'affiche, pour changer l'un ou l'autre
   des deux ID si les valeurs préremplies ne correspondent pas à votre
   activité (ex. entretien de chaudière fioul/bois plutôt que gaz).
5. Ajoutez le shortcode `[lion_rdv_booking]` sur la page « Prise de rendez-vous »
   de votre site.

## Intégration InterFast

Confirmé via `https://developers.inter-fast.fr/` (référence OpenAPI) :

- Serveur API : `https://app.inter-fast.fr` (les chemins incluent déjà `/v1`,
  pas de préfixe `/api`)
- Authentification par en-tête `X-API-KEY`
- `GET /v1/events` : vue unifiée du planning (interventions, RDV, absences,
  tâches), sert à calculer les créneaux libres
- `GET /v1/crm/search` : recherche d'un client existant par email avant
  d'en créer un nouveau (évite les doublons dans le CRM)
- `POST /v1/client/particular` : création du client CRM si aucun client
  existant ne correspond (InterFast référence les interventions à un
  client existant, pas à des coordonnées en texte libre)
- `POST /v1/intervention` : création de l'intervention (`clientId`,
  `addressId`, `reportTypeId` obtenus/configurés en amont)

**Important** : l'accès à l'API InterFast est réservé aux comptes avec
l'abonnement **Business**.

**Anti-double-réservation** : en plus de revérifier la disponibilité auprès
d'InterFast juste avant de créer l'intervention, un verrou côté WordPress
(30 secondes) empêche deux visiteurs de valider le même créneau en même
temps sur le widget.

**Déduplication client** : avant de créer un client, le plugin cherche un
client existant dont l'email correspond exactement (`GET /v1/crm/search`
puis vérification stricte du champ `email` retourné). Si trouvé, ce client
est réutilisé ; sinon un nouveau client "particulier" est créé. Si la
recherche échoue (API indisponible), le plugin se rabat sur la création
d'un nouveau client plutôt que de bloquer la réservation.

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
