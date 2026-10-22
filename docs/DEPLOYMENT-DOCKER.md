# Deploying with Docker Compose

This runs the whole application on one server with Docker: **nginx** (web), **PHP-FPM** (the application), a **queue worker** (e-mail), the **scheduler** (nightly backup), **MySQL 8** and **Redis**. Only the web container (or Caddy, for HTTPS) is reachable from outside; the database and Redis stay on the internal network. It is for **production**; for development use the instructions in the README.

## What you need

- A Linux server with Docker Engine and the Compose plugin (or Docker Desktop on Windows/macOS for a trial).
- A name for the application, for example `paa.example.gov.tl`, pointing at the server, if you want HTTPS (Caddy gets and renews the certificate by itself; ports 80 and 443 must be reachable).
- An SMTP account, if you want e-mail notifications.

> **Windows note.** Docker Desktop cannot build from a folder whose path contains non-ASCII characters (for example `Orçamento`): it fails with `non-printable ASCII char`. Clone or copy the project to a plain path such as `C:\paa` before building. On a Linux server this does not arise.

## First installation

```bash
git clone <repository> paa && cd paa
cp .env.production.example .env.production      # fill in every value marked CHANGE; never commit this file

# the application key (copy the printed value into APP_KEY)
docker compose --env-file .env.production -f compose.prod.yml run --rm --no-deps -e WAIT_FOR_DB=false app php artisan key:generate --show

docker compose --env-file .env.production -f compose.prod.yml up -d --build
```

On first start the **app** container waits for the database, builds it from the schema baseline and the migrations, caches the configuration and starts PHP-FPM. Then create the roles, the economic categories, the settings and the first administrator (from `ADMIN_*` in `.env.production`):

```bash
docker compose --env-file .env.production -f compose.prod.yml exec -u www-data app php artisan db:seed --force
```

Open `http://<server>:8080` (the `WEB_PORT`), or, with HTTPS:

```bash
docker compose --env-file .env.production -f compose.prod.yml --profile https up -d
```

and open `https://<APP_DOMAIN>`. Sign in with the administrator, change the password, and set up the institution (Part 4 of the manual). The optional sample data is refused in production on purpose.

> **Tip.** To avoid typing `--env-file` and `-f` every time, set them once in your shell: `export COMPOSE_FILE=compose.prod.yml COMPOSE_ENV_FILES=.env.production`.

## E-mail notifications and the live bell

- The **bell** needs nothing: it checks for new notifications every 20 seconds while a page is open and shows a short message (no websocket server).
- **E-mail** needs three things: the `MAIL_*` values in `.env.production`, the `queue` container running (it is part of the stack), and an administrator switching on **System → Settings → Send notifications by e-mail**. Each person can opt out in their profile. Use an address your mail server allows as `MAIL_FROM_ADDRESS`.
- To test the mail: switch it on, then trigger a notification (for example, send an activity for approval) and follow `docker compose logs -f queue`. A failed mail is retried three times and then listed in the `failed_jobs` table; it never blocks the action that caused it.

## Updating to a new version

```bash
git pull
docker compose --env-file .env.production -f compose.prod.yml up -d --build
```

The migrations run when the **app** container starts (`RUN_MIGRATIONS=true`). Take a backup first (below). The queue worker is restarted with the new code by the same command.

## Backups

The scheduler container runs `paa:backup` every night at 02:00 (gzip-compressed `mysqldump`, kept `BACKUP_KEEP_DAYS` days). Each backup is kept in the storage volume and copied to `BACKUP_HOST_DIR` (default `./backups` on the server). **Copy that folder off the server** (another machine, a mounted share, a cloud bucket): a backup on the same disk is not a backup. Run one now:

```bash
docker compose --env-file .env.production -f compose.prod.yml exec -u www-data scheduler php artisan paa:backup
```

Restore into an empty database with `gunzip -c backups/<file>.sql.gz | docker compose ... exec -T db mysql -uroot -p"$DB_ROOT_PASSWORD" "$DB_DATABASE"`.

`GET /health` reports the state of the database, cache, storage, queue, disk and latest backup; `GET /up` is the plain liveness check.

## Useful commands

| What | Command (after `COMPOSE_FILE` and `COMPOSE_ENV_FILES` are set) |
|---|---|
| State of the services | `docker compose ps` |
| Application logs | `docker compose logs -f app` |
| Queue worker logs (e-mail) | `docker compose logs -f queue` |
| Open a shell in the application | `docker compose exec -u www-data app sh` |
| Run an artisan command | `docker compose exec -u www-data app php artisan <command>` |
| Stop everything (data is kept) | `docker compose down` |

## What is kept where

| Volume | Holds |
|---|---|
| `db-data` | the MySQL database |
| `redis-data` | sessions, cache and queued jobs |
| `storage` | uploaded logos, imports, logs and the backups |
| `caddy-data` | the HTTPS certificates (profile `https`) |

`docker compose down -v` **deletes these volumes**, and with them the database. Never run it on a server in use.

## Security notes

- `.env.production` holds secrets: keep it out of the repository (it is ignored) and restrict who can read it on the server.
- Do not publish ports 3306 (MySQL) or 6379 (Redis); the compose file does not.
- Keep the server and the images up to date (`docker compose pull` for `mysql`, `redis`, `caddy`, then `up -d`; rebuild for the application).
- Production forces `https` links; for a trial over plain http (for example on your own computer), set `APP_URL=http://localhost:8080` and `SESSION_SECURE_COOKIE=false`.
