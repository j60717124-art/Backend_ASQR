# Imagen oficial de PHP con Apache
FROM php:8.2-apache

# Instalar dependencias y extensiones necesarias
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    zip \
    libzip-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    default-mysql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        mysqli \
        pdo \
        pdo_mysql \
        zip \
        gd \
        mbstring \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Habilitar mod_rewrite
RUN a2enmod rewrite

# Copiar el proyecto
COPY . /var/www/html/

# Configurar DocumentRoot
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/*.conf \
    /etc/apache2/apache2.conf \
    /etc/apache2/conf-available/*.conf

# Permisos
RUN chown -R www-data:www-data /var/www/html

# Puerto
EXPOSE 80

# Iniciar Apache
CMD ["apache2-foreground"]