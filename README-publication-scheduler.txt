Publication planifiée

Ajoutez une tâche cron toutes les 5 minutes :
*/5 * * * * cd /chemin/vers/htdocs && php tools/publish-scheduled.php >> storage/scheduler.log 2>&1

Le site reste compatible sans cron : une publication devient visible automatiquement après scheduled_at, mais le script met aussi à jour publication_status et published_at.
