# KOVA — rebuild debug

Cette version reconstruit le socle web de KOVA avec un chargement explicite et vérifiable des CSS/JS.

## Point important

Le mode debug est activé par défaut dans `.env.example` :

`APP_DEBUG=true`

Il affiche les erreurs PHP et les erreurs JavaScript/CSS dans une console développeur KOVA visible uniquement lorsque le mode debug est actif.

Pour la production, modifier plus tard :

`APP_DEBUG=false`

## Installation

1. Téléverser tout le dossier à la racine du domaine.
2. Copier `.env.example` vers `.env`.
3. Remplacer les valeurs sensibles.
4. Vérifier que PHP 8.1+ est disponible.
5. Donner les droits d'écriture à `storage/`.
6. Ouvrir le domaine.
7. Vérifier `/health`.
8. Vérifier `/debug/assets` tant que `APP_DEBUG=true`.

Le projet ne dépend pas d'une base MySQL : les données applicatives sont stockées en JSON avec verrouillage et écriture atomique.

## CSS / JS

Toutes les pages utilisent les mêmes assets :

- `/assets/css/kova.css`
- `/assets/js/kova.js`

Les chemins sont absolus afin d'éviter les erreurs lorsque l'on passe de `/inscription` à `/profil`, `/messages`, etc.

Le `.htaccess` laisse les fichiers physiques être servis directement et ne les redirige pas vers PHP.

## Sécurité

Ne jamais laisser `APP_DEBUG=true` sur un site public définitif. PHP recommande l'affichage des erreurs pour le développement, mais de les désactiver en production car elles peuvent révéler des informations sensibles.


---

## Version 2 — fonctionnalités ajoutées

### Ce qui était « affiché mais vide » est maintenant réel
| Zone | Avant | Maintenant |
|---|---|---|
| Barre de recherche | champ sans effet | recherche personnes / groupes / communautés / publications (`#hashtags` inclus), liste déroulante + page `/recherche` |
| Messages | « à venir » | messagerie privée : conversations, images, « Vu », compteur de non-lus, blocage respecté |
| Boutique | « à venir » | catalogue, fiche produit, favoris, panier, commandes, ventes, gestion de ses produits |
| Paramètres › Langue | liste désactivée | français / anglais (interface), mémorisé sur le compte |
| Paramètres › Notifications | simple lien | 5 préférences réelles (j’aime, commentaires, abonnés, groupes, boutique) |
| Notifications | jamais générées | générées (j’aime, commentaires, réponses, abonnés, groupes, commandes) avec lien vers le contenu |
| Communautés | bouton « Ouvrir » sans effet | page de détail : publications, membres, rôles |
| Profil des autres | boutons de modification visibles | lecture seule + Suivre / Message / Bloquer / Signaler |
| Signalement | API sans interface | bouton « Signaler » sur publications, commentaires, profils, groupes |
| Abonnements | absents | suivre / ne plus suivre, onglet « Abonnements » du fil |
| Hashtags, partage | texte brut / lien inopérant | hashtags cliquables, lien de partage qui ouvre la publication |

### Groupes façon Facebook
* **Public** : tout le monde voit les publications ; rejoindre est immédiat.
* **Privé** : la fiche (nom, description, nombre de membres) est visible, mais **publications, commentaires et liste des membres sont réservés aux membres** — appliqué côté serveur (lecture, commentaires, réactions).
* Rejoindre un groupe privé = **demande**, que **seul le créateur** peut approuver ou refuser (onglet « Demandes », notification à chaque étape).
* Le créateur modifie, supprime, exclut, nomme des modérateurs. Les modérateurs suppriment des publications.

### Profils : chacun ne modifie que le sien
Cause du défaut : aucune règle CSS `[hidden]`, donc `display:inline-flex` des boutons annulait l’attribut `hidden` et les commandes d’édition restaient visibles sur le profil d’autrui. Corrigé (CSS + éléments masqués par défaut + refus serveur de tout `user_id` étranger).

### Vérification d’e-mail par code à 6 chiffres
* Code envoyé par e-mail, valable **15 minutes**, **5 essais**, renvoi possible toutes les **60 s**.
* Jamais stocké en clair (HMAC signé avec `APP_KEY`).
* `EMAIL_VERIFICATION_REQUIRED=true` (dans `.env`) bloque la connexion tant que l’e-mail n’est pas vérifié.

### « Continuer avec KOVA » (KOVA = fournisseur d’identité pour vos autres plateformes)
* Protocole : OAuth 2.0 Authorization Code + PKCE, jetons Bearer, refresh, révocation, métadonnées.
* Endpoints : `/oauth/authorize`, `/oauth/token`, `/oauth/userinfo`, `/oauth/revoke`, `/.well-known/oauth-authorization-server`.
* Enregistrez vos plateformes dans **Développeurs** (`/developpeurs`). Un client PHP prêt à l’emploi : `tools/oauth-client-example/kova-login.php`.
* Les utilisateurs gèrent/retirent les accès dans **Paramètres › Applications connectées**. L’admin peut « vérifier » ou désactiver une application.

### Nouvelles variables `.env` (voir `.env.example`)
`EMAIL_VERIFICATION_REQUIRED`, `OAUTH_ENABLED`, `OAUTH_APP_CREATORS`, `STORE_CURRENCY`.

### Sécurité
* Protection CSRF : toute requête d’écriture doit porter `X-Requested-With` (déjà envoyé par le JS).
* Comptes suspendus/bannis déconnectés immédiatement.
* En-têtes `nosniff`, `Referrer-Policy`, `X-Frame-Options` ; écran de consentement OAuth non « iframable ».
* **À faire en production** : supprimer `api-test.php`, `kova-check.php`, `test-*.html/php`, `i.php` ; ne jamais publier `.env`.


---

## Version 3 — sécurité renforcée, notifications téléphone, paramètres complets

### ⚠ Faille critique corrigée (introduite dans la v2)
`auth/verify-code` ouvrait une session pour **n'importe quel compte déjà vérifié** avec son e-mail et un code quelconque
(`Auth::verifyCode()` renvoyait « ok » pour un compte vérifié et l'API connectait alors l'utilisateur).
Corrigé : un compte vérifié n'a plus aucun code valable, la réponse est identique à celle d'un code faux, et un test de
non-régression figure dans `kova-check.php`. **Après déploiement, changez `SESSION_EPOCH` dans `.env`** (ex. 4) : toutes les
sessions existantes — y compris celles qu'un attaquant aurait pu ouvrir — sont invalidées. Consultez aussi la table
`security_events` (événements `login_success` inattendus) et faites changer les mots de passe des comptes sensibles.

### Réponse au rapport d'audit
| Constat | Traitement |
|---|---|
| F-01 Pas de limitation des tentatives | `Guard` : blocage 15 min → 24 h par couple (compte + IP) après 5 échecs, plafond par IP, « captcha » par preuve de travail auto-hébergée dès 3 échecs, délai aléatoire, temps de réponse constant (hachage factice) |
| F-02 Mots de passe faibles | `Passwords` : 10–72 car., 2 familles, pas de répétition/suite, liste de mots courants + variantes « leet », interdit e-mail/pseudo ; argon2id (ou bcrypt 12) avec mise à niveau automatique ; jauge en direct |
| F-03 Session non renouvelée | `session.use_strict_mode`, cookies `__Host-` HttpOnly/Secure/SameSite, identifiant régénéré à chaque connexion / 2FA / changement de mot de passe, liaison au navigateur, rotation globale `SESSION_EPOCH`, expiration inactive + absolue. *Cause probable : la session était démarrée par `Security::boot()` avant `Auth`, donc les paramètres de cookie d'Auth n'étaient jamais appliqués.* |
| F-04 CSP `unsafe-inline` | `script-src 'self'` seul : plus aucun script inline (valeurs passées par `data-*`), `script-src-attr 'none'`, `<style>` interdit |
| F-05 Énumération à l'inscription | Réponse identique que l'adresse existe ou non ; le titulaire reçoit un avis par e-mail |
| F-06 Métadonnées OAuth filtrées | `/api/oauth/metadata` (chemin non filtré) ; exemple client affiché dans la page Développeurs |
| F-07 Upload d'images | `ImageStore` : type réel, dimensions, **ré-encodage GD** (détruit EXIF/GPS et polyglottes), noms aléatoires ; **stockage local de secours** si Cloudinary est injoignable ; `uploads/.htaccess` sans exécution de script |
| F-08 Déconnexion par lien | `GET /deconnexion` affiche une confirmation ; seule la requête POST + jeton CSRF déconnecte |
| F-09 robots.txt | Exception `<Files "robots.txt">` dans `.htaccess` (la règle `.txt` le bloquait), sitemap avec dates |
| Reco. 2FA | TOTP (Google Authenticator, Authy…), codes de secours, obligation possible pour les admins (`ADMIN_2FA_REQUIRED`) |

Autres durcissements : jeton CSRF renouvelé à chaque changement d'identité ; comptes suspendus déconnectés immédiatement ;
un administrateur ne peut pas suspendre un autre administrateur ; secrets (hachages, 2FA, jetons) jamais renvoyés au
navigateur ni exportés ; e-mail plus jamais utilisé comme nom d'affichage ; avis par e-mail (mot de passe modifié, 2FA,
déconnexion globale) ; `JsonStore` verrouillé (`flock`) et dossier `storage/` auto-protégé ; limitation de débit atomique ;
détection de l'IP réelle derrière un proxy (`TRUSTED_PROXY_HEADER`) ; endpoint push protégé contre le SSRF.

### Nouveautés fonctionnelles
* **Recherche du tableau de bord** : personnes, groupes, communautés, publications, **produits**, avec filtres (aussi sur `/recherche`).
* **Notifications sur le téléphone** (pop-up dans la barre de notification, avec autorisation) :
  Web Push chiffré (RFC 8291 + VAPID) *et* mode de secours tant que KOVA est ouvert. Réglage et test dans Paramètres.
  ⚠ Le push serveur exige que l'hébergeur autorise les connexions HTTPS **sortantes** ; sinon seul le mode de secours fonctionne.
* **Paramètres opérationnels** : 2FA, déconnexion de tous les appareils, thème clair/sombre/auto, langue, notifications
  (push + 6 catégories), « Qui peut m'écrire ? », visibilité dans la recherche, utilisateurs bloqués, applications
  connectées, export / demandes / suppression de compte (avec fenêtres sécurisées à la place de `prompt()`).
* **Messages** : notifications (badge + push), respect du réglage « Qui peut m'écrire ? ».
* Photos réduites côté téléphone avant envoi (2048 px max) : plus rapide, moins de données, GPS jamais envoyé.

### Diagnostic
`kova-check.php` a été réécrit : lecture seule, réservé à un administrateur connecté (ou à la ligne de commande) et à
`CHECK_ENABLED=true`, aucun compte réel créé, tests fonctionnels sur stockage temporaire (politique de mots de passe,
régression verify-code, 2FA, verrouillage, preuve de travail, ré-encodage d'une image piégée, chiffrement Web Push),
contrôle de syntaxe de tous les fichiers PHP, cohérence routes JS ↔ PHP, exposition web des fichiers sensibles, en-têtes.
En ligne de commande : `php kova-check.php` ou `php kova-check.php --json`.

### Déploiement
1. Sauvegardez `storage/` et `.env` (le zip ne les contient pas). Décompressez par-dessus le site.
2. Ajoutez à `.env` les nouvelles clés (voir `.env.example`) : au minimum `APP_KEY`, `SESSION_EPOCH` (nouvelle valeur), `VAPID_SUBJECT`.
3. `CHECK_ENABLED=true` → ouvrez `/kova-check.php` en administrateur → corrigez les ⚠/✘ → remettez `CHECK_ENABLED=false`.
4. Activez la 2FA sur votre compte administrateur (Paramètres › Sécurité), puis `ADMIN_2FA_REQUIRED=true`.
5. Supprimez `api-test.php`, `i.php`, `test-*` et, si vous n'en avez plus besoin, `kova-check.php`.
