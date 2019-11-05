<?php

declare(strict_types=1);

namespace Composer\Satis\VendorOverwrite\Package\Loader;


use Composer\Satis\VendorOverwrite\Package\CompletePackage;

class ArrayLoader extends \Composer\Package\Loader\ArrayLoader
{
    public function load(array $config, $class = CompletePackage::class) {
        $package = parent::load($config, $class);
        if(isset($config['base_path'])) {
            $package->setBasePath($config['base_path']);
        }
        return $package;
    }
}