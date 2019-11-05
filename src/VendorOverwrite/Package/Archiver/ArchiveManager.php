<?php

declare(strict_types=1);

namespace Composer\Satis\VendorOverwrite\Package\Archiver;


use Composer\Json\JsonFile;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Util\Filesystem;

class ArchiveManager extends \Composer\Package\Archiver\ArchiveManager
{


    /**
     * Create an archive of the specified package.
     *
     * @param PackageInterface $package The package to archive
     * @param string $format The format of the archive (zip, tar, ...)
     * @param string $targetDir The directory where to build the archive
     * @param string|null $fileName The relative file name to use for the archive, or null to generate
     *                                                  the package name. Note that the format will be appended to this name
     * @param bool $ignoreFilters Ignore filters when looking for files in the package
     * @return string                    The path of the created archive
     * @throws \RuntimeException*@throws \Exception
     * @throws \InvalidArgumentException
     */
    public function archive(PackageInterface $package, $format, $targetDir, $fileName = null, $ignoreFilters = false)
    {
        if (empty($format)) {
            throw new \InvalidArgumentException('Format must be specified');
        }

        // Search for the most appropriate archiver
        $usableArchiver = null;
        foreach ($this->archivers as $archiver) {
            if ($archiver->supports($format, $package->getSourceType())) {
                $usableArchiver = $archiver;
                break;
            }
        }

        // Checks the format/source type are supported before downloading the package
        if (null === $usableArchiver) {
            throw new \RuntimeException(sprintf('No archiver found to support %s format', $format));
        }

        $filesystem = new Filesystem();
        if (null === $fileName) {
            $packageName = $this->getPackageFilename($package);
        } else {
            $packageName = $fileName;
        }

        // Archive filename
        $filesystem->ensureDirectoryExists($targetDir);
        $target = realpath($targetDir).'/'.$packageName.'.'.$format;
        $filesystem->ensureDirectoryExists(dirname($target));

        if (!$this->overwriteFiles && file_exists($target)) {
            return $target;
        }

        if ($package instanceof RootPackageInterface) {
            $sourcePath = realpath('.');
        } else {
            // Directory used to download the sources
            $sourcePath = sys_get_temp_dir().'/composer_archive'.uniqid();
            $filesystem->ensureDirectoryExists($sourcePath);

            try {
                // Download sources
                $this->downloadManager->download($package, $sourcePath);
            } catch (\Exception $e) {
                $filesystem->removeDirectory($sourcePath);
                throw  $e;
            }

            // Check exclude from downloaded composer.json
            if (file_exists($composerJsonPath = $sourcePath.'/composer.json')) {
                $jsonFile = new JsonFile($composerJsonPath);
                $jsonData = $jsonFile->read();
                if (!empty($jsonData['archive']['exclude'])) {
                    $package->setArchiveExcludes($jsonData['archive']['exclude']);
                }
            }
        }

        // Create the archive
        $tempTarget = sys_get_temp_dir().'/composer_archive'.uniqid().'.'.$format;
        $filesystem->ensureDirectoryExists(dirname($tempTarget));

        if(method_exists ($package , 'getBasePath' ) &&  $package->getBasePath() !== '') {
            $archivePath = $usableArchiver->archive($sourcePath.'/'.$package->getBasePath().'/', $tempTarget, $format, $package->getArchiveExcludes(), $ignoreFilters);
        } else {
            $archivePath = $usableArchiver->archive($sourcePath, $tempTarget, $format, $package->getArchiveExcludes(), $ignoreFilters);
        }
        $filesystem->rename($archivePath, $target);

        // cleanup temporary download
        if (!$package instanceof RootPackageInterface) {
            $filesystem->removeDirectory($sourcePath);
        }
        $filesystem->remove($tempTarget);

        return $target;
    }
}