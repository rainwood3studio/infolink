FROM serversideup/php:8.5-fpm-nginx

USER root

# intl: required by Filament's money/number formatting (Laravel Number helper).
RUN install-php-extensions intl

USER www-data
