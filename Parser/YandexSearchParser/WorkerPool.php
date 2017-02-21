<?php
namespace Seo\AppBundle\Parser\YandexSearchParser;

use Seo\AppBundle\Parser\AbstractWorkerPool;
use Symfony\Component\Console\Output\OutputInterface;

class WorkerPool extends AbstractWorkerPool
{
    public function __construct(array $proxyList, array $userAgents, $tmpDir, OutputInterface $output)
    {
        parent::__construct($userAgents, $tmpDir, $output);
        foreach ($proxyList as $proxyName) {
            try {
                $userAgent = $this->getOrderedUserAgent();
                $cookieJar = $this->getCookieJar($proxyName, $userAgent);
                $worker = new Worker($cookieJar, $proxyName, $userAgent);

                $this->addWorkerToStorage($proxyName, $worker);
            } catch (\Exception $e) {

            }
        }
    }

    /**
     * Возвращает подготвленную куку
     *
     * @param $proxy
     * @param $userAgent
     * @return \GuzzleHttp\Cookie\FileCookieJar
     */
    private function getCookieJar($proxy, $userAgent)
    {
        $cookieBuilder = new CookieBuilder($proxy, $userAgent, $this->tmpDir, $this->output);
        $cookieBuilder->constructJar();

        return $cookieBuilder->getCookieJar();
    }

    /**
     * Возвращает UserAgent последовательно
     *
     * @return mixed
     */
    private function getOrderedUserAgent()
    {
        $result = array_pop($this->userAgents);
        array_unshift($this->userAgents, $result);
        return $result;
    }
}
