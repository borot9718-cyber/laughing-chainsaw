# Sécurité KOVA

La sécurité applicative est centralisée dans `app/Core/security.php` et activée dès le bootstrap.

Protections intégrées :
- sessions PHP durcies et cookies HttpOnly/SameSite/Secure en HTTPS ;
- invalidation des sessions après changement de mot de passe ;
- jeton CSRF pour les mutations API ;
- contrôle d'origine pour les requêtes navigateur ;
- limitation des tentatives d'inscription, connexion, récupération et demandes sensibles ;
- en-têtes HTTP de sécurité et CSP ;
- protection des répertoires internes par `.htaccess` ;
- blocage des scripts PHP dans les répertoires de stockage/upload ;
- hachage des mots de passe ;
- jetons de récupération de mot de passe stockés uniquement sous forme hachée ;
- OAuth avec redirect URI exacte, PKCE pour les clients publics, codes à usage unique et révocation ;
- journalisation limitée des événements de sécurité ;
- export de données sans mots de passe, secrets ni jetons ;
- suppression/anonymisation des données personnelles selon leur catégorie.

Avant déploiement :
- conserver les secrets uniquement dans `.env` ;
- utiliser HTTPS ;
- conserver `APP_DEBUG=false` ;
- vérifier régulièrement les sauvegardes et les permissions du stockage ;
- renouveler immédiatement tout secret qui aurait été exposé hors du serveur.

## Contrôles ajoutés en v3
- **Authentification** : limitation progressive des tentatives (compte+IP, IP, compte) avec preuve de travail auto-hébergée ;
  politique de mots de passe (longueur, variété, liste de mots courants, pas de pseudo/e-mail) ; argon2id/bcrypt 12 avec
  mise à niveau automatique ; temps de réponse constant ; réponses neutres à l'inscription et au renvoi de code ;
  double authentification TOTP + codes de secours (secret chiffré AES-256-GCM au repos).
- **Sessions** : `use_strict_mode`, cookie `__Host-`, identifiant régénéré à chaque changement d'identité, rotation globale
  `SESSION_EPOCH`, version de session par compte (déconnexion globale, suspension, changement de rôle), CSRF rotatif.
- **Navigateur** : CSP `script-src 'self'` (aucun script inline), `style-src` sans `<style>`, COOP/CORP, HSTS, Permissions-Policy.
- **Fichiers** : ré-encodage des images (GD) et noms aléatoires ; `uploads/` sans exécution ; `storage/` protégé même s'il est recréé.
- **Notifications push** : endpoint validé (HTTPS + domaines de services push), charge utile chiffrée (RFC 8291), pas de redirection.
- **Journal** : `security_events` (connexion, échec, 2FA, changement de mot de passe, actions d'administration), IP hachée.

Signalement d'une vulnérabilité : contactez l'administrateur du site (`ADMIN_EMAIL`).
