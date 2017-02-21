<?php

namespace Seo\AppBundle\Parser\WordStatParser\WorkerPoolException;

class NoMoreAccounts extends \Exception
{
    public function __construct($message)
    {
        $message = 'No more accounts: ' . $message;
        parent::__construct($message);
    }
}
