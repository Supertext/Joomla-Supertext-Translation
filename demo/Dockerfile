# Supertext Joomla demo: Joomla 6.1 + German (CH), French and Italian + this plugin.
# Build context is the repository root:
#   docker build -f demo/Dockerfile -t supertext-joomla-demo .
# Everything persistent is in PostgreSQL (DATABASE_URL), including configuration.php;
# the container keeps no files between deploys. See docs/DEVELOPER.md -> Demo.

FROM php:8.3-apache

ARG JOOMLA_VERSION=6.1.4
ARG JOOMLA_LANGUAGES="de-CH fr-FR it-IT"

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev libpq-dev \
      unzip zip curl ca-certificates \
 && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
 && docker-php-ext-install -j"$(nproc)" intl zip gd opcache pgsql pdo_pgsql mysqli pdo_mysql \
 && a2enmod rewrite headers expires deflate \
 && rm -rf /var/lib/apt/lists/*

# Joomla core and language packs, downloaded at build time.
RUN mkdir -p /var/www/joomla /opt/demo/languages \
 && curl -fsSL -o /tmp/joomla.zip "https://github.com/joomla/joomla-cms/releases/download/${JOOMLA_VERSION}/Joomla_${JOOMLA_VERSION}-Stable-Full_Package.zip" \
 && unzip -q /tmp/joomla.zip -d /var/www/joomla && rm /tmp/joomla.zip \
 && cp /var/www/joomla/htaccess.txt /var/www/joomla/.htaccess \
 && mv /var/www/joomla/installation /opt/demo/installation \
 && for tag in $JOOMLA_LANGUAGES; do \
      url=$(curl -fsSL "https://update.joomla.org/language/details6/${tag}_details.xml" | grep -o '<downloadurl[^>]*>[^<]*' | head -1 | sed 's/<[^>]*>//'); \
      curl -fsSL -o "/opt/demo/languages/${tag}.zip" "$url"; \
    done

# This plugin and the demo-only setup command, packaged as installable zips.
COPY plugin /tmp/plugin
COPY demo/plg_console_supertextdemo /tmp/demoplugin
RUN cd /tmp/plugin && zip -qr /opt/demo/plg_system_supertext.zip . \
 && cd /tmp/demoplugin && zip -qr /opt/demo/plg_console_supertextdemo.zip . \
 && rm -rf /tmp/plugin /tmp/demoplugin

COPY demo/apache.conf /etc/apache2/sites-available/000-default.conf
COPY demo/php.ini /usr/local/etc/php/conf.d/joomla.ini
COPY demo/createdb.php demo/state.php /opt/demo/
COPY demo/entrypoint.sh /usr/local/bin/joomla-entrypoint
RUN chmod +x /usr/local/bin/joomla-entrypoint

ENTRYPOINT ["joomla-entrypoint"]
CMD ["apache2-foreground"]
