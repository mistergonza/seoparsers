<?php
namespace Seo\AppBundle\Parser;

use GuzzleHttp\Client as GuzzleClient;

trait CaptchaDecodeTrait
{
    private $apiCaptchaKey;

    public function setCaptchaApiKey($key)
    {
        $this->apiCaptchaKey = $key;
    }

    private function saveCaptchaFile($url, $dir)
    {
        $img        = file_get_contents($url);
        $filename   = "{$this->tmpDir}/captcha/{$dir}/" . md5($url) . ".jpg";
        file_put_contents($filename, $img);

        return $filename;
    }

    private function recognizeCaptcha(
        $filename,
        $domain = "rucaptcha.com",
        $rtimeout = 5,
        $mtimeout = 120,
        $isPhrase = 0,
        $isRegsense = 0,
        $isNumeric = 0,
        $minLen = 0,
        $maxLen = 0,
        $language = 0
    )
    {
        $apiKey = $this->apiCaptchaKey;
        if (!file_exists($filename)) {
            return false;
        }

        $client = new GuzzleClient();

        $response = $client->post(
            "http://$domain/in.php",
            [
                'multipart' => [
                    [
                        'name' => 'key',
                        'contents' => $apiKey,
                    ],
                    [
                        'name' => 'phrase',
                        'contents' => (string)$isPhrase,
                    ],
                    [
                        'name' => 'regsense',
                        'contents' => (string)$isRegsense
                    ],
                    [
                        'name' => 'numeric',
                        'contents' => (string)$isNumeric,
                    ],
                    [
                        'name' => 'min_len',
                        'contents' => (string)$minLen,
                    ],
                    [
                        'name' => 'max_len',
                        'contents' => (string)$maxLen,
                    ],
                    [
                        'name' => 'language',
                        'contents' => (string)$language,
                    ],
                    [
                        'name' => 'file',
                        'contents' => fopen($filename, 'r'),
                    ]
                ]
            ]
        );

        $result = $response->getBody()->getContents();


        if (strpos($result, "ERROR") !== false) {
            return false;
        } else {
            $ex = explode("|", $result);
            $captcha_id = $ex[1];
            $waittime = 0;
            sleep($rtimeout);
            while (true) {
                $result = file_get_contents("http://$domain/res.php?key=" . $apiKey . '&action=get&id=' . $captcha_id);
                if (strpos($result, 'ERROR') !== false) {
                    return false;
                }
                if ($result == "CAPCHA_NOT_READY") {
                    $waittime += $rtimeout;
                    if ($waittime > $mtimeout) {
                        break;
                    }
                    sleep($rtimeout);
                } else {
                    $ex = explode('|', $result);
                    if (trim($ex[0]) == 'OK') {
                        // Удаляем файл после распознования
                        unlink($filename);
                        return trim($ex[1]);
                    }
                }
            }

            return false;
        }
    }
}
