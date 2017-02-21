<?php

namespace Seo\AppBundle\Parser;

interface CookieBuilderInterface
{
    public function constructJar();

    public function getCookieJar();
}
