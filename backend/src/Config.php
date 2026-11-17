<?php
declare(strict_types=1);
namespace Banklord;
final class Config
{
    public static function load(): void
    {
        $path=__DIR__.'/../private/config.local.php';
        if(!is_file($path)) return; // env vars in local development or CI
        $config=require $path;
        if(!is_array($config))throw new \RuntimeException('Invalid private configuration');
        foreach(['DB_HOST','DB_NAME','DB_USER','DB_PASS','APP_HTTPS','UPDATE_HOST','UPDATE_SOURCE','UPDATE_AUTO'] as $key){
            if(isset($config[$key]) && is_string($config[$key]))putenv($key.'='.$config[$key]);
        }
    }
}
