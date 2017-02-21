<?php
namespace Seo\AppBundle\Parser\WordStatParser\CookieBuilderException;

class BadAccount extends \Exception
{
    public function __construct($message)
    {
        $message = 'Bad account: ' . $message;
        parent::__construct($message);
    }
}
