# Deploy-артефакты (шаблоны)

Файлы в `systemd/` — примеры для целевого хоста. Пути, пользователь и путь к PHP
должны быть заменены под реальную инфраструктуру. Локально юниты не запускались
и перезапуск процессов не проверялся.

## Установка (выполняет ответственный за инфраструктуру)

1. Скопировать юниты в `/etc/systemd/system/`, заменить `www-data`,
   `/srv/corpms/current` и `/usr/bin/php`.
2. Включить только нужные сервисы:
   - scheduler: `systemctl enable --now corpms-scheduler.timer`;
   - queue worker: `systemctl enable --now corpms-worker.service`;
   - Reverb (только при `BROADCAST_CONNECTION=reverb`): `systemctl enable --now corpms-reverb.service`.
3. После релиза выполнить `php artisan queue:restart`, затем проверить
   `systemctl --failed`, `journalctl -u corpms-worker -n 100` и свежесть heartbeat.

## Heartbeat

- Scheduler пишет `ops.health.last_run` в cache при каждом запуске
  `php artisan corpms:health` из расписания. Ключ должен обновляться чаще 15 минут.
- Worker и Reverb heartbeat ведутся внешним мониторингом: см. `monitoring/CHECKLIST.md`.
- Отсутствие свежего heartbeat, failed jobs или роста backlog — сигнал тревоги,
  а не причина автоматически перезапускать БД или менять данные.
