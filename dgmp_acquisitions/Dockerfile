FROM php:8.2-apache

# Installer les dépendances système pour PostgreSQL
RUN apt-get update && apt-get install -y libpq-dev

# Installer les extensions PHP pdo et pdo_pgsql
RUN docker-php-ext-install pdo pdo_pgsql

# Copier votre code dans le dossier du serveur web
COPY . /var/www/html/

# Donner les droits d'accès
RUN chown -R www-data:www-data /var/www/html/

# Exposer le port 80
EXPOSE 80
