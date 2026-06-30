FROM php:8.2-cli

# 1. Installer les dépendances système pour PostgreSQL
RUN apt-get update && apt-get install -y libpq-dev

# 2. Installer les extensions pdo et pdo_pgsql
RUN docker-php-ext-install pdo pdo_pgsql

# 3. Définir le dossier de travail
WORKDIR /app

# 4. Copier tous les fichiers de votre projet dans le container
COPY . .

# 5. Lancer le serveur PHP sur le port dynamique de Railway
# Le 0.0.0.0 est obligatoire pour que le site soit accessible
CMD ["php", "-S", "0.0.0.0:8080", "-t", "."]