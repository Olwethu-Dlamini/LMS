FROM php:8.2-apache

# PDO MySQL driver is required by config/database.php
RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite

# Make APP_URL, DB_* and MAIL_* container env vars visible to getenv() inside
# mod_php. Apache does not pass its environment to PHP unless each name is listed
# here, and a name left out fails silently: getenv() returns false, so
# config/constants.php falls through to its development default and nothing
# reports a problem.
#
# APP_URL is on this list because config/constants.php documents the environment
# as a working configuration layer. Without the name here that promise held for
# the cron worker, which reads the real environment, and quietly did not hold for
# the web pages, which read Apache's. The result was a portal on localhost links
# and correct links in its own email.
#
# MAIL_PASSWORD is listed but has no value baked in anywhere: it is passed at run
# time so the mailbox password never enters an image or a tracked file.
RUN printf 'PassEnv APP_URL\nPassEnv DB_HOST DB_PORT DB_NAME DB_USER DB_PASS\nPassEnv MAIL_ENABLED MAIL_HOST MAIL_PORT MAIL_ENCRYPTION MAIL_USERNAME MAIL_PASSWORD MAIL_FROM_ADDRESS MAIL_FROM_NAME MAIL_REPLY_TO MAIL_REDIRECT_TO\n' \
    > /etc/apache2/conf-available/lms-env.conf \
    && a2enconf lms-env

# Surface PHP warnings/notices during UAT instead of silently blank-paging
RUN printf 'display_errors=On\nerror_reporting=E_ALL\nlog_errors=On\nerror_log=/dev/stderr\nupload_max_filesize=10M\npost_max_size=12M\ndate.timezone=Africa/Johannesburg\n' \
    > /usr/local/etc/php/conf.d/zz-lms-uat.ini

WORKDIR /var/www/html
