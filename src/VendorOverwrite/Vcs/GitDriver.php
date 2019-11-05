<?php

declare(strict_types=1);

namespace Composer\Satis\VendorOverwrite\Vcs;


use Composer\Json\JsonFile;

class GitDriver extends \Composer\Repository\Vcs\GitDriver
{
    protected $basePath = '';
    /**
     *
     */
    public function setBasePath($path) {
        $this->basePath = $path;
    }

    /**
     * @return string
     */
    public function getBasePath() {
        return $this->basePath;
    }

    /**
     * {@inheritdoc}
     */
    public function getComposerInformation($identifier)
    {
        if (!isset($this->infoCache[$this->basePath.$identifier])) {
//            if ($this->shouldCache($this->basePath.$identifier) && $res = $this->cache->read($this->basePath.$identifier)) {
//                return $this->infoCache[$this->basePath.$identifier] = JsonFile::parseJson($res);
//            }

            $composer = $this->getBaseComposerInformation($identifier);

            if ($this->shouldCache($this->basePath.$identifier)) {
                $this->cache->write($this->basePath.$identifier, json_encode($composer));
            }

            $this->infoCache[$this->basePath.$identifier] = $composer;
        }

        return $this->infoCache[$this->basePath.$identifier];
    }

    protected function getBaseComposerInformation($identifier)
    {
        $composerFileContent = $this->getFileContent($this->basePath . 'composer.json', $identifier);

        if (!$composerFileContent) {
            return null;
        }

        $composer = JsonFile::parseJson($composerFileContent, $identifier . $this->basePath .  ':composer.json');


        if (empty($composer['time']) && $changeDate = $this->getChangeDate($identifier)) {
            $composer['time'] = $changeDate->format(DATE_RFC3339);
        }

        return $composer;
    }
}