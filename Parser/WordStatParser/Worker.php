<?php

namespace Seo\AppBundle\Parser\WordStatParser;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Cookie as GuzzleCookie;
use Seo\AppBundle\Parser\WorkerInterface;

final class Worker implements WorkerInterface
{
    private $httpClient;
    private $cookieJar;
    private $proxy;
    private $userAgent;

    public function __construct(GuzzleCookie\CookieJarInterface $jar, $proxy, $userAgent)
    {
        $this->httpClient = new GuzzleClient();
        $this->cookieJar  = $jar;
        $this->proxy      = $proxy;
        $this->userAgent  = $userAgent;
    }

    /**
     * @param $phrase
     * @param array $regions
     * @param int   $page
     * @param null  $captchaKey
     * @param null  $captchaValue
     *
     * @return \GuzzleHttp\Promise\PromiseInterface
     */
    public function parsePhrase($phrase, $regions = [], $page = 1, $captchaKey = null, $captchaValue = null)
    {
        // Подготавливаем параметр с регионами
        $regionsParam = null;
        if (!empty($regions)) {
            $regionsParam = implode(',', $regions);
        }

        $options = [
            'cookies' => $this->cookieJar,
            'proxy'   => 'tcp://' . $this->proxy,
            'headers' => [
                'User-Agent' => $this->userAgent,
            ],
            'form_params' => [
                'db'        => null,
                'filter'    => 'all',
                'map'       => 'world',
                'page'      => $page,
                'page_type' => 'words',
                'period'    => 'monthly',
                'regions'   => $regionsParam,
                'sort'      => 'cnt',
                'type'      => 'list',
                'words'     => $phrase,
            ],
        ];

        if ($captchaKey && $captchaValue) {
            $options['form_params']['captcha_key']   = $captchaKey;
            $options['form_params']['captcha_value'] = $captchaValue;
        }

        $promise = $this->httpClient->postAsync(
            'https://wordstat.yandex.ru/stat/words',
            $options
        );

        return $promise;
    }

    /**
     * @return string
     */
    public function getId()
    {
        return $this->proxy;
    }

    /**
     * @return GuzzleCookie\CookieJarInterface
     */
    public function getCookieJar()
    {
        return $this->cookieJar;
    }

    /**
     * @return string
     */
    public function getUserAgent()
    {
        return $this->userAgent;
    }
}
