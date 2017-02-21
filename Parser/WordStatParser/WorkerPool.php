<?php

namespace Seo\AppBundle\Parser\WordStatParser;

use Seo\AppBundle\Parser\AbstractWorkerPool;
use Seo\AppBundle\Parser\WordStatParser\WorkerPoolException\NoMoreAccounts;
use Seo\AppBundle\Parser\WordStatParser\CookieBuilderException\BadProxy;
use Symfony\Component\Console\Output\OutputInterface;

final class WorkerPool extends AbstractWorkerPool
{
    private $accountsList;
    private $apiKey;

    public function __construct(array $proxyList, array $userAgents, $accountsList, $apiKey, $tmpDir, OutputInterface $output)
    {
        parent::__construct($userAgents, $tmpDir, $output);
        $this->accountsList = $accountsList;
        $this->apiKey       = $apiKey;

        foreach ($proxyList as $proxyName) {
            try {
                $userAgent = $this->getRandomUserAgent();
                $cookieJar = $this->getCookieJar($proxyName, $userAgent);
                $worker    = new Worker($cookieJar, $proxyName, $userAgent);

                $this->addWorkerToStorage($proxyName, $worker);
            } catch (NoMoreAccounts $e) {
                break;
            } catch (BadProxy $e) {
                $output->writeln($e->getMessage());
                continue;
            }
        }
    }

    /**
     * Удаление ворекера.
     *
     * @param $proxyName
     *
     * @return $this
     */
    public function removeWorker($proxyName)
    {
        $worker = $this->storage[$proxyName]['worker'];
        // Очищаем куки
        $worker->getCookieJar()->clear();

        parent::removeWorker($proxyName);

        return $this;
    }

    /**
     * @param $proxy
     * @param $userAgent
     *
     * @return \GuzzleHttp\Cookie\FileCookieJar
     *
     * @throws BadProxy
     * @throws NoMoreAccounts
     */
    private function getCookieJar($proxy, $userAgent)
    {
        $account = $this->getUnusedAccount();

        $cookieBuilder = new CookieBuilder($proxy, $userAgent, $account['login'], $account['pwd'], $this->tmpDir, $this->output);
        $cookieBuilder->setCaptchaApiKey($this->apiKey);

        $constructed = false;
        while ($constructed == false) {
            try {
                $cookieBuilder->constructJar();
                $constructed = true;
            } catch (CookieBuilderException\BadAccount $e) {
                $newAccount = $this->getUnusedAccount();
                $cookieBuilder->setYandexLogin($newAccount['login']);
                $cookieBuilder->setYandexPassword($newAccount['pwd']);
            }
        }

        return $cookieBuilder->getCookieJar();
    }

    /**
     * Получение не использованного аккаунта.
     *
     * @return array
     *
     * @throws NoMoreAccounts
     */
    private function getUnusedAccount()
    {
        if (!empty($this->accountsList)) {
            $key = array_rand($this->accountsList);

            $exploded = explode(':', trim($this->accountsList[$key]));
            $result   = [
                'login' => $exploded[0],
                'pwd'   => $exploded[1],
            ];

            // Убираем аккаунт из массива дабы он нам больше не попадался
            unset($this->accountsList[$key]);

            return $result;
        }

        throw new NoMoreAccounts('');
    }
}
