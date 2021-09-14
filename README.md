# ZAPLOG REST-API PROTOTYPE

Zie: https://github.com/zaplogv2/doc.zaplog voor uitleg van de functionaliteit.

Wanneer de API draait, zal deze een overzicht van endpoints tonen op: /Api.php

<img src="https://user-images.githubusercontent.com/701331/131680261-752bd0a1-6c15-4b4d-8111-815b144d48a6.png" width="500"/>

Dit is een prototype en heeft nog geen unit-tests o.i.d. 

## Benodigde skills

Advanced SQL en advanced PHP. De volledige syntax van OO PHP 7.3 wordt benut en het datamodel
heeft ingebouwde referentiele integriteit (foreign keys), events en triggers.

Kennis van het SLIM3 framework is handig (hoewel de code voor zich spreekt), maar niet nodig als je aan delen van de applicatie wilt werken.

## Bouwen en deployen

Zorg dat de MariaDb database draait. Installeer in principe de LAMP of XAMPP stack. Clone het project vanuit Github.
Ik heb zelf ontwikkeld op XAMP 3.2.4 (PHP 7.3, MariaDb 10.4.6)

Het resolven / ophalen van alle libraries gebeurt met composer:

    php composer.phar -update

Deze server is geschreven in PHP 7.4 op MariaDB en kan worden gestart met de ingebouwde PHP interpreter:

    PHP -S localhost:8080

Het base adres voor de API is:

    /Api.php

De root URL genereert een quick list van beschikbare endpoints.

## Configuratie

De configuratie kan worden aangepast in:

    /slim-rest-api.ini 

De server maakt gebruik van de zgn. event-scheduler van de database engine.
Deze moet in de meeste installaties expliciet woden aangezet.

De server maakt gebruik van een externe SMTP server. Deze kan in de bovenstaande .ini worden geconfigureerd.

Voor wat initiële content kun je het volgende tijdelijke endpoint aanroepen:

    /Api.php/cronjobs/hour

Dit zal een handvol RSS feeds inlezen.

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

# FRONT-END

Het front-end heeft een apart project op https://github.com/zaplogv2/web.zaplog
