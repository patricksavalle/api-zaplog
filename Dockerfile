FROM php:apache

RUN apt-get update
RUN apt-get install -y libz-dev libmemcached-dev apt-utils git unzip libzip-dev && \
    docker-php-ext-install zip pdo pdo_mysql && \
    pecl install memcached  &&\
    docker-php-ext-enable memcached

ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

RUN chmod +x /usr/local/bin/install-php-extensions && \
    install-php-extensions tidy && \
    php --ini

WORKDIR /var/www/html/

#COPY php.ini /usr/local/etc/php/php.ini
COPY . /var/www/html/

RUN chmod +x /var/www/html/composer.phar

RUN /var/www/html/composer.phar selfupdate && \
    /var/www/html/composer.phar update

#wat commando's om een mariaDB te vullen en aan te maken in docker
#RUN echo "docker exec -i mariadb mysql -u root -ppass < /home/<>/Projects/api-zaplog/datamodel.sql"
#RUN echo "docker exec -it mariadb mysql -u root -ppass"
#RUN echo "CREATE USER zaplog@zaplog IDENTIFIED BY 'password';"
#RUN echo "GRANT ALL PRIVILEGES ON *.* TO 'zaplog'@'zaplog'@'zaplog.docker_default' IDENTIFIED BY 'password';"
#RUN echo "FLUSH PRIVILEGES;"

EXPOSE 80
CMD ["/usr/sbin/apache2ctl", "-D", "FOREGROUND"]
