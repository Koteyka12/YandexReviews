FROM php:8.3-fpm-alpine

# Системные зависимости
RUN apk add --no-cache \
    nginx \
    nodejs \
    npm \
    git \
    curl \
    zip \
    unzip \
    libpng-dev \
    libjpeg-turbo-dev \
    libwebp-dev \
    sqlite \
    sqlite-dev \
    oniguruma-dev \
    icu-dev

# PHP расширения
RUN docker-php-ext-install \
    pdo \
    pdo_sqlite \
    mbstring \
    intl \
    opcache

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Копируем зависимости отдельным слоем для кэша
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY package.json package-lock.json ./
RUN npm ci

# Копируем весь проект
COPY . .

# Финализируем composer и строим фронт
RUN composer dump-autoload --optimize \
    && npm run build

# Настройка прав
RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Nginx конфиг
COPY docker/nginx.conf /etc/nginx/nginx.conf

# Entrypoint
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
