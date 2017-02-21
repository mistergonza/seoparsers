<?php

namespace Seo\AppBundle\Parser\WordStatParser;

use Seo\AppBundle\Parser\AbstractNextGenerationParser;
use Seo\AppBundle\Parser\WordStatParser\WorkerPoolException\EmptyPool as EmptyPoolException;
use Seo\AppBundle\Parser\CaptchaDecodeTrait;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Promise as GuzzlePromise;
use Symfony\Component\Console\Output\OutputInterface;

final class Parser extends AbstractNextGenerationParser
{
    const STANDARD_PHRASE_TYPE = 'standard';
    const QUOTED_PHRASE_TYPE   = 'quoted';
    const STRONG_PHRASE_TYPE   = 'strong';

    use CaptchaDecodeTrait;

    public function __construct(WorkerPool $workerPool, $tmpDir, OutputInterface $output)
    {
        parent::__construct($workerPool, $tmpDir, $output);
    }

    public function parse($phrases, $regions = [], $type = self::STANDARD_PHRASE_TYPE, $tails = 0)
    {
        $results = [];

        if ($this->workerPool->getSize() > 0) {
            while (!empty($phrases)) {
                $phrasesPack = [];

                $freeWorkers = $this->workerPool->getWorkersLeft();

                if ($freeWorkers > 0) {
                    $this->output->writeln("Free workers: {$freeWorkers}");

                    // Собираем пачку фраз по количеству свободных воркеров
                    for ($i = 1; $i <= $freeWorkers; ++$i) {
                        if (!empty($phrases)) {
                            $phrasesPack[] = array_pop($phrases);
                        }
                    }

                    $executedResults = $this->execute($phrasesPack, $regions, $type, $tails);
                    $results         = array_merge($results, $executedResults['complete']);
                    $phrases         = array_merge($phrases, $executedResults['missed']);
                } else {
                    throw new EmptyPoolException(' Parser::parse() not found free workers');
                }
            }

            return $results;
        } else {
            throw new EmptyPoolException(' Parser::parse()');
        }
    }

    private function execute($phrasesPack, $regions = [], $type = self::STANDARD_PHRASE_TYPE, $tails = 0)
    {
        $workers = [];
        $results = [
            'complete' => [],
            'missed'   => [],
        ];
        $tempParseResults = [];

        foreach ($phrasesPack as $phrase) {
            $worker = $this->workerPool->getWorker();

            // Получаем необходимое колчество воркеров
            $workers[$worker->getId()] = $worker;

            // Подготавливаем результирующий массив
            $tempParseResults[$worker->getId()] = [
                'phrase'   => $phrase,
                'lastPage' => 0,
                'shows'    => 0,
                'tails'    => [],
                'complete' => false,
            ];
            $worker = null;
        }

        $this->output->writeln('Used workers: ' . count($workers));

        $promises = [];

        // Подготовка запросов первой страницы wordstat
        foreach ($workers as $workerId => $worker) {
            /** @var Worker $worker */
            $phrase          = $tempParseResults[$worker->getId()]['phrase'];
            $requestedPhrase = $this->getPhraseToRequestByType($phrase, $type);

            $promises[$workerId] = $worker->parsePhrase($requestedPhrase, $regions);
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

                    $response = $result['value'];

                    $responseContent = $response->getBody()->getContents();
                    $responseData    = json_decode($responseContent, true);

                    $phrase = $tempParseResults[$workerId]['phrase'];
                    // Фраза обработанная в соответствии типу
                    $requestedPhrase = $this->getPhraseToRequestByType($phrase, $type);

                    if (isset($responseData['key'])) {
                        // Если пришел номральный ответ
                        // Декодируем данные
                        $decodedWsData = $this->decodeWs($responseData, $workers[$workerId]);

                        $tempParseResults[$workerId]['shows'] = $this->getShows($decodedWsData);

                        if ($tails > 0) {
                            $tempParseResults[$workerId]['tails'] = array_merge(
                                $tempParseResults[$workerId]['tails'],
                                $this->getTails($decodedWsData)
                            );
                        }

                        $page                                    = $this->getCurrentPage($decodedWsData);
                        $tempParseResults[$workerId]['lastPage'] = $page;

                        if ($this->hasNextPage($decodedWsData) && $tails > 0 && $page <= $tails) {
                            // Если нужно парсить следующию страницу
                            $nextPage = $page + 1;

                            $promises[$workerId] = $worker->parsePhrase($requestedPhrase, $regions, $nextPage);
                            $this->output->writeln($workerId . ": #{$requestedPhrase}# have next page {$nextPage}");
                        } else {
                            $this->output->writeln("{$workerId}: complete #{$requestedPhrase}#");
                            $tempParseResults[$workerId]['complete'] = true;
                            $this->workerPool->unchainWorker($workerId);
                            unset($workers[$workerId]);
                        }
                    } elseif (isset($responseData['captcha'])) {
                        $this->output->writeln("$workerId: captcha #{$requestedPhrase}#");

                        $captchaKey  = $responseData['captcha']['key'];
                        $captchaUrl  = 'https:' . $responseData['captcha']['url'];
                        $captchaFile = $this->saveCaptchaFile($captchaUrl, 'ws');

                        $recognizedResult = $this->recognizeCaptcha($captchaFile);

                        // Берем последнию запрашиваемую страницу для этого воркера
                        $page = $tempParseResults[$workerId]['lastPage'];

                        if ($recognizedResult) {
                            $this->output->writeln("$workerId: капча распознана");
                            $promises[$workerId] = $worker->parsePhrase($requestedPhrase, $regions, $page, $captchaKey, $recognizedResult);
                        } else {
                            $this->output->writeln("$workerId: капча не распознана");

                            // Освобождаем воркер
                            $this->workerPool->unchainWorker($workerId);
                            unset($workers[$workerId]);
                        }
                    } elseif (isset($responseData['blocked'])) {
                        // Если прокси или аккаунт заблокирован яндексом
                        $this->output->writeln("$workerId: blocked");

                        // Убиваем воркер
                        $this->workerPool->removeWorker($workerId);
                        unset($workers[$workerId]);
                    } else {
                        // Если пришел не нормальный ответ
                        $this->output->writeln("$workerId: bad response #{$requestedPhrase}#");

                        // Освобождаем воркер
                        $this->workerPool->unchainWorker($workerId);
                        unset($workers[$workerId]);
                    }
                }

                // Засыпаем перед следующим проходом цикла
                sleep(rand(45, 90));
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
     * Возвращает фразу подготовленную для запроса в соответсвии с типом
     *
     * @param $phrase
     * @param $type
     *
     * @return string
     */
    private function getPhraseToRequestByType($phrase, $type)
    {
        switch ($type) {
            case self::QUOTED_PHRASE_TYPE:
                $result = '"' . $phrase . '"';
                break;
            case self::STRONG_PHRASE_TYPE:
                $phrase = str_replace(' ', ' !', $phrase);
                $phrase = str_replace('! ', ' ', $phrase);
                $result = '"!' . $phrase . '"';
                break;
            default:
                $result = $phrase;
        }

        return $result;
    }

    /**
     * Получение количества показов.
     *
     * @param $decodedWsData
     *
     * @return int
     */
    private function getShows($decodedWsData)
    {
        if (isset($decodedWsData['content']['includingPhrases']['info'][2])) {
            $result = $decodedWsData['content']['includingPhrases']['info'][2];
            $result = preg_replace('/\s/', '', $result);
            $result = preg_replace('/[^\d]/', '', $result);

            return intval($result);
        } else {
            return 0;
        }
    }

    /**
     * Получение хвостовов.
     *
     * @param $decodedWsData
     *
     * @return array
     */
    private function getTails($decodedWsData)
    {
        $items = $decodedWsData['content']['includingPhrases']['items'];

        $result = [];

        foreach ($items as $item) {
            $phrase   = $item['phrase'];
            $shows    = $item['number'];
            $shows    = preg_replace('/\s/', '', $shows);
            $shows    = preg_replace('/[^\d]/', '', $shows);
            $result[] = [
                'phrase' => $phrase,
                'shows'  => intval($shows),
            ];
        }

        return $result;
    }

    /**
     * Получаем номер текущей страницы.
     *
     * @param $decodedWsData
     *
     * @return int
     */
    private function getCurrentPage($decodedWsData)
    {
        $nextPage = intval($decodedWsData['content']['currentPage']);

        return $nextPage;
    }

    /**
     * Проверка на наличие следующей страницы.
     *
     * @param $decodedWsData
     *
     * @return bool
     */
    private function hasNextPage($decodedWsData)
    {
        $textNode = $decodedWsData['content']['hasNextPage'];
        if ($textNode === 'yes') {
            return true;
        }

        return false;
    }

    /**
     * Разбирает закодированные данные от wordstat.
     *
     * @param array  $responseData
     * @param Worker $worker
     *
     * @return mixed
     */
    private function decodeWs($responseData, Worker $worker)
    {
        $wsDecodeClient = new GuzzleClient();
        $code           = base64_encode($responseData['key']);
        $decodeResponse = $wsDecodeClient->request('GET', 'http://localhost:8810/?code=' . $code, []);
        $decodedKey     = $decodeResponse->getBody()->getContents();

        $userAgent = $worker->getUserAgent();

        $cookieJar = $worker->getCookieJar();

        foreach ($cookieJar->toArray() as $cookie) {
            if ($cookie['Name'] == 'fuid01') {
                $cookieFuid = $cookie['Value'];
                break;
            }
        }

        $hash        = substr($userAgent, 0, 25) . $cookieFuid . $decodedKey;
        $decodedData = '';
        $dataLen     = strlen($responseData['data']);
        for ($g = 0; $g < $dataLen; ++$g) {
            $decodedData .= chr(
                ord($responseData['data'][$g]) ^ ord($hash[$g % strlen($hash)])
            );
        }
        $decodedData = urldecode($decodedData);

        $result = json_decode($decodedData, true);

        return $result;
    }
}
