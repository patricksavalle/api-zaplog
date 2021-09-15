<?php

declare(strict_types=1);

namespace Zaplog\Library {


    class MoneroPayments
    {
        static public function checkAndForward( array $paymentshares )
        {
            // open wallet, create if not exists

            // check for payments
            $amount = 0.0;

            // forward payments

            return [$amount, count( $paymentshares )];
        }
    }
}