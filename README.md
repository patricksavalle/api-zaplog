# ZAPLOG REST-API

See: https://github.com/zaplogv2/doc.zaplog for explanation of the functionality.

Zaplog is a next-generation social-news platform. It includes:

- collaborative linkdumping, bookmarking, blogging, voting, tagging etc.
- wisdom-of-the-crowd mechanisms for frontpage generation
- Monero and Bitcoin crowd payments distributed based on user reputation

## Contributing

Everyone is encouraged to help improve this project. Here are a few ways you can help:

- Report bugs
- Write, clarify, or fix documentation
- Suggest or add new features
- Fix bugs and submit pull requests
  - Fork it.
  - Create your feature branch (git checkout -b my-new-feature).
  - Commit your changes (git commit -am 'Added some feature').
  - Push to the branch (git push origin my-new-feature).
  - Create a new Pull Request.

*Or ask to be added to our team!*

## Required skills

Advanced SQL and advanced PHP. We're using the full spectrum of OO PHP 7.3 syntax and the datamodel
contains built-in referential integrity (foreign keys), events and triggers.

Knowledge of the SLIM3 framework is useful but not required. The code speaks for itself.

## Deploying the REST-server (classic)

- Install a LAMP or XAMPP stack. Make sure MariaDb (mySQL) is running. I am using XAMP 3.2.4 (PHP 7.3, MariaDb 10.4.6), POSTMAN and MySQL Workbench. PHP needs the following extensions:

      ext-json
      ext-libxml
      ext-dom
      ext-simplexml
      ext-pdo
      ext-curl
      ext-tidy
      ext-fileinfo
      php-mbstring
      ext-gd


- Clone the project from this Github to your local computer


- Run composer to fetch and update external libraries:

      php composer.phar selfupdate  
      php composer.phar update

- Set the correct values in the main INI file ([see example here](https://gitlab.com/zaplog/api-zaplog/-/blob/master/slim-rest-api.ini.example)):

      /slim-rest-api.ini
 
- Create the database (for instance using MySQL workbench):

      /datamodel.sql

- Run the built-in PHP interpreter:

      php -S localhost:8080

- Open the homepage of the Api in a browser to initialize (you should see [a list of endpoints](https://api.zaplog.pro)):

      http://localhost:8080/Api.php

- Install the cronjobs, the command lines (from the root of the server) are:

      php Api.php /cronjobs/minute GET
      php Api.php /cronjobs/hour GET
      php Api.php /cronjobs/day GET
      php Api.php /cronjobs/month GET

- For extra performance install memcached and edit the INI accordingly, it will be automatically used, see https://www.memcached.org/


- For much more performance install a reverse proxy that first checks GET 
requests by full URL in memcached (e.g. NGINX http://nginx.org/en/docs/http/ngx_http_memcached_module.html)


- This server does not do rate-limiting, DDOS-mitigation or anything else that should be done by a reverse proxy
  (e.g. NGINX https://www.nginx.com/blog/rate-limiting-nginx/ and https://www.nginx.com/blog/mitigating-ddos-attacks-with-nginx-and-nginx-plus/)

## Deploying the REST-server (Docker)

See: https://gitlab.com/zaplog/api-zaplog/container_registry 

