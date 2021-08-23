# ZAPLOG REST-API PROTOTYPE

Dit is een prototype voor het ZapLog nieuwscommunity platform. 

Crowd-sourced link-dump met algoritmische voorpagina. 

- POC
  - n.v.t. 


- Prototype
    - 2FA email login
    - links plaatsen 
    - links taggen 
    - links voten 
    - activity stream
    - linkscores 
    - algoritmische voorpagina
    - gemeenschappelijk archief (channel)
    - persoonlijk archief (channel)
    - trefwoorden index (tags)
    - ‘now online' 


- MVP 
    - SLIM 3.x -> SLIM 4.x
    - PHP 7.x -> PHP 8.x
    - links commenten
    - blogging
    - network of zaplogs (remove single point of censorship) 
    - gebruikersprofielen
    - gebruikersreputatie
    - automatische content actualisatie / crawling

  
- COULD HAVE:
    - comments voten
    - RSS feeds plaatsen 
    - RSS feeds voting
    - RSS feeds reader


- WON'T HAVE
  - images in user-content (copyright gevoelig)

## Technologie

Er zal een eenvoudig maatwerk systeem worden gebouwd in moderne en courante web technologie.
De back-end zal een PHP REST-API worden. Het frontend een PHP/CSS3 Website.

Bestaande blogsystemen zoals Wordpress en Expression Engine zijn te complex en zijn niet goed geschikt voor de gevraagd efunctionaliteit.

Deze REST-API is gebaseerd op het SLIM 3 Framework en custom plugins

- LAMP/XAMP stack
- SLIM 3 Framework
- SMTP server
- optioneel Memcache

## Bouwen en deployen 

Het resolven / ophalen van alle libraries gebeurt met composer:

    php composer.phar -update

Deze server is geschreven in PHP 7.4 op MariaDB en kan worden gestart met de ingebouwde PHP interpreter:

    PHP -S localhost:8080
    
Het adres voor de API is:

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