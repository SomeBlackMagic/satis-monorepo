<?php

declare(strict_types=1);

namespace Composer\Satis\VendorOverwrite\Package;


class CompletePackage extends \Composer\Package\CompletePackage
{
    protected $basePath;

    /**
     * @return mixed
     */
    public function getBasePath()
    {
        return $this->basePath;
    }

    /**
     * @param mixed $basePath
     */
    public function setBasePath($basePath): void
    {
        $this->basePath = $basePath;
    }


}