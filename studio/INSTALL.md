# KOVA AI STUDIO — installation

Le Studio utilise les routes KOVA existantes :

- `GET /api/gateway/handshake`
- `GET /api/gateway/publication-style`
- `POST /api/gateway/publications`

Le scheduler reste celui de KOVA. Ne créez pas de worker concurrent.

## Configuration

Créer `.env.studio` hors Git avec :

- `KOVA_BASE_URL`
- `KOVA_GATEWAY_TOKEN`
- `OPENAI_API_KEY`
- modèles OpenAI

Le token gateway se génère dans l’administration KOVA. Il ne doit jamais être placé dans le JavaScript.

## Apache

Si `/studio` n’est pas servi automatiquement par le dossier physique, ajouter avant la règle générale vers `index.php` :

```apache
RewriteRule ^studio(?:/.*)?$ studio/index.php [L,QSA]
```

## Cron KOVA

Conserver la tâche existante documentée par KOVA :

```cron
*/5 * * * * cd /chemin/vers/htdocs && php tools/publish-scheduled.php >> storage/scheduler.log 2>&1
```

## Sécurité

Ne jamais versionner `.env.studio`, `.env`, `app_secret.key`, tokens ou clés API.
