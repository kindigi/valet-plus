<?php

use Illuminate\Container\Container;

$container = Container::getInstance();

$container->singleton(
    Valet\Valet::class,
    KinDigi\ValetPlus\Extends\Valet::class
);

$container->singleton(
    Valet\Configuration::class,
    KinDigi\ValetPlus\Extends\Configuration::class
);

$container->singleton(
    Valet\Valet::class,
    KinDigi\ValetPlus\Extends\Valet::class
);
