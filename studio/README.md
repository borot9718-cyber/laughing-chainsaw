# KOVA AI STUDIO

Extension backend-first de KOVA. Le Studio appelle la passerelle KOVA existante et l’API OpenAI uniquement côté serveur.

## Installation rapide

1. Déployer le dossier `studio/` sous la racine web KOVA.
2. Copier `studio/.env.example` vers `.env.studio` hors du dépôt.
3. Renseigner `KOVA_BASE_URL`, `KOVA_GATEWAY_TOKEN` et `OPENAI_API_KEY`.
4. Ajouter la règle `/studio` indiquée dans `studio/INSTALL.md`.
5. Configurer le cron déjà prévu par KOVA (`publish-scheduled.php`).

Aucune publication simulée n’est utilisée. Le bouton publier appelle `/api/gateway/publications` de KOVA.
