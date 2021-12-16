FROM php:8.0.13-apache

RUN apt-get update
RUN apt-get install -y libz-dev libmemcached-dev apt-utils git unzip libzip-dev && \
    docker-php-ext-install zip pdo pdo_mysql && \
    pecl install memcached  &&\
    docker-php-ext-enable memcached

ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

RUN chmod +x /usr/local/bin/install-php-extensions && \
    install-php-extensions tidy gd apcu && \
    php --ini

WORKDIR /var/www/html/

#COPY php.ini /usr/local/etc/php/php.ini
RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
COPY . /var/www/html/
COPY slim-rest-api.ini.docker.example /var/www/html/slim-rest-api.ini

RUN chmod +x /var/www/html/composer.phar

RUN /var/www/html/composer.phar selfupdate && \
    /var/www/html/composer.phar update

RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

EXPOSE 80
CMD ["/usr/sbin/apache2ctl", "-D", "FOREGROUND"]
