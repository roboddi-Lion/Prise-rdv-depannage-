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
   - le tableau **« Types de rendez-vous proposés »** : sept lignes
     préconfigurées (Entretien climatisation, Entretien PAC, Entretien
     chaudière gaz, Entretien chaudière fioul, Dépannage climatisation/PAC,
     Dépannage chaudière, Dépannage plomberie), chacune avec une case
     **« Actif »** (décochez pour retirer ce service du widget public sans
     perdre sa configuration), son nom affiché, sa description, sa durée,
     son préavis minimum, sa priorité, son **ID de modèle de rapport**
     (`reportTypeId`, obligatoire), ses **ID techniciens** (voir ci-dessous)
     et un **email de notification spécifique** optionnel — les 3 services
     de dépannage sont préremplis avec `contact@lion-renovation.fr` en plus
     de l'email de notification interne général
4. Cliquez sur **« Tester la connexion InterFast »** : la liste complète de
   vos modèles de rapport InterFast s'affiche, pour ajuster l'ID de modèle
   d'une ligne si la valeur préremplie ne correspond pas à votre activité —
   en particulier pour les 3 services de dépannage, préremplis avec le
   modèle générique « Dépannage » faute de modèle plus spécifique trouvé
   dans votre catalogue.
5. Pour chaque ligne, renseignez les **ID techniciens** (identifiants
   numériques InterFast, séparés par des virgules) habilités à réaliser ce
   type d'intervention.
6. Ajoutez le shortcode `[lion_rdv_booking]` sur la page « Prise de rendez-vous »
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

### Techniciens par type de rendez-vous

**Important si votre équipe compte plusieurs techniciens dans InterFast.**
Chaque service (ligne du tableau « Types de rendez-vous proposés ») a sa
propre liste de techniciens (`technician_ids`). Un créneau n'est proposé
pour ce service que si **au moins un** des techniciens listés est libre, et
celui qui est libre lui est automatiquement assigné (`primaryTechnicianId`)
au moment de la réservation.

Si la liste est laissée vide pour un service, le plugin vérifie le planning
de **toute l'entreprise** et considère un créneau occupé dès qu'**un seul**
technicien (même sans rapport avec ce service) a quelque chose de prévu —
sur un planning chargé, ça peut faire disparaître presque tous les
créneaux. Laissez donc toujours au moins un technicien renseigné par service
dès que vous avez plusieurs techniciens.

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

## Email de confirmation qui n'arrive pas

WordPress envoie ses emails via `wp_mail()`, qui utilise par défaut la
fonction PHP `mail()` du serveur. Sur la majorité des hébergements
mutualisés modernes, cette fonction est soit désactivée, soit non
configurée (aucun logiciel d'envoi de mail installé), soit les emails
partent bien mais sont rejetés par le destinataire faute d'enregistrements
SPF/DKIM valides pour le domaine d'envoi. **C'est la cause la plus probable
si les confirmations n'arrivent pas.**

Pour diagnostiquer : **Réglages > Prise de RDV Lion > Voir les réservations
récentes**, colonne **« Email client »** — indique si l'envoi a réussi, et
en cas d'échec, survolez l'icône ⚠ pour voir le message d'erreur exact
(WordPress le fournit rarement en détail, mais c'est un indice).

**Solution recommandée** : installez un plugin SMTP (ex. **WP Mail SMTP**,
gratuit) et connectez-le à un vrai service d'envoi transactionnel (Brevo,
Mailgun, Amazon SES, ou même un compte Gmail/Outlook en dépannage). Ce
plugin fonctionne automatiquement avec `wp_mail()` sans modification du
code de ce plugin.

## Anti-spam

Le formulaire de réservation étant public, il inclut un champ piège
(honeypot) invisible ainsi qu'une limite de 5 réservations par heure et par
adresse IP.

## Personnalisation visuelle

Le style est calé sur la charte actuelle de **lion-renovation.fr** (bleu
`#0d4995`, polices Outfit/Montserrat, rayon 8px, ombres teintées bleu,
petite barre de marque ambre/terracotta/bleu/marine). Le widget est
présenté comme un encart blanc arrondi avec ombre, sur le même modèle que
le formulaire de contact existant du site (`.request-form`).

Les couleurs sont définies via des variables CSS en haut de
`assets/css/booking-widget.css` (`--lion-rdv-primary`, etc.) — ce sont les
mêmes noms de variables que le plugin « Prise de RDV Devis » (agendas
Google Calendar) : si la charte du site venait à changer, reportez les
nouvelles valeurs dans les deux fichiers CSS pour que les deux widgets
restent visuellement identiques.
