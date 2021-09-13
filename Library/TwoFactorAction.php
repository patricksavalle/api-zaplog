<?php /** @noinspection PhpUndefinedMethodInspection */

declare(strict_types=1);

namespace Zaplog\Library {

    require_once BASE_PATH . '/Library/Mail.php';

    use Zaplog\Exception\EmailException;

    class TwoFactorAction extends \SlimRestApi\Infra\TwoFactorAction
    {
        // overload of base class
        protected function sendMail(string $receiver, string $sender, string $sendername, string $subject, string $body)
        {
            // Mail the secret to the recipient
            Mail::setSubject($subject);
            Mail::addAddress($receiver);
            Mail::setFrom($sender, $sendername);
            Mail::isHTML(true);
            Mail::setBody($body);
            if (Mail::send() != true) {
                throw new EmailException(Mail::getErrorInfo());
            }
        }
    }
}