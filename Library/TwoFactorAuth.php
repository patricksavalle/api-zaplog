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
    public $callables = [];

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
        $this->callables[] = [$phpfile, $callable, $arguments];
        return $this;
    }

    /**
     * Send the authorisation request to the user
     * @throws ResourceNotFoundException
     * @throws Exception
     */
    public function send()
    {
        // set some defaults and dynamics
        $this->template_url = $this->template_url ?? Ini::get('email_action_template');
        $this->sender = $this->sender ?? Ini::get('email_default_sender');
        $this->sendername = $this->sendername ?? Ini::get('email_default_sendername');
        $this->subject = $this->subject ?? Ini::get('email_default_subject');
        $this->ttl = 60 * 60 * 24 /* token expires in 24hrs */;

        // Get the email template from the client
        $body = file_get_contents($this->template_url);
        echo $this->template_url;
        if ($body === false) {
            throw new Exception('Cannot open location: ' . $this->template_url);
        }

        // Store $this in database, add token to '$this' so it will be used in rendering below
        $this->utoken = Locker::stash($this, $this->ttl);

        // Very simple template rendering, just iterate all object members and replace name with value
        // Most object members are set from the POST body. Client can POST data that will be put into his template.
        unset($this->callables);
        foreach ($this as $member => $value) {
            $body = str_replace("{{{$member}}}", filter_var($value, FILTER_SANITIZE_SPECIAL_CHARS), $body);
        }

        // Mail the secret to the recipient
        Mail::setSubject($this->subject);
        Mail::addAddress($this->receiver);
        Mail::setFrom($this->sender, $this->sendername);
        Mail::isHTML(true);
        Mail::setBody($body);
        if (Mail::send() != true) {
            throw new Exception(Mail::getErrorInfo(), 500);
        }
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, stdClass $args): ResponseInterface
    {
        // Restore $this to state with which the 2FA request was made
        $this->addParams(Locker::unstash($args->utoken));

        // Execute all requested actions in order, remember last result
        $result = null;
        foreach ($this->callables as list($phpfile, $callable, $arguments)) {
            require_once $phpfile;
            $result = call_user_func_array($callable, $arguments);
        }

        return isset($result) ? $response->withJson($result) : $response;
    }
}
