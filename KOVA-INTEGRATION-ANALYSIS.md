# KOVA INTEGRATION ANALYSIS

## Architecture constatée

KOVA est une application PHP 8.1+ sans framework externe, avec stockage JSON par défaut et support SQL optionnel. Le dépôt de développement est aplati pour consultation, mais le code référence l’arborescence historique `app/Core`, `bootstrap`, `resources` et `tools`.

## Authentification et OAuth

KOVA possède une authentification session native, CSRF, rate limiting, 2FA TOTP et OAuth 2.0 Authorization Code + PKCE. Le Studio n’implémente pas une seconde authentification KOVA et ne reçoit pas de secret dans le navigateur.

## Passerelle et publication

Routes vérifiées dans `App.php` :

- `GET /api/gateway/handshake`
- `GET /api/gateway/publication-style`
- `POST /api/gateway/publications`
- `GET/POST /api/admin/gateway`
- `POST /api/admin/gateway/token`

La publication accepte le contenu, une image URL ou image_data Base64, et `scheduled_at` lorsqu’il est pris en charge par la version déployée.

## Scheduler

`publish-scheduled.php` et sa documentation existent à la racine dans le dépôt aplati. Ils doivent être remis dans `tools/` lors du déploiement de l’arborescence historique. Le Studio transmet la programmation à KOVA et ne crée aucun scheduler concurrent.

## Stockage

KOVA utilise `JsonStore` par défaut. Le Studio stocke uniquement son historique technique dans `storage/studio`, isolé par permissions serveur. Les publications KOVA restent dans le stockage KOVA.

## Risques / actions manuelles

- Vérifier que le token gateway est activé et limité au serveur.
- Vérifier le support de `scheduled_at` dans la copie effectivement déployée.
- Régénérer `APP_KEY` si `app_secret.key` a été exposé.
- Ne pas déployer les fichiers secrets du dépôt public.
- Restaurer les fichiers aplatis dans leurs chemins historiques avant exécution en production.

## Fichiers ajoutés

Le module isolé est sous `studio/`. Aucun fichier métier KOVA existant n’est remplacé.
