<?php

// autoload_psr4.php @generated by Composer

$vendorDir = dirname(dirname(__FILE__));
$baseDir = dirname($vendorDir);

return array(
    'pavlakis\\cli\\' => array($vendorDir . '/pavlakis/slim-cli/src'),
    'Slim\\' => array($vendorDir . '/slim/slim/Slim'),
    'SlimRestApi\\' => array($vendorDir . '/patricksavalle/slim-rest-api'),
    'SlimRequestParams\\' => array($baseDir . '/', $vendorDir . '/patricksavalle/slim-request-params'),
    'Psr\\Http\\Message\\' => array($vendorDir . '/psr/http-message/src'),
    'Psr\\Container\\' => array($vendorDir . '/psr/container/src'),
    'PHPMailer\\PHPMailer\\' => array($vendorDir . '/phpmailer/phpmailer/src'),
    'FastRoute\\' => array($vendorDir . '/nikic/fast-route/src'),
    'CorsSlim\\' => array($vendorDir . '/palanik/corsslim'),
);
