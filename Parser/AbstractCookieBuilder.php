<?php

namespace Seo\AppBundle\Parser;

use GuzzleHttp\Cookie as GuzzleCookie;
use Symfony\Component\Console\Output\OutputInterface;

abstract class AbstractCookieBuilder implements CookieBuilderInterface
{
    protected $userAgent;
    protected $proxy;
    protected $output;
    protected $cookieJar;
    protected $cookieFile;

    /**
     * AbstractCookieBuilder constructor.
     *
     * @param $userAgent
     * @param $cookieFile
     * @param $proxy
     * @param OutputInterface $output
     */
    public function __construct($userAgent, $cookieFile, $proxy, OutputInterface $output)
    {
        $this->userAgent  = $userAgent;
        $this->cookieFile = $cookieFile;
        $this->proxy      = $proxy;
        $this->output     = $output;
    }

    abstract public function constructJar();

    /**
     * @return GuzzleCookie\FileCookieJar
     */
    public function getCookieJar()
    {
        $this->cookieJar->save($this->cookieFile);

        return $this->cookieJar;
    }

    /**
     * Проверка на наличие и актуальность печеньки.
     *
     * @param $cookieName
     *
     * @return bool
     */
    protected function cookieAnalyze($cookieName)
    {
        $cookies = $this->cookieJar->toArray();

        foreach ($cookies as $cookie) {
            if ($cookie['Name'] == $cookieName) {
                $date = (new \DateTime())->format('U');
                if ($date < $cookie['Expires']) {
                    $this->output->writeln("{$this->proxy}: Проверка cookie {$cookieName} пройдена");

                    return true;
                }
            }
        }

        return false;
    }
}
