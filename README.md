# ZAPLOG REST-API PROTOTYPE

Dit is een prototype voor het zaplog. 
## configuratie

De configuratie kan worden aangepast in:
 
    slim-rest-api.ini 

## bouwen en deployen 

Het resolven / ophalen van alle libraries gebeurt met composer:

    php composer.phar -update

Deze server is geschreven in PHP 7.4 op MariaDB en kan worden gestart met de ingebouwde PHP interpreter:

    PHP -S localhost:8080
    
Het adres voor de API is:

    /api.php
    
Het adres voor het front-end is:

    /web.php

Beiden tegelijk gebruiken kan kan alleen via NGINX of APACHE omdat het front-end 
het back-end aanroept en de interne PHP-server dan blokkeert.