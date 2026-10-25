# Vau · imagem única para o painel (Apache + PHP) e para o worker.
FROM docker:27-cli AS docker-cli

FROM php:8.3-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends git libpq-dev curl \
 && docker-php-ext-install pdo_mysql pdo_pgsql pcntl \
 && a2enmod headers \
 && rm -rf /var/lib/apt/lists/*

COPY --from=docker-cli /usr/local/bin/docker /usr/local/bin/docker
COPY docker/apache-vau.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php-vau.ini /usr/local/etc/php/conf.d/vau.ini
COPY docker/entrypoint.sh /usr/local/bin/vau-entrypoint

WORKDIR /app
COPY . /app
RUN chmod +x /usr/local/bin/vau-entrypoint bin/vau \
 && mkdir -p storage/logs storage/workspaces \
 && chown -R www-data:www-data storage

ENV VAU_APP_URL=http://localhost:8080 \
    VAU_RUNTIME=docker

EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --retries=3 CMD curl -fsS http://127.0.0.1/health || exit 1
ENTRYPOINT ["vau-entrypoint"]
CMD ["apache2-foreground"]
