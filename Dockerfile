FROM php:8.2-apache

# Instalar extensões PHP necessárias (MySQLi e GMP)
RUN apt-get update && apt-get install -y \
    libgmp-dev \
    && docker-php-ext-install mysqli gmp \
    && docker-php-ext-enable mysqli gmp

# Copia os arquivos do projeto
COPY . /var/www/html/

# Habilita mod_rewrite
RUN a2enmod rewrite

# Configura permissões
RUN chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html

EXPOSE 80
