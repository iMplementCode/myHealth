# ============================================================
#  myHealth — nginx + php-fpm
# ------------------------------------------------------------
#  Replaces the nixpacks start command, which serves the site
#  with `php -S`. PHP's own manual says that server "should not
#  be used on a public network", and the reason is not pedantry:
#  it has no request timeouts, no connection limits, and handles
#  PHP_CLI_SERVER_WORKERS requests at a time — four, as shipped.
#
#  Four is the whole pharmacy. Three staff with a page open and
#  one PDF rendering, and the fourth person waits. One person
#  opening slow connections on purpose takes the site down
#  without a botnet or a flaw to exploit.
#
#  php-fpm handles this properly: a pool of workers, a queue in
#  front of it, a ceiling on how long any one request may hold a
#  worker, and nginx refusing the traffic that should never reach
#  PHP at all.
#
#  Build it with Dokploy pointed at this file
#  (deploy/Dockerfile) rather than at nixpacks. Nothing changes
#  until you do, which is deliberate: a live pharmacy should not
#  change how it is served because a file appeared in a commit.
# ============================================================

FROM php:8.3-fpm-alpine

#  pdo_pgsql is the only extension the application needs that is
#  not already in the image. libpq is the runtime half of it;
#  postgresql-dev is only needed while compiling, so it goes
#  again in the same layer.
RUN apk add --no-cache nginx libpq gettext \
 && apk add --no-cache --virtual .build-deps postgresql-dev \
 && docker-php-ext-install -j"$(nproc)" pdo_pgsql opcache \
 && apk del .build-deps \
 && rm -rf /var/cache/apk/*

#  opcache: the application is a few hundred PHP files and every
#  request parses the ones it needs. Compiled once and kept, a
#  page costs a fraction of what it does without. revalidate off
#  because the code in a container never changes under it — the
#  container is replaced instead.
RUN { \
      echo 'opcache.enable=1'; \
      echo 'opcache.enable_cli=0'; \
      echo 'opcache.memory_consumption=128'; \
      echo 'opcache.max_accelerated_files=10000'; \
      echo 'opcache.validate_timestamps=0'; \
      echo 'opcache.interned_strings_buffer=16'; \
    } > /usr/local/etc/php/conf.d/opcache.ini

#  Production php.ini, stated rather than inherited. display_errors
#  off because a stack trace on a page names paths and sometimes
#  query text; the log still gets everything.
RUN { \
      echo 'display_errors=Off'; \
      echo 'display_startup_errors=Off'; \
      echo 'log_errors=On'; \
      echo 'error_log=/dev/stderr'; \
      echo 'expose_php=Off'; \
      echo 'memory_limit=256M'; \
      echo 'post_max_size=8M'; \
      echo 'upload_max_filesize=5M'; \
      echo 'max_execution_time=30'; \
      echo 'session.use_strict_mode=1'; \
    } > /usr/local/etc/php/conf.d/zz-production.ini

#  The pool. pm=dynamic with a real ceiling: 24 workers at the
#  ~30MB this application uses per request is comfortable inside
#  1GB, and is six times what the built-in server managed.
#
#  max_requests recycles a worker periodically, which costs
#  nothing and ends any slow leak in a long-running process.
RUN { \
      echo '[www]'; \
      echo 'user = www-data'; \
      echo 'group = www-data'; \
      echo 'listen = /run/php-fpm.sock'; \
      echo 'listen.owner = www-data'; \
      echo 'listen.group = www-data'; \
      echo 'pm = dynamic'; \
      echo 'pm.max_children = 24'; \
      echo 'pm.start_servers = 6'; \
      echo 'pm.min_spare_servers = 4'; \
      echo 'pm.max_spare_servers = 12'; \
      echo 'pm.max_requests = 500'; \
      echo 'request_terminate_timeout = 60s'; \
      echo 'catch_workers_output = yes'; \
      echo 'decorate_workers_output = no'; \
      echo 'clear_env = no'; \
    } > /usr/local/etc/php-fpm.d/zz-pool.conf

#  How nginx talks to the pool. One file, included by every PHP
#  location in the site config, so the settings cannot drift
#  between them.
RUN { \
      echo 'fastcgi_pass  unix:/run/php-fpm.sock;'; \
      echo 'fastcgi_index index.php;'; \
      echo 'fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;'; \
      echo 'include       fastcgi_params;'; \
      echo 'fastcgi_read_timeout 60s;'; \
      echo 'fastcgi_send_timeout 60s;'; \
      echo 'fastcgi_buffers 16 16k;'; \
      echo 'fastcgi_buffer_size 32k;'; \
      echo 'fastcgi_hide_header X-Powered-By;'; \
    } > /etc/nginx/fastcgi-php.conf

WORKDIR /app

#  Dependencies first, so a change to application code does not
#  reinstall them. composer.lock is committed, so this is the
#  same set of packages every build.
COPY composer.json composer.lock ./
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN composer install --no-dev --no-interaction --no-progress --optimize-autoloader \
 && composer clear-cache

COPY . /app

#  Uploads are the only writable path. Everything else being
#  read-only to the web user means a bug that writes a file
#  cannot write a PHP file into the document root.
RUN mkdir -p /app/public/uploads /run \
 && chown -R www-data:www-data /app/public/uploads \
 && chmod -R 755 /app \
 && chmod -R 775 /app/public/uploads

COPY deploy/nginx-site.conf /etc/nginx/http.d/default.conf.template

#  nginx reads no environment variables of its own, so PORT is
#  substituted into the config at start-up. envsubst touches only
#  ${PORT} and leaves every $variable nginx needs — $uri,
#  $document_root — alone, which is why they are named here.
#
#  Migrations run before anything listens, and `|| true` keeps a
#  database that is briefly unreachable from stopping the site:
#  the application is written to survive being one migration
#  behind and says so on screen to an administrator.
RUN printf '%s\n' \
    '#!/bin/sh' \
    'set -e' \
    ': "${PORT:=3000}"' \
    'export PORT' \
    'envsubst "\$PORT" < /etc/nginx/http.d/default.conf.template > /etc/nginx/http.d/default.conf' \
    'php /app/database_structure/migrate.php || true' \
    'php-fpm -D' \
    'exec nginx -g "daemon off;"' \
    > /usr/local/bin/start.sh \
 && chmod +x /usr/local/bin/start.sh

EXPOSE 3000

#  Liveness only: it answers before the application bootstraps,
#  so a database that is down reports as a database that is down
#  rather than as a dead container to be killed and restarted.
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1:" . (getenv("PORT") ?: 3000) . "/health.php") ? 0 : 1);'

CMD ["/usr/local/bin/start.sh"]
