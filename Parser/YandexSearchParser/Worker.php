<?php
namespace Seo\AppBundle\Parser\YandexSearchParser;

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
        $this->httpClient   = new GuzzleClient();
        $this->cookieJar    = $jar;
        $this->proxy        = $proxy;
        $this->userAgent    = $userAgent;
    }

    public function parsePage($keyword, $region, $page = 1)
    {
        $options = [
            'cookies' => $this->cookieJar,
            'proxy' => 'tcp://' . $this->proxy,
            'connect_timeout' => 10,
            'timeout' => 30,
            'headers' => [
                'User-Agent' => $this->userAgent
            ],
            'query' => [
                'text' => $keyword,
                'numdoc' => 50,
                'lr' => $region
            ]
        ];
        if ($page > 1) {
            $options['query']['p'] = $page - 1;
        }
        $promise = $this->httpClient->getAsync(
            'https://yandex.ru/yandsearch',
            $options
        );

        return $promise;
    }

    public function sendCaptcha($captchaKey, $captchaValue, $retPath) {
        $options = [
            'cookies' => $this->cookieJar,
            'proxy' => 'tcp://' . $this->proxy,
            'connect_timeout' => 10,
            'timeout' => 30,
            'headers' => [
                'User-Agent' => $this->userAgent
            ],
            'query' => [
                'rep'       => $captchaValue,
                'key'       => $captchaKey,
                'retpath'   => $retPath,
            ]
        ];
        $promise = $this->httpClient->getAsync(
            'https://yandex.ru/checkcaptcha',
            $options
        );

        return $promise;
    }

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
}
