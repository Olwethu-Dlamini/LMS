FROM php:8.2-apache

# PDO MySQL driver is required by config/database.php
RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite

# Make DB_* container env vars visible to getenv() inside mod_php
RUN printf 'PassEnv DB_HOST DB_PORT DB_NAME DB_USER DB_PASS\n' \
    > /etc/apache2/conf-available/lms-env.conf \
    && a2enconf lms-env

# Surface PHP warnings/notices during UAT instead of silently blank-paging
RUN printf 'display_errors=On\nerror_reporting=E_ALL\nlog_errors=On\nerror_log=/dev/stderr\nupload_max_filesize=10M\npost_max_size=12M\ndate.timezone=Africa/Johannesburg\n' \
    > /usr/local/etc/php/conf.d/zz-lms-uat.ini

WORKDIR /var/www/html
