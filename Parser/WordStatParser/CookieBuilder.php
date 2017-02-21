<?php

namespace Seo\AppBundle\Parser\WordStatParser;

use Seo\AppBundle\Parser\AbstractCookieBuilder;
use Seo\AppBundle\Parser\CaptchaDecodeTrait;
use Seo\AppBundle\Parser\WordStatParser\CookieBuilderException\BadProxy as BadProxyException;
use Seo\AppBundle\Parser\WordStatParser\CookieBuilderException\BadAccount as BadAccountException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Cookie as GuzzleCookie;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Подготавливает куки для дальнейшего использования в парсере
 * Class CookieBuilder.
 */
final class CookieBuilder extends AbstractCookieBuilder
{
    private $httpClient;
    private $yandexLogin;
    private $yandexPassword;
    private $tmpDir;

    use CaptchaDecodeTrait;

    public function __construct($proxy, $userAgent, $login, $password, $tmpDir, OutputInterface $output)
    {
        $cookieFileName = $tmpDir . '/cookies/ws/' . str_replace(['.', ':'], ['_', '_'], $proxy) . '.txt';

        parent::__construct($userAgent, $cookieFileName, $proxy, $output);

        $this->httpClient     = new GuzzleClient();
        $this->yandexLogin    = $login;
        $this->yandexPassword = $password;
        $this->tmpDir         = $tmpDir;
        $this->cookieJar      = new GuzzleCookie\FileCookieJar($cookieFileName);
    }

    /**
     * @return $this
     *
     * @throws BadAccountException
     * @throws BadProxyException
     */
    public function constructJar()
    {
        try {
            $this
                ->getYandexCookies()
                ->yandexAuth();

            return $this;
        } catch (BadAccountException $e) {
            // Исключения для плохи аккаунтов выодим и передаем выше
            $this->output->writeln($e->getMessage());
            throw new BadAccountException($e->getMessage());
        } catch (\Exception $e) {
            $message = $this->proxy . ': "' . $e->getMessage() . '"';
            throw new BadProxyException($message);
        }

        return $this;
    }

    /**
     * @param string $yandexLogin
     */
    public function setYandexLogin($yandexLogin)
    {
        $this->yandexLogin = $yandexLogin;
    }

    /**
     * @param string $yandexPassword
     */
    public function setYandexPassword($yandexPassword)
    {
        $this->yandexPassword = $yandexPassword;
    }

    /**
     * Получение первоначальных печенек от Яндекса.
     *
     * @return $this
     */
    private function getYandexCookies()
    {
        if (!$this->cookieAnalyze('fuid01')) {
            $this->output->writeln($this->proxy . ': Получение печенек от Яндекса');
            $client = $this->httpClient;

            $options = [
                'cookies' => $this->cookieJar,
                'headers' => [
                    'User-Agent' => $this->userAgent,
                ],
                'proxy' => 'tcp://' . $this->proxy,
            ];

            $response = $client->request('GET', 'https://wordstat.yandex.ru', $options);
            $response = $client->request('GET', 'https://kiks.yandex.ru/su/', $options);
        }

        return $this;
    }

    /**
     * Авторизация на Яндексе.
     *
     * @return $this
     *
     * @throws BadAccountException
     */
    private function yandexAuth()
    {
        if (!$this->cookieAnalyze('yandex_login')) {
            $this->output->writeln("{$this->proxy}: Авторизация на Яндексе");

            $client = $this->httpClient;

            $options = [
                'cookies' => $this->cookieJar,
                'headers' => [
                    'User-Agent' => $this->userAgent,
                ],
                'proxy'       => 'tcp://' . $this->proxy,
                'form_params' => [
                    'login'  => $this->yandexLogin,
                    'passwd' => $this->yandexPassword,
                ],
            ];

            $response = $client->request(
                'POST',
                'https://passport.yandex.ru/passport?mode=auth&from=&retpath=https%3A%2F%2Fwordstat.yandex.ru%2F&twoweeks=yes',
                $options
            );

            $body = $response->getBody()->getContents();

            // Если словили капчу пытаемся её распознать
            if (strpos($body, 'captcha__captcha__text') !== false) {
                $this->output->writeln("{$this->proxy}: Капча при авторизации");
                $domCrawler = new Crawler($body);
                $captchaUrl = $domCrawler->filter('img.captcha__captcha__text')->first()->attr('src');
                $captchaKey = $domCrawler->filter('input.captcha_key')->first()->attr('value');

                $captchaFile      = $this->saveCaptchaFile($captchaUrl, 'ws');
                $recognizedResult = $this->recognizeCaptcha($captchaFile);

                if ($recognizedResult) {
                    $this->output->writeln("{$this->proxy}: капча распознана");
                    $captchaOptions                        = $options;
                    $captchaOptions['form_data']['answer'] = $recognizedResult;
                    $captchaOptions['form_data']['key']    = $captchaKey;
                    $response                              = $client->request(
                        'POST',
                        'https://passport.yandex.ru/passport?mode=auth&from=&retpath=https%3A%2F%2Fwordstat.yandex.ru%2F&twoweeks=yes',
                        $captchaOptions
                    );

                    $body = $response->getBody()->getContents();
                } else {
                    throw new BadAccountException('Капча не распознана: ' . $captchaUrl);
                }
            }

            // Если есть кнопка выхода
            if (strpos($body, 'b-head-userinfo__link') !== false) {
                $this->output->writeln("{$this->proxy}: Авторизация пройдена");
            } else {
                throw new BadAccountException("{$this->proxy}: Авторизация не пройдена ({$this->yandexLogin}:{$this->yandexPassword})");
            }
        } else {
            $this->output->writeln("{$this->proxy}: Авторизация на Яндексе не требуется");
        }

        return $this;
    }
}
