<?php

namespace KinDigi\ValetPlus\Extends;
use GuzzleHttp\Client;
use Valet\Valet as LaravelValet;

class Valet extends LaravelValet
{
    public function onLatestVersion(string $currentVersion): bool
    {
        $url = 'https://api.github.com/repos/kindigi/valet-plus/releases/latest';
        $response = json_decode((new Client())->get($url)->getBody());

        return version_compare($currentVersion, trim($response->tag_name, 'v'), '>=');
    }
}