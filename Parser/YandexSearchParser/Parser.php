<?php
namespace Seo\AppBundle\Parser\YandexSearchParser;

use Seo\AppBundle\Parser\AbstractNextGenerationParser;
use Seo\AppBundle\Parser\CaptchaDecodeTrait;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Cookie as GuzzleCookie;
use GuzzleHttp\Promise as GuzzlePromise;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DomCrawler\Crawler;

final class Parser extends AbstractNextGenerationParser
{
    use CaptchaDecodeTrait;

    public function __construct(WorkerPool $workerPool, $tmpDir, OutputInterface $output)
    {
        parent::__construct($workerPool, $tmpDir, $output);
    }


    public function parse(array $keywords, $domain, $region)
    {
        $results = [];

        if ($this->workerPool->getSize() > 0) {
            while (!empty($keywords)) {
                $keywordsPack = [];


                $freeWorkers = $this->workerPool->getWorkersLeft();

                if ($freeWorkers > 0) {
                    $this->output->writeln("Free workers: {$freeWorkers}");

                    // Собираем пачку фраз по количеству свободных воркеров
                    for ($i = 1; $i <= $freeWorkers; $i++) {
                        if (!empty($keywords)) {
                            $keywordsPack[] = array_pop($keywords);
                        }
                    }

                    $executeResults = $this->execute($keywordsPack, $domain, $region);
                    $results = array_merge($results, $executeResults['complete']);
                    $keywords = array_merge($keywords, $executeResults['missed']);
                } else {
                    throw new \Exception(' Parser::parse() not found free workers');
                }
            }

            return $results;
        }
    }

    /**
     * Запуск парсинга набора фраз
     *
     * @param array $keywordsPack
     * @param $domain
     * @return array
     * @throws
     * @throws \Exception
     */
    private function execute(array $keywordsPack, $domain, $region)
    {
        $workers = [];
        $results = [
            'complete' => [],
            'missed' => [],
        ];
        $tempParseResults = [];

        foreach ($keywordsPack as $keyword) {
            $worker = $this->workerPool->getWorker();

            // Получаем необходимое колчество воркеров
            $workers[$worker->getId()] = $worker;

            // Подготавливаем результирующий массив
            $tempParseResults[$worker->getId()] = [
                'phrase' => $keyword,
                'url' => null,
                'position' => null,
                'page' => 1,
                'complete' => false
            ];
            $worker = null;
        }

        $this->output->writeln('Used workers: ' . count($workers));

        $promises = [];

        // Подготовка запросов первой страницы wordstat
        foreach ($workers as $workerId => $worker) {
            /** @var Worker $worker */

            $phrase = $tempParseResults[$worker->getId()]['phrase'];

            $promises[$workerId] = $worker->parsePage($phrase, $region);
        }

        if (!empty($promises)) {
            while (!empty($promises)) {
                $workerResults = GuzzlePromise\settle($promises)->wait();

                // Обнуляем промисы
                $promises = [];

                foreach ($workerResults as $workerId => $result) {
                    // Проверяем состояние ответа
                    if ($result['state'] == 'rejected') {
                        // Если всё плохо удаляем воркер и пропускаем этот результат
                        $this->output->writeln("$workerId: worker fail: {$result['reason']->getMessage()}");
                        $this->workerPool->removeWorker($workerId);
                        unset($workers[$workerId]);
                        continue;
                    }

                    $worker = $workers[$workerId];

                    $phrase = $tempParseResults[$workerId]['phrase'];

                    $response = $result['value'];
                    $responseContent = $response->getBody()->getContents();
                    if (!$this->hasCaptcha($responseContent)) {
                        // Если нет капчи проверяем рузультаты
                        $page = $tempParseResults[$workerId]['page'];
                        $resultData = $this->findPosition($domain, $responseContent, $page);
                        if ($resultData !== null) {
                            // Если результаты найдены
                            $this->output->writeln("$workerId: позиция найдена #{$phrase}#");
                            $tempParseResults[$workerId]['position'] = $resultData['position'];
                            $tempParseResults[$workerId]['url']      = strtolower($resultData['url']);
                            $tempParseResults[$workerId]['complete'] = true;
                            $this->workerPool->unchainWorker($workerId);
                            unset($workers[$workerId]);
                        } else {
                            // Если результаты не найдены
                            if ($tempParseResults[$workerId]['page'] == 1) {
                                // Если текущая страница была первой, то парсим вторую
                                $this->output->writeln("$workerId: парсим следующию страницу #{$phrase}#");
                                $tempParseResults[$workerId]['page'] = 2;
                                $promises[$workerId] = $worker->parsePage($phrase, $region, 2);
                            } else {
                                // В противном случае считаем, что искомый результат не найден
                                $this->output->writeln("$workerId: позиция не найдена #{$phrase}#");
                                $tempParseResults[$workerId]['position'] = 0;
                                $tempParseResults[$workerId]['complete'] = true;
                                $this->workerPool->unchainWorker($workerId);
                                unset($workers[$workerId]);
                            }

                        }
                    } else {
                        $this->output->writeln("$workerId: captcha #{$phrase}#");
                        try {
                            $crawler = new Crawler($responseContent);

                            $captchaKey         = $crawler->filter('form.form__inner > input.form__key')->first()->attr('value');
                            $captchaUrl         = $crawler->filter('form.form__inner > img.form__captcha')->first()->attr('src');
                            $captchaRetPath     = $crawler->filter('form.form__inner > input.form__retpath')->first()->attr('value');
                            $captchaFile = $this->saveCaptchaFile($captchaUrl, 'yse');

                            $recognizedResult = $this->recognizeCaptcha($captchaFile);
                        } catch (\Exception $e) {
                            $this->output->writeln("$workerId: crawler exception ({$e->getMessage()}) #{$phrase}#");
                            $recognizedResult = null;
                        }

                        if ($recognizedResult) {
                            $this->output->writeln("$workerId: капча распознана");
                            $promises[$workerId] = $worker->sendCaptcha($captchaKey, $recognizedResult, $captchaRetPath);
                        } else {
                            $this->output->writeln("$workerId: капча не распознана");
                            // Освобождаем воркер
                            $this->workerPool->unchainWorker($workerId);
                            unset($workers[$workerId]);
                        }
                    }
                }

                // Засыпаем перед следующим проходом цикла
                sleep(rand(0,30));
            }
        }

        foreach ($tempParseResults as $result) {
            if ($result['complete']) {
                // Собираем данные по завершенным фразам
                // В качестве ключа для массива используем хеш от фразы, чтобы потом было легче выцепить данные
                // о конретной фразе
                $results['complete'][md5($result['phrase'])] = $result;
            } else {
                // Собираем фразы которые по каким либо причинам еще не были завершены
                $results['missed'][] = $result['phrase'];
            }
        }

        if (!empty($results['missed'])) {
            $this->output->writeln('Missed phrases: ' . count($results['missed']));
        }

        return $results;
    }

    /**
     * Поиск позиции на странице
     *
     * @param $domain
     * @param $html
     * @param $page
     * @return array|null
     */
    private function findPosition($domain, $html, $page)
    {
        $result = null;
        $crawler = new Crawler($html);
        $articles = $crawler->filter('.serp-list > .serp-item:not(.serp-adv-item):not(.serp-block_type_wizard_adresawizard_adresa) h2 > a');
        $position = ($page - 1) * 50 ;
        foreach($articles as $node) {
            $position++;
            $link = $node->getAttribute('href');
            if(stripos($link, $domain) !== false) {
                $result = [
                    'position' => $position,
                    'url' => $link
                ];
                break;
            }
        }
        return $result;
    }

    /**
     * Проверка страницы на наличие капчи
     *
     * @param $html
     * @return bool
     */
    private function hasCaptcha($html)
    {
        if(strpos($html, 'form__captcha') !== false) {
            return true;
        }

        return false;
    }
}
