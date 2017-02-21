<?php

namespace Seo\AppBundle\Parser;

use Symfony\Component\Console\Output\OutputInterface;

abstract class AbstractNextGenerationParser
{
    protected $workerPool;
    protected $tmpDir;
    protected $output;

    public function __construct(WorkerPoolInterface $workerPool, $tmpDir, OutputInterface $output)
    {
        $this->workerPool = $workerPool;
        $this->output     = $output;
        $this->tmpDir     = $tmpDir;
    }
}
