# ZAPLOG REST-API PROTOTYPE

Zie: https://github.com/zaplogv2/doc.zaplog voor uitleg van de functionaliteit.

Wanneer de API draait, zal deze een overzicht van endpoints tonen op: /Api.php

<img src="https://user-images.githubusercontent.com/701331/131515274-1248a9d4-7d95-40f9-8d81-079c0cc8fb32.png" width="500"/>

## Benodigde skills

Advanced SQL en advanced PHP. De volledige syntax van OO PHP 7.3 wordt benut en het datamodel
heeft ingebouwde referentiele integriteit (foreign keys), events en triggers.

## Bouwen en deployen

Zorg dat de MariaDb database draait. Installeer in principe de LAMP of XAMPP stack. Clone het project vanuit Github.
Ik heb zelf ontwikkeld op XAMP 3.2.4 (PHP 7.3, MariaDb 10.4.6)

Het resolven / ophalen van alle libraries gebeurt met composer:

    php composer.phar -update

Deze server is geschreven in PHP 7.4 op MariaDB en kan worden gestart met de ingebouwde PHP interpreter:

    PHP -S localhost:8080

Het base adres voor de API is:

    /api.php

De root URL genereert een quick list van beschikbare endpoints.

## Configuratie

De configuratie kan worden aangepast in:

    /slim-rest-api.ini 

De server maakt gebruik van de zgn. event-scheduler van de database engine.
Deze moet in de meeste installaties expliciet woden aangezet.

De server maakt gebruik van een externe SMTP server. Deze kan in de bovenstaande .ini worden geconfigureerd.

Voor wat initiële content kun je het volgende tijdelijke endpoint aanroepen:

    /Api.php/cronjobhour

Dit zal een handvol RSS feeds inlezen.

# FRONT-END

Het front-end heeft een apart project op:

    https://github.com/patricksavalle/web.zaplog

Beiden tegelijk gebruiken kan kan alleen via NGINX of APACHE omdat het front-end
het back-end aanroept en de interne PHP-server dan blokkeert.
