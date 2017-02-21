<?php
namespace Seo\AppBundle\Parser\YandexSearchParser;

use Seo\AppBundle\Parser\AbstractCookieBuilder;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Cookie as GuzzleCookie;
use Symfony\Component\Console\Output\OutputInterface;

final class CookieBuilder extends AbstractCookieBuilder
{
    private $httpClient;
    private $tmpDir;

    public function __construct($proxy, $userAgent, $tmpDir, OutputInterface $output)
    {
        $cookieFileName = $tmpDir . '/cookies/yse/' . str_replace(['.', ':'], '_', $proxy) . '.txt';

        parent::__construct($userAgent, $cookieFileName, $proxy, $output);

        $this->httpClient   = new GuzzleClient();
        $this->tmpDir       = $tmpDir;
        $this->cookieJar    = new GuzzleCookie\FileCookieJar($cookieFileName);
    }

    public function constructJar()
    {
        $this
            ->getYandexPageCookies()
            ->getYandexWatchCookies()
            ->getYandexKiksCookies();

        return $this;
    }

    /**
     * Получение первоначальных печенек от Яндекса
     * @return $this
     */
    private function getYandexPageCookies()
    {
        if (!$this->cookieAnalyze('yp')) {
            $this->output->writeln($this->proxy . ': Получение печенек от Яндекса (главная страница)');
            $client = $this->httpClient;

            $options = [
                'cookies' => $this->cookieJar,
                'headers' => [
                    'User-Agent' => $this->userAgent
                ],
                'proxy' => 'tcp://' . $this->proxy,
            ];

            $response = $client->request('GET', 'https://yandex.ru/', $options);
        }
        return $this;
    }

    private function getYandexWatchCookies()
    {
        $this->output->writeln($this->proxy . ': Получение печенек от Яндекса (watch)');
        $client = $this->httpClient;

        $options = [
            'cookies' => $this->cookieJar,
            'headers' => [
                'User-Agent' => $this->userAgent
            ],
            'proxy' => 'tcp://' . $this->proxy,
        ];

        $response = $client->request('GET', 'http://mc.yandex.ru/watch/10630330?ut=noindex', $options);

        return $this;
    }

    private function getYandexKiksCookies()
    {

        if (!$this->cookieAnalyze('fuid01')) {
            $this->output->writeln($this->proxy . ': Получение печенек от Яндекса (kiks)');
            $client = $this->httpClient;

            $options = [
                'cookies' => $this->cookieJar,
                'headers' => [
                    'User-Agent' => $this->userAgent
                ],
                'proxy' => 'tcp://' . $this->proxy,
            ];

            $response = $client->request('GET', 'http://kiks.yandex.ru/su/', $options);
        }
        return $this;
    }
}
