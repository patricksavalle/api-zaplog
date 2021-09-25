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

Kennis van het SLIM3 framework is handig (hoewel de code voor zich spreekt), maar niet nodig als je aan delen van de applicatie wilt werken.

## Deploying the REST-server

- Install a LAMP or XAMPP stack. Make sure MariaDb (mySQL) is running. I am using XAMP 3.2.4 (PHP 7.3, MariaDb 10.4.6), POSTMAN and MySQL Workbench. PHP needs the following extensions:

      ext-json
      ext-libxml
      ext-dom
      ext-simplexml
      ext-pdo
      ext-curl
      ext-tidy


- Clone the project from this Github to your local computer


- Run composer to fetch and update external libraries:

      php composer.phar selfupdate  
      php composer.phar update

- Set the correct values in the main INI file (see example below):

      /slim-rest-api.ini 

- Create the database (for instance using MySQL workbench):

      /datamodel.sql

- Run the built-in PHP interpreter:

      php -S localhost:8080

  Or

      php -S 127.0.0.1:8080

- Try the homepage of the Api in a browser:

      http://localhost:8080/Api.php

- For initial content call this temporary endpoint from the browser (It will ingest some RSS feeds):

      http://localhost:8080/Api.php/cronjobs/hour

- The next call to the server must be a login (initialises some tables)

      POST http://localhost:8080/Api.php/sessions/<your-urlencoded-email>/<urlencoded-loginurl>

  try this: 

      POST http://localhost:8080/Api.php/sessions/your@urlencoded.email/http%3A%2F%2Flocalhost%3A8080%2FApi.php%2F2factor%2F

  if SMTP is not yet configured the method response will contain the 2-factor code (unsafe) for use in:

      GET http://localhost:8080/Api.php/2factor/<2factorcode>

- Install the cronjobs, the command lines (from the root of the server) are:

      php Api.php /cronjobs/minute GET
      php Api.php /cronjobs/hour GET
      php Api.php /cronjobs/day GET
      php Api.php /cronjobs/month GET

- For extra performance install memcached


- For much more performance install a reverse proxy that first checks GET 
requests by URL in memcached (e.g. NGINX)


- This server does no rate-limiting, black-listing, DDOS-mitigation or anything else that should be done by a reverse proxy 

## Example INI file

    ; Example slim-rest-api.ini, needs to be placed in webroot
    
    [memcache]
    memcache_host=localhost
    memcache_port=11211
    
    [cors]
    cors_origin[]=http://localhost:3000
    cors_origin[]=<http://yourdomainhere.com>
    cors_expose_headers=
    cors_max_age=1728000
    cors_allow_credentials=1
    cors_allow_methods=POST,GET,PUT,DELETE,PATCH
    cors_allow_headers=Authorization,Content-Type
    
    [database]
    database_host=127.0.0.1
    database_name=zaplog
    database_user=root
    database_password=<password>
    database_charset=utf8
    
    [smtp]
    smtp_host=<host>
    smtp_login=<account>
    smtp_password=<password>
    smtp_secure=TLS
    smtp_port=587
    
    [email]
    email_twofactor_template=;twofactoraction.html
    email_sender=zaplog@patricksavalle.com
    email_sendername=Zaplog

## Endpoints

    GET	/2factor/{utoken:[[:alnum:]]{32}}
    GET	/payments/inaddress
    GET	/
    POST	/sessions/{emailencoded:(?:[^%]|%[0-9A-Fa-f]{2})+}/{loginurlencoded:(?:[^%]|%[0-9A-Fa-f]{2})+}
    PATCH	/sessions/{emailencoded:(?:[^%]|%[0-9A-Fa-f]{2})+}/{updateurlencoded:(?:[^%]|%[0-9A-Fa-f]{2})+}
    GET	/sessions
    DELETE	/sessions
    GET	/channels
    GET	/channels/id/{id:[\d]{1,10}}
    PATCH	/channels
    GET	/channels/active
    GET	/frontpage
    POST	/links/{urlencoded:(?:[^%]|%[0-9A-Fa-f]{2})+}
    GET	/links/id/{id:\d{1,10}}
    POST	/links
    PATCH	/links/id/{id:\d{1,10}}
    DELETE	/links/id/{id:\d{1,10}}
    GET	/links
    GET	/links/tag/{tag:[\w-]{3,55}}
    GET	/links/channel/{id:[\w-]{3,55}}
    GET	/links/metadata/{urlencoded:(?:[^%]|%[0-9A-Fa-f]{2})+}
    POST	/votes/link/{id:\d{1,10}}
    POST	/tags/link/{id:\d{1,10}}/tag/{tag:[\w-]{3,50}}
    GET	/tags/index
    GET	/tags/active
    DELETE	/tags/id/{id:\d{1,10}}
    GET	/activities
    GET	/activities/channels/{id:\d{1,10}}
    GET	/statistics
    GET	/cronjobs/minute
    GET	/cronjobs/hour
    GET	/cronjobs/day
    GET	/cronjobs/month    