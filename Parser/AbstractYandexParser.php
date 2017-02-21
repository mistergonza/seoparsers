<?php
namespace Seo\AppBundle\Parser;


class AbstractYandexParser {

    protected function getYandexCookies() {
        $this->AC->get('http://kiks.yandex.ru/su/', array(), array(CURLOPT_COOKIEJAR => $this->cookie, CURLOPT_REFERER => 'wordstat.yandex.ru'));
        $this->AC->execute(1);
        $this->executeTailsRequests();
        $cookie1 = file_get_contents($this->cookie);
        $this->AC->flush_requests();
        $this->AC->get('http://mc.yandex.ru/watch/292098', array(), array(CURLOPT_COOKIEJAR => $this->cookie, CURLOPT_REFERER => 'wordstat.yandex.ru'));
        $this->AC->execute(1);
        $this->executeTailsRequests();
        $cookie2 = file_get_contents($this->cookie);
        $this->AC->flush_requests();
        $cookiesum = $cookie1 . $cookie2;
        file_put_contents($this->cookie, $cookiesum);
    }

    public function addPhrase($phrase) {
        $this->phrases[] = $phrase;
    }

    public function clearPhrases() {
        $this->phrases = array();
    }

}