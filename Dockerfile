# Используем официальный образ PHP
FROM php:8.2-cli

# Устанавливаем необходимые зависимости
RUN apt-get update && apt-get install -y \
    libzip-dev \
    libpq-dev \
    nginx \
    && docker-php-ext-install zip pdo pdo_pgsql

# Устанавливаем Composer
RUN php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" \
    && php composer-setup.php --install-dir=/usr/local/bin --filename=composer \
    && php -r "unlink('composer-setup.php');"

# Устанавливаем рабочую директорию
WORKDIR /app

# Копируем файлы проекта в контейнер
COPY . .

# Устанавливаем зависимости через Composer
RUN composer install

# Копируем конфигурацию Nginx
COPY nginx.conf /etc/nginx/sites-available/default

# Запускаем Nginx и PHP-FPM
CMD ["bash", "-c", "make start"]