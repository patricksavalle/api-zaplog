<?php

declare(strict_types = 1);

namespace Zaplog\Library;

require_once BASE_PATH . '/Exception/ResourceNotFoundException.php';
require_once BASE_PATH . '/Library/Mail.php';
require_once BASE_PATH . '/Library/Locker.php';

use Exception;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SlimRestApi\Infra\Ini;
use stdClass;
use Zaplog\Exception\ResourceNotFoundException;

/**
 * Generic mechanism for authorising actions through an email link.
 *
 * Send a authorisation token to user:
 *
 *  $params = BodyParameters::get();
 *  (new TwoFactor)
 *      ->addParams($params)
 *      ->addTrigger(['Class', 'method'], [$params])
 *      ->send();
 *
 * Handle the confirmed token, SLIM route-handler
 *
 *  $this->get("/<url_segment>/{utoken:[[:alnum:]]{32}}", new TwoFactorAuth);
 *
 * This will cause the triggers to be run. The result of the last methods will be returned.
 */
class TwoFactorAuth extends stdClass
{
    // MUST BE PUBLIC, otherwise it will not be enumerated into the Locker
    public $triggers = [];

    public function addParams($iterable): TwoFactorAuth
    {
        foreach ($iterable as $key => $value) {
            /** @noinspection PhpVariableVariableInspection */
            assert(!isset($this->$key));
            /** @noinspection PhpVariableVariableInspection */
            $this->$key = $value;
        }
        return $this;
    }

    public function addTrigger($phpfile, callable $callable, array $arguments): TwoFactorAuth
    {
        $this->triggers[] = [$phpfile, $callable, $arguments];
        return $this;
    }

    public function createToken(): TwoFactorAuth
    {
        // Create a token for '$this', to execute to token, use the __invoke methode
        $this->utoken = Locker::stash($this->triggers, 60 * 60 * 24);
        unset($this->triggers);
        return $this;
    }

    /**
     * Send the authorisation request to the user
     * @throws ResourceNotFoundException
     * @throws Exception
     */
    public function sendToken(stdClass $args): TwoFactorAuth
    {
        // set some defaults and dynamics
        $args->template_url = $args->template_url ?? Ini::get('email_action_template');
        $args->sender = $args->sender ?? Ini::get('email_default_sender');
        $args->sendername = $args->sendername ?? Ini::get('email_default_sendername');
        $args->subject = $args->subject ?? Ini::get('email_default_subject');

        // Get the email template from the client
        $body = file_get_contents($args->template_url);
        if ($body === false) {
            throw new Exception('Cannot open location: ' . $args->template_url);
        }

        // Very simple template rendering, just iterate all object members and replace name with value
        // Most object members are set from the POST body. Client can POST data that will be put into his template.
        foreach ($args as $member => $value) {
            $body = str_replace("{{{$member}}}", filter_var($value, FILTER_SANITIZE_SPECIAL_CHARS), $body);
        }

        // Mail the secret to the recipient
        Mail::setSubject($args->subject);
        Mail::addAddress($args->receiver);
        Mail::setFrom($args->sender, $args->sendername);
        Mail::isHTML(true);
        Mail::setBody($body);
        if (Mail::send() != true) {
            throw new Exception(Mail::getErrorInfo(), 500);
        }
        return $this;
    }

    // ---------------------------------------------------------------------------------
    // This is the 2 factor callback. It executes the triggers associated with the token
    // SLIM format
    // ---------------------------------------------------------------------------------

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, stdClass $args): ResponseInterface
    {
        // Execute all requested actions in order, remember last result
        $result = null;
        foreach (Locker::unstash($args->utoken) as list($phpfile, $callable, $arguments)) {
            require_once $phpfile;
            $result = call_user_func_array($callable, $arguments);
        }
        return isset($result) ? $response->withJson($result) : $response;
    }
}
