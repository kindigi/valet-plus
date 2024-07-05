<?php

namespace KinDigi\ValetPlus\Extends;
use Valet\Nginx as LaravelNginx;

class Nginx extends LaravelNginx
{
    /**
     * Install the configuration files for Nginx.
     * @return void
     */
    public function installServer(): void
    {
        parent::installServer();

        // Merge fastcgi_params from Laravel Valet with our optimizations.
        $contents = $this->files->get(BREW_PREFIX . '/etc/nginx/fastcgi_params');
        $contents .= $this->files->get(__DIR__ . '/../../stubs/nginx/fastcgi_params');

        $this->files->putAsUser(
            BREW_PREFIX . '/etc/nginx/fastcgi_params',
            $contents
        );
    }
}