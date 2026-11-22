# Чек-лист мониторинга процессов

Пороги утверждаются на целевой инфраструктуре. Ниже — минимальные проверки,
которые должны быть подключены к алертингу до публичного релиза.

| Объект | Проверка | Условие тревоги |
|---|---|---|
| Scheduler | `php artisan corpms:health --json` и `cache:get ops.health.last_run` | ключ старше 15 минут или nonzero health |
| Queue worker | `SELECT COUNT(*), MIN(created_at) FROM jobs` | backlog растёт или старейшая задача старше порога |
| Failed jobs | `SELECT COUNT(*) FROM failed_jobs` | любая новая запись за интервал |
| Reverb | TCP/HTTP health на `REVERB_HOST:REVERB_PORT` | порт недоступен при `BROADCAST_CONNECTION=reverb` |
| Backups | дата последнего `BACKUP_PATH` и манифест ZIP | свежесть/контрольная сумма нарушена |
| HTTP/TLS | внешний HTTP-мониторинг с проверкой сертификата | 5xx или истёкший TLS |

Проверки worker/Reverb и внешний HTTP/TLS-мониторинг в локальном окружении не выполнялись.
