<?php

namespace Seo\AppBundle\Parser;

interface WorkerPoolInterface
{
    public function getWorker();

    public function removeWorker($id);

    public function unchainWorker($id);
}
