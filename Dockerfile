FROM php:8.2-apache 
 
# Instalar extensao mysqli 
RUN docker-php-ext-install mysqli && docker-php-ext-enable mysqli 
 
# Copiar arquivos 
COPY . /var/www/html/ 
 
# Habilitar mod_rewrite 
RUN a2enmod rewrite 
 
# Configurar permissoes 
RUN chown -R www-data:www-data /var/www/html && chmod -R 755 /var/www/html 
 
EXPOSE 80 
