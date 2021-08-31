# ZAPLOG REST-API PROTOTYPE

Zie: https://github.com/zaplogv2/doc.zaplog voor uitleg van de functionaliteit.

Wanneer de API draait, zal deze een overzicht van endpoints tonen op: /Api.php

## Bouwen en deployen

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

# FRONT-END

Het front-end heeft een apart project op:

    https://github.com/patricksavalle/web.zaplog

Beiden tegelijk gebruiken kan kan alleen via NGINX of APACHE omdat het front-end
het back-end aanroept en de interne PHP-server dan blokkeert.