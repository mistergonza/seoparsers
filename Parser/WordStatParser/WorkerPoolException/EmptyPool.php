<?php

namespace Seo\AppBundle\Parser\WordStatParser\WorkerPoolException;

class EmptyPool extends \Exception
{
    public function __construct($message)
    {
        $message = 'Pool is empty: ' . $message;
        parent::__construct($message);
    }
}
