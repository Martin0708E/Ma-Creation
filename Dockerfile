FROM php:8.2-cli

RUN apt-get update && apt-get install -y libpq-dev

RUN docker-php-ext-install pdo pdo_pgsql

WORKDIR /app

COPY . .

# On dit à PHP de chercher les fichiers dans dgmp_acquisitions
CMD php -S 0.0.0.0:$PORT -t dgmp_acquisitions