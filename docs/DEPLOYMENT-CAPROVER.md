# Deploying on CapRover

CapRover runs **one container per app**, so the repository has a single-container image for it: nginx, PHP-FPM, the queue worker (e-mail) and the scheduler (nightly backup) run together in the app. The database and Redis are two separate CapRover apps. (For a server of your own with Docker Compose, see [DEPLOYMENT-DOCKER.md](DEPLOYMENT-DOCKER.md).)

The repository has a `captain-definition` file at its root that points CapRover to the `Dockerfile`; the last stage of that file is the single-container image. If CapRover says *"Captain Definition file does not exist"*, the branch you are deploying does not have that file yet.

## 1. The database and Redis (one-click apps)

In **Apps → One-Click Apps/Databases**:

1. **MySQL** (version 8): name it `paa-db` and set a root password. Its address from other apps is `srv-captain--paa-db`.
2. **Redis**: name it `paa-redis` and set a password. Its address is `srv-captain--paa-redis`.

## 2. The application

1. Create an app named `paa` (tick *Has Persistent Data*). Enable **HTTPS** and *Force HTTPS* for its domain.
2. **App Configs → Persistent Directories**: add `/var/www/html/storage` (a label such as `paa-storage`). This keeps uploaded logos, logs and **backups** across deployments; without it they are lost every time you deploy.
3. **App Configs → Environmental Variables → Bulk Edit**, paste and fill in:

```ini
APP_NAME=Plano de Ação Anual (PAA)
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:...                     # generate one: php artisan key:generate --show (on your computer), or any 32 random bytes in base64
APP_URL=https://paa.your-domain
APP_LOCALE=tet
APP_FALLBACK_LOCALE=pt
LOG_CHANNEL=stderr
LOG_LEVEL=warning
TRUSTED_PROXIES=*
SESSION_SECURE_COOKIE=true

DB_CONNECTION=mysql
DB_HOST=srv-captain--paa-db
DB_PORT=3306
DB_DATABASE=paa
DB_USERNAME=root
DB_PASSWORD=<the MySQL root password>

REDIS_CLIENT=predis
REDIS_HOST=srv-captain--paa-redis
REDIS_PORT=6379
REDIS_PASSWORD=<the Redis password>
SESSION_DRIVER=redis
CACHE_STORE=redis
QUEUE_CONNECTION=redis

# first start: create the database if needed, build it, create the roles and the first administrator
AUTO_CREATE_DB=true
RUN_MIGRATIONS=true
SEED_IF_EMPTY=true
ADMIN_NAME=Administrator
ADMIN_EMAIL=admin@your-domain
ADMIN_PASSWORD=<at least 12 characters>

# e-mail notifications (optional; an administrator also switches them on in Settings)
MAIL_MAILER=smtp
MAIL_HOST=smtp.your-provider
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS=paa@your-domain
```

`AUTO_CREATE_DB` needs a database user that may create databases (root here). If you prefer, create the database yourself with its own user, and leave `AUTO_CREATE_DB` out.

4. **Deployment**: deploy the repository by any of CapRover's methods (GitHub/GitLab webhook, `caprover deploy`, or an uploaded tar). The first build takes several minutes (it compiles the PHP extensions and the front-end).

## 3. After the deployment

- Open `https://paa.your-domain` and sign in with `ADMIN_EMAIL` and `ADMIN_PASSWORD`; change the password at once. The seeder runs only while there is **no user**, so it never touches your data on later deployments; migrations do run on every start.
- The app logs (CapRover → *App Logs*) show `[paa] ...` lines for each step; `GET /up` answers 200 when the app is running.
- **Keep the number of instances at 1**: the scheduler runs inside the container, and a second instance would run the nightly backup twice.
- The app needs about 512 MB of memory.

## Backups

The scheduler makes a gzip-compressed `mysqldump` every night at 02:00 in `/var/www/html/storage/app/backups` (kept 30 days; `BACKUP_KEEP_DAYS`). That folder is inside the persistent directory, **on the same server as the database**: copy it off the server too. Either collect it from the persistent volume, or add a second persistent directory (for example `/backups`, mapped to a folder or a mounted share) and set `BACKUP_COPY_PATH=/backups`.

## Updating

Deploy again. The migrations run when the container starts. Take a backup first if you can.

## If something goes wrong

| Message in the logs | What it means |
|---|---|
| `APP_KEY is not set` | Add `APP_KEY` to the environment variables. |
| `the database did not answer in time` | Check `DB_HOST` (`srv-captain--paa-db`), the password, and that the MySQL app is running. |
| `Access denied` or `Unknown database` | The database or user does not exist: set `AUTO_CREATE_DB=true` with the root user, or create them. |
| Pages load without styling, or links start with `http://` | `APP_URL` must be the `https://` address, with `TRUSTED_PROXIES=*` and *Force HTTPS* on. |
