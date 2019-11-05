<?php

declare(strict_types=1);

namespace Composer\Satis\VendorOverwrite\Vcs;


use Composer\Json\JsonFile;

class GitHubDriver extends \Composer\Repository\Vcs\GitHubDriver
{

    /**
     * {@inheritDoc}
     */
    public function getComposerInformation($identifier, $path = null)
    {
        if ($this->gitDriver) {
            return $this->gitDriver->getComposerInformation($identifier);
        }

        if (!isset($this->infoCache[$identifier])) {
            if ($this->shouldCache($identifier) && $res = $this->cache->read($identifier)) {
                return $this->infoCache[$identifier] = JsonFile::parseJson($res);
            }

            $composer = $this->getBaseComposerInformation($identifier, $path);

            if ($composer) {
                // specials for github
                if (!isset($composer['support']['source'])) {
                    $label = array_search($identifier, $this->getTags()) ?: array_search($identifier, $this->getBranches()) ?: $identifier;
                    $composer['support']['source'] = sprintf('https://%s/%s/%s/tree/%s', $this->originUrl, $this->owner, $this->repository, $label);
                }
                if (!isset($composer['support']['issues']) && $this->hasIssues) {
                    $composer['support']['issues'] = sprintf('https://%s/%s/%s/issues', $this->originUrl, $this->owner, $this->repository);
                }
            }

            if ($this->shouldCache($identifier)) {
                $this->cache->write($identifier, json_encode($composer));
            }

            $this->infoCache[$identifier] = $composer;
        }

        return $this->infoCache[$identifier];
    }


    protected function getBaseComposerInformation($identifier, $path = null)
    {
        if ($path !== null) {
            $composerFileContent = $this->getFileContent($path . 'composer.json', $identifier);
        } else {
            $composerFileContent = $this->getFileContent($path . 'composer.json', $identifier);
        }


        if (!$composerFileContent) {
            return null;
        }

        if ($path !== null) {
            $composer = JsonFile::parseJson($composerFileContent, $identifier . $path .  ':composer.json');
        } else {
            $composer = JsonFile::parseJson($composerFileContent, $identifier  .  ':composer.json');
        }


        if (empty($composer['time']) && $changeDate = $this->getChangeDate($identifier)) {
            $composer['time'] = $changeDate->format(DATE_RFC3339);
        }

        return $composer;
    }
}