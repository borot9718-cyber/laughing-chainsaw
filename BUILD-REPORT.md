# BUILD REPORT

## Implémenté

- Module autonome `studio/` sans modification destructive du cœur KOVA.
- Client serveur KOVA avec timeout, Bearer token et gestion des réponses.
- Handshake KOVA.
- Génération de texte via OpenAI côté serveur.
- Historique JSON local Studio.
- Publication réelle via `/api/gateway/publications`.
- Programmation réelle via `scheduled_at` transmis à KOVA.
- Interface mobile-first et PWA manifest.
- `.env.example` sans secrets.
- Documentation d’installation et d’intégration.

## Non inclus volontairement

- Aucun mock de publication.
- Aucun compte fictif.
- Aucun scheduler parallèle.
- Génération image/BD/campagnes avancées à compléter après validation du fournisseur image et du format exact de la version déployée de KOVA.

## Limitation importante

Le dépôt consulté est aplati alors que les chemins internes du code attendent `app/Core`, `bootstrap`, `resources` et `tools`. Le déploiement final doit restaurer l’arborescence historique. Le scheduler a été retrouvé à la racine de la copie de consultation et doit être placé dans `tools/` en production.
