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

## ⚠️ Point à vérifier avant mise en production : l'intégration InterFast

Confirmé via `https://developers.inter-fast.fr/` (référence OpenAPI) :

- Serveur API : `https://app.inter-fast.fr` (les chemins incluent déjà `/v1`,
  pas de préfixe `/api`)
- Authentification par en-tête `X-API-KEY`
- `GET /v1/events` : vue unifiée du planning, sert à calculer les créneaux libres
- `POST /v1/intervention` : création d'une intervention

**Important** : l'accès à l'API InterFast est réservé aux comptes avec
l'abonnement **Business**.

La doc n'a listé que les chemins d'endpoints, pas encore le détail des
paramètres/schémas (accès direct à developers.inter-fast.fr bloqué depuis
l'environnement de développement). Avant la mise en production, **ouvrez ces
deux endpoints dans la doc et confirmez** (puis ajustez si besoin
`lion-rdv-booking/includes/class-lion-rdv-interfast-client.php`, zones
clairement commentées) :

- `GET /v1/events` : noms exacts des paramètres de filtrage par date, et
  forme de la réponse (`data`, `items`, tableau brut…)
- `POST /v1/intervention` : schéma exact du corps attendu — en particulier,
  le client est-il référencé via un `client_id` existant (module CRM séparé
  visible dans la doc), ou peut-il être envoyé en objet inline comme
  actuellement dans le code ? Si un `client_id` est requis, il faudra ajouter
  un appel préalable de recherche/création du client dans le module CRM.

Le bouton **« Tester la connexion InterFast »** dans les réglages du plugin
permet de valider rapidement ces ajustements sans avoir à modifier le code
du widget public.

Si votre besoin est plutôt de filtrer les disponibilités sur un technicien
ou une équipe précise, renseignez son identifiant dans le champ **« ID
ressource / technicien »** des réglages (transmis tel quel dans les appels
API — le nom exact du paramètre peut aussi nécessiter un ajustement selon la
doc InterFast).

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
