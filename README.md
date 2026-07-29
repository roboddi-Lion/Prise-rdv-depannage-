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
   - l'**email de notification interne** (par défaut : l'email admin du site)
   - le tableau **« Types de rendez-vous proposés »** : trois lignes
     préconfigurées (Dépannage, Entretien chaudière, Entretien climatisation
     / PAC), chacune avec son nom affiché, sa description, sa durée, son
     préavis minimum, sa priorité et son **ID de modèle de rapport**
     (`reportTypeId`, obligatoire)
4. Cliquez sur **« Tester la connexion InterFast »** : la liste complète de
   vos modèles de rapport InterFast s'affiche, pour ajuster l'ID de modèle
   d'une ligne si la valeur préremplie ne correspond pas à votre activité
   (ex. entretien de chaudière fioul/bois plutôt que gaz).
5. Ajoutez le shortcode `[lion_rdv_booking]` sur la page « Prise de rendez-vous »
   de votre site.

### Ajouter un nouveau type de rendez-vous

La liste des services proposés (label, description, durée, préavis,
priorité, modèle de rapport) est centralisée dans
`Lion_RDV_Settings::default_services()`
(`lion-rdv-booking/includes/class-lion-rdv-settings.php`). Pour ajouter un
quatrième type de rendez-vous (ex. « Dépannage électrique »), ajoutez une
entrée avec une nouvelle clé dans ce tableau — elle apparaîtra
automatiquement dans les réglages et dans le widget public, sans autre
modification de code.

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

### Plusieurs techniciens : champ « ID techniciens éligibles aux RDV en ligne »

**Important si votre équipe compte plusieurs techniciens dans InterFast.**
Par défaut (champ vide), le plugin vérifie le planning de **toute
l'entreprise** et considère un créneau occupé dès qu'**un seul** technicien
(même un qui ne prend jamais de RDV en ligne) a quelque chose de prévu — sur
un planning chargé, ça peut faire disparaître presque tous les créneaux.

Renseignez la liste des identifiants numériques InterFast des techniciens
pouvant recevoir un RDV pris en ligne (ex. `12, 34, 56`). Le plugin
considère alors un créneau libre dès qu'**au moins un** de ces techniciens
n'a rien de prévu, et lui assigne automatiquement l'intervention
(`primaryTechnicianId`) au moment de la réservation.

## Fonctionnement

1. Le visiteur choisit son type de rendez-vous (Dépannage, Entretien
   chaudière, Entretien climatisation / PAC, ou tout autre service ajouté).
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
