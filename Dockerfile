# Dockerfile — dev + test image for the pottery portfolio.
# Apache mirrors the Bluehost production environment and honors the project's
# .htaccess files. Dev dependencies (PHPUnit) are included so the same image
# serves both the `web` and `test` compose services.

FROM php:8.2-apache

ENV DEBIAN_FRONTEND=noninteractive \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1

RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev libjpeg-dev libwebp-dev libfreetype6-dev \
        libzip-dev libonig-dev \
        unzip git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd pdo pdo_mysql zip mbstring \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/apache/000-default.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php/custom.ini /usr/local/etc/php/conf.d/zz-custom.ini
COPY docker/entrypoint.sh /usr/local/bin/pottery-entrypoint.sh
RUN chmod +x /usr/local/bin/pottery-entrypoint.sh

WORKDIR /var/www/html

# Install PHP deps with dev requirements (PHPUnit, etc.) so the `test` service
# can run the suite without a second image build. The repo is bind-mounted at
# runtime; an anonymous volume on /var/www/html/vendor preserves these deps.
COPY composer.json composer.lock ./
RUN composer install --optimize-autoloader --no-scripts --no-progress

COPY . .

RUN mkdir -p public/uploads/pottery public/uploads/products \
             public/uploads/hero    public/uploads/profile \
             public/uploads/templates \
 && chown -R www-data:www-data public/uploads

# Stash the uploads/.htaccess at a path *outside* the named volume so the
# entrypoint can copy it into the volume on every boot. compose.yml mounts a
# named volume over public/uploads/ to persist images across rebuilds, but
# that mount also hides the .htaccess we bundled there. /usr/local/share/ is
# never volume-mounted, so the file survives there as the canonical seed.
RUN cp public/uploads/.htaccess /usr/local/share/pottery-uploads-htaccess

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/pottery-entrypoint.sh"]
CMD ["apache2-foreground"]
