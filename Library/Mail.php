<?php

declare(strict_types = 1);

namespace Zaplog\Library;

use SlimRestApi\Infra\Ini;
use SlimRestApi\Infra\Singleton;
use PHPMailer\PHPMailer\PHPMailer;

/**
 *  SMTP, instantiates a SMTP object and delegates all calls to this object.
 *  Singleton-pattern.
 *  We use PHPMailer, see: https://github.com/PHPMailer/PHPMailer
 */
final class Mail extends Singleton
{
    static protected $instance = null;

    static public function setSubject($subject)
    {
        Mail::setProperty('Subject', $subject);
    }

    static public function setBody(string $body)
    {
        Mail::setProperty('Body', $body);
    }

    static public function setAltBody(string $body)
    {
        Mail::setProperty('AltBody', $body);
    }

    static public function getErrorInfo()
    {
        return Mail::getProperty('ErrorInfo');
    }

    /** @noinspection PhpMissingReturnTypeInspection */
    static protected function instance()
    {
        $mail = new PHPMailer();
        $mail->isSMTP();
        $mail->Host = Ini::get('smtp_host');
        $mail->SMTPAuth = true;
        $mail->Username = Ini::get('smtp_login');
        $mail->Password = Ini::get('smtp_password');
        $mail->SMTPSecure = Ini::get('smtp_secure');
        $mail->Port = Ini::get('smtp_port');
        return $mail;
    }
}

