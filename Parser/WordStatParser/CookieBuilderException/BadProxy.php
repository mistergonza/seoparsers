<?php

namespace Seo\AppBundle\Parser\WordStatParser\CookieBuilderException;

class BadProxy extends \Exception
{
    public function __construct($message)
    {
        $message = 'Bad proxy: ' . $message;
        parent::__construct($message);
    }
}
