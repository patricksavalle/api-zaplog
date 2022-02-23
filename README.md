# ZAPLOG REST-API

Zaplog is a next-generation social-news / group-blogging platform. It includes:

- [Project homepage](https://zaplog.gitlab.io/infra-zaplog)
- [Live example]() https://zaplog.pro)
- [Manual](https://gitlab.com/zaplog/api-zaplog/-/wikis/Zaplog-manual)

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

Advanced SQL and advanced PHP. We're using the full spectrum of object-oriented PHP 7.3 syntax and the datamodel
contains built-in referential integrity (foreign keys), events, transactions and triggers.

Knowledge of the SLIM3 framework (a port to SLIM4 is planned) is useful but not required. The code speaks for itself.

## Local deployment of the REST-server

- Install a LAMP or XAMPP stack with MariaDb. Make sure MariaDb is running. PHP versions above 8.0 generate errors. PHP needs the following extensions:

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
      ext-xmlreader
      ext-apcu

  APCu configuration (php.ini)

      [apcu]
      extension=apcu
      apc.enabled=1
      apc.shm_size=16M
      apc.ttl=7200
      apc.enable_cli=1
      apc.serializer=php
  
- Clone the project from this Gitlab to your local computer

      git clone https://gitlab.com/zaplog/api-zaplog.git

- Run composer to fetch and update external libraries:

      php composer.phar selfupdate  
      php composer.phar update

- Set the correct values in the main INI file ([see example here](https://gitlab.com/zaplog/api-zaplog/-/blob/master/slim-rest-api.ini.example)):

      /slim-rest-api.ini
 
- Create the database by running this script (for instance using MySQL workbench):

      /datamodel.sql

- Enable the MariaDb event scheduler and disable locking (no need for locking, huge performance increase)

      SET GLOBAL event_scheduler = ON;
      SET GLOBAL TRANSACTION ISOLATION LEVEL READ UNCOMMITTED; 

- Run the built-in PHP interpreter:

      php -S localhost:8080

- Open the homepage of the Api in a browser to initialize (you should see [a list of endpoints](https://api.zaplog.pro/v1)):

      http://localhost:8080/Api.php/v1

## Production deploying of the entire system

See: https://gitlab.com/zaplog/infra-zaplog/-/blob/main/README.md 

