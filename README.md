# Bitrix24 XLSX import

## 1. Настройка

Открой `config.php` и вставь webhook Bitrix24.

Положи рядом исходный файл `companies (5).xlsx`.

Собери и запусти Docker:

```bash
docker compose build
docker compose up -d
```

После этого открой:

```text
http://SERVER:8080/setup.php
```

`setup.php` создаёт нужные поля и сохраняет `setup.json`.

## 2. Создание очередей

После настройки:

```bash
docker compose exec bitrix-import php xlsx_to_json.php
```

Будут созданы:

```text
queues/distributors.json
queues/contacts.json
queues/companies.json
```

## 3. Запуск импорта

Один рабочий запуск:

```bash
docker compose exec bitrix-import php worker.php
```

Worker идёт по этапам:

1. дистрибьюторы
2. контакты
3. компании

Каждый следующий запуск продолжает с текущего места.

## 4. Cron

На сервере можно запускать каждые 2 минуты:

```cron
*/2 * * * * cd /path/to/bitrix-import && docker compose exec -T bitrix-import php worker.php >> logs/cron.log 2>&1
```

После проверки `setup.php` можно удалить.

## Важно

Не удаляй `queues` во время импорта — там хранится состояние очереди.
