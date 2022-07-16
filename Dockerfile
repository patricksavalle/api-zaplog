FROM php:8.0.13-apache

RUN apt-get update && \
    apt-get install -y libz-dev apt-utils git unzip libzip-dev && \
    docker-php-ext-install zip pdo pdo_mysql && \
    apt-get clean && \
    rm -rf /var/lib/apt/lists/*

ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

RUN chmod +x /usr/local/bin/install-php-extensions && \
    install-php-extensions gd apcu && \
    php --ini

WORKDIR /var/www/html/

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" && \
    echo "apc.shm_size=16M" >> "$PHP_INI_DIR/php.ini"

COPY . /var/www/html/

RUN rm /var/log/apache2/access.log
RUN ln -s /dev/null /var/log/apache2/access.log

RUN chmod +x /var/www/html/composer.phar && \
    /var/www/html/composer.phar selfupdate && \
    /var/www/html/composer.phar update && \
   echo "ServerName localhost" >> /etc/apache2/apache2.conf

EXPOSE 80
CMD ["/usr/sbin/apache2ctl", "-D", "FOREGROUND"]
