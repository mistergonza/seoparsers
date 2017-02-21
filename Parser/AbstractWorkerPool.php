<?php

namespace Seo\AppBundle\Parser;

use Symfony\Component\Console\Output\OutputInterface;

abstract class AbstractWorkerPool implements WorkerPoolInterface
{
    protected $storage = [];
    protected $userAgents;
    protected $output;
    protected $tmpDir;

    const STATUS_FREE = 0;
    const STATUS_BUSY = 1;

    public function __construct(array $userAgents, $tmpDir, OutputInterface $output)
    {
        $this->userAgents = $userAgents;
        $this->tmpDir     = $tmpDir;
        $this->output     = $output;
    }

    /**
     * Добавляет worker в хранилище.
     *
     * @param $id
     * @param WorkerInterface $worker
     *
     * @return $this
     */
    protected function addWorkerToStorage($id, WorkerInterface $worker)
    {
        $this->storage[$id] = [
            'worker' => $worker,
            'status' => self::STATUS_FREE,
        ];

        return $this;
    }

    /**
     * Возвращает рандомный свободный воркер
     *
     * @return WorkerInterface|null
     */
    public function getWorker()
    {
        $this->storage;

        $randomIds = array_rand($this->storage, count($this->storage));

        foreach ($randomIds as $id) {
            if ($this->storage[$id]['status'] === self::STATUS_FREE) {
                $this->storage[$id]['status'] = self::STATUS_BUSY;

                return $this->storage[$id]['worker'];
            }
        }

        return;
    }

    public function removeWorker($id)
    {
        unset($this->storage[$id]);

        return $this;
    }

    /**
     * Освобождает воркер
     *
     * @param $id
     *
     * @return $this
     */
    public function unchainWorker($id)
    {
        if (isset($this->storage[$id])) {
            $this->storage[$id]['status'] = self::STATUS_FREE;
        }

        return $this;
    }

    /**
     * Размер пула.
     *
     * @return int
     */
    public function getSize()
    {
        return count($this->storage);
    }

    /**
     * Количество свободных воркеров.
     *
     * @return int
     */
    public function getWorkersLeft()
    {
        $count = 0;
        foreach ($this->storage as $value) {
            if ($value['status'] === self::STATUS_FREE) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Возвращает случайный UserAgent.
     *
     * @return string
     */
    protected function getRandomUserAgent()
    {
        $key = array_rand($this->userAgents);

        return $this->userAgents[$key];
    }
}
