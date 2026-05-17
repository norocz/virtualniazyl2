<?php

declare(strict_types=1);

namespace App\Services;

use Nette\Caching\Cache;
use Nette\Caching\Storages\FileStorage;

class IpInfoService
{
    private Cache $cache;

    public function __construct(string $cacheDir)
    {
        $storage = new FileStorage($cacheDir);
        $this->cache = new Cache($storage);
    }

    public function getIpInfo(string $ip): array
    {
        // Validate that $ip is a syntactically valid IPv4 or IPv6 address before using in URL
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return ['error' => 'invalid_ip', 'cached' => false, 'cacheKey' => ''];
        }

        $cacheKey = 'ip-info-' . md5($ip);
        $ipInfo = $this->cache->load($cacheKey);

        if ($ipInfo === null) {
            $response = file_get_contents("https://ipinfo.io/" . rawurlencode($ip) . "/json");
            $ipInfo = json_decode($response, true);
            $this->cache->save($cacheKey, $ipInfo);
            $ipInfo['cached'] = false; //tady nám to řekne jestli ůdaj pochází z cache nebo jestli ze zdroje

        } else {
            $ipInfo['cached'] = true;
        }
        $ipInfo['cacheKey'] = $cacheKey; //ještě si pošleme klíč kdyby jsme potřebovali něky s ním pracovat
        return $ipInfo;
    }
}
