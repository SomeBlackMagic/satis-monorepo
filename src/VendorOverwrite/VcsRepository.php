<?php

declare(strict_types=1);

namespace Composer\Satis\VendorOverwrite;

use Composer\Config;
use Composer\Downloader\TransportException;
use Composer\EventDispatcher\EventDispatcher;
use Composer\IO\IOInterface;
use Composer\Package\Loader\InvalidPackageException;
use Composer\Package\Loader\LoaderInterface;
use Composer\Package\Loader\ValidatingArrayLoader;
use Composer\Package\Version\VersionParser;
use Composer\Repository\ArrayRepository;
use Composer\Repository\ConfigurableRepositoryInterface;
use Composer\Repository\InvalidRepositoryException;
use Composer\Repository\Vcs\VcsDriverInterface;
use Composer\Repository\VersionCacheInterface;
use Composer\Satis\VendorOverwrite\Package\CompletePackage;
use Composer\Satis\VendorOverwrite\Package\Loader\ArrayLoader;
use Composer\Satis\VendorOverwrite\Vcs\GitDriver;

/**
 * Class VcsRepository
 * @package Composer\Satis\VendorOverwrite
 */
class VcsRepository extends ArrayRepository implements ConfigurableRepositoryInterface
{
    protected $url;
    protected $packageName;
    protected $isVerbose;
    protected $isVeryVerbose;
    protected $io;
    protected $config;
    protected $versionParser;
    protected $type;
    protected $loader;
    protected $repoConfig;
    protected $branchErrorOccurred = false;
    private $drivers;
    /** @var VcsDriverInterface */
    private $driver;
    /** @var VersionCacheInterface */
    private $versionCache;
    private $emptyReferences = array();

    public function __construct(array $repoConfig, IOInterface $io, Config $config, EventDispatcher $dispatcher = null, array $drivers = null, VersionCacheInterface $versionCache = null)
    {
        parent::__construct();
        $this->drivers = $drivers ?: array(
            //'github' => GitHubDriver::class,
            'gitlab' => 'Composer\Repository\Vcs\GitLabDriver',
            'git-bitbucket' => 'Composer\Repository\Vcs\GitBitbucketDriver',
            'git' => GitDriver::class,
            'hg-bitbucket' => 'Composer\Repository\Vcs\HgBitbucketDriver',
            'hg' => 'Composer\Repository\Vcs\HgDriver',
            'perforce' => 'Composer\Repository\Vcs\PerforceDriver',
            'fossil' => 'Composer\Repository\Vcs\FossilDriver',
            // svn must be last because identifying a subversion server for sure is practically impossible
            'svn' => 'Composer\Repository\Vcs\SvnDriver',
        );

        $this->url = $repoConfig['url'];
        $this->io = $io;
        $this->type = isset($repoConfig['type']) ? $repoConfig['type'] : 'vcs';
        $this->isVerbose = $io->isVerbose();
        $this->isVeryVerbose = $io->isVeryVerbose();
        $this->config = $config;
        $this->repoConfig = $repoConfig;
        $this->versionCache = $versionCache;
    }

    public function getRepoConfig()
    {
        return $this->repoConfig;
    }

    public function setLoader(LoaderInterface $loader)
    {
        $this->loader = $loader;
    }

    /**
     * @return VcsDriverInterface
     */
    public function getDriver()
    {
        if ($this->driver) {
            return $this->driver;
        }

        if (isset($this->drivers[$this->type])) {
            $class = $this->drivers[$this->type];
            $this->driver = new $class($this->repoConfig, $this->io, $this->config);
            $this->driver->initialize();

            return $this->driver;
        }

        foreach ($this->drivers as $driver) {
            if ($driver::supports($this->io, $this->config, $this->url)) {
                $this->driver = new $driver($this->repoConfig, $this->io, $this->config);
                $this->driver->initialize();

                return $this->driver;
            }
        }

        foreach ($this->drivers as $driver) {
            if ($driver::supports($this->io, $this->config, $this->url, true)) {
                $this->driver = new $driver($this->repoConfig, $this->io, $this->config);
                $this->driver->initialize();

                return $this->driver;
            }
        }
    }

    public function hadInvalidBranches()
    {
        return $this->branchErrorOccurred;
    }

    public function getEmptyReferences()
    {
        return $this->emptyReferences;
    }

    protected function initialize()
    {
        parent::initialize();

        $isVerbose = $this->isVerbose;
        $isVeryVerbose = $this->isVeryVerbose;

        $driver = $this->getDriver();
        if (!$driver) {
            throw new \InvalidArgumentException('No driver found to handle VCS repository '.$this->url);
        }

        $this->versionParser = new VersionParser;
        if (!$this->loader) {
            $this->loader = new ArrayLoader($this->versionParser);
        }

        foreach ($driver->getTags() as $tag => $identifier) {
            $this->processTag($driver, $tag, $identifier);

            $isVerbose = $this->isVerbose;
            $isVeryVerbose = $this->isVeryVerbose;

            try {
                $driver->setBasePath('');
                $data = $driver->getComposerInformation($tag);
            } catch (\Exception $e) {
                if ($isVeryVerbose) {
                    $this->io->writeError('<error>Skipped parsing '.$driver->getRootIdentifier().', '.$e->getMessage().'</error>');
                }
                continue;
            }

            if(isset($data['config']['monorepo'])) {
                foreach ($data['config']['monorepo'] as $name => $path) {
                    $msg = 'Reading composer.json of <info>' . ($this->packageName ?: $this->url) .' in folder '.$path. '</info> (<comment>' . $tag . '</comment>)';
                    if ($isVeryVerbose) {
                        $this->io->writeError($msg);
                    } elseif ($isVerbose) {
                        $this->io->overwriteError($msg, false);
                    }
                    $driver->setBasePath($path . '/');
                    $this->processTag($driver, $tag, $identifier);
                }
            }
        }

        if (!$isVeryVerbose) {
            $this->io->overwriteError('', false);
        }

        $branches = $driver->getBranches();
        foreach ($branches as $branch => $identifier) {
            $driver->setBasePath('');
            $this->processBranch($driver, $branch, $identifier);

            try {
                $data = $driver->getComposerInformation($branch);
            } catch (\Exception $e) {
                if ($isVeryVerbose) {
                    $this->io->writeError('<error>Skipped parsing '.$driver->getRootIdentifier().', '.$e->getMessage().'</error>');
                }
                continue;
            }

            if(isset($data['config']['monorepo'])) {
                foreach ($data['config']['monorepo'] as $name => $path) {
                    $msg = 'Reading composer.json of <info>' . ($this->packageName ?: $this->url) .' in folder '.$path. '</info> (<comment>' . $branch . '</comment>)';
                    if ($isVeryVerbose) {
                        $this->io->writeError($msg);
                    } elseif ($isVerbose) {
                        $this->io->overwriteError($msg, false);
                    }
                    $driver->setBasePath($path . '/');
                    $this->processBranch($driver, $branch, $identifier);
                }
            }

        }
        $driver->cleanup();

        if (!$isVeryVerbose) {
            $this->io->overwriteError('', false);
        }

        if (!$this->getPackages()) {
            throw new InvalidRepositoryException('No valid composer.json was found in any branch or tag of '.$this->url.', could not load a package from it.');
        }
    }

    protected function processTag($driver, $tag, $identifier) {
        $isVerbose = $this->isVerbose;
        $isVeryVerbose = $this->isVeryVerbose;


        // strip the release- prefix from tags if present
        $tag = str_replace('release-', '', $tag);

//        $cachedPackage = $this->getCachedPackageVersion($tag, $identifier, $isVerbose, $isVeryVerbose);
//        if ($cachedPackage) {
//            $this->addPackage($cachedPackage);
//
//            return false;
//        } elseif ($cachedPackage === false) {
//            $this->emptyReferences[] = $identifier;
//
//            return false;
//        }

        if (!$parsedTag = $this->validateTag($tag)) {
            if ($isVeryVerbose) {
                $this->io->writeError('<warning>Skipped tag '.$tag.', invalid tag name</warning>');
            }
            return false;
        }

        try {
            if (!$data = $driver->getComposerInformation($identifier)) {
                if ($isVeryVerbose) {
                    $this->io->writeError('<warning>Skipped tag '.$tag.', no composer file</warning>');
                }
                $this->emptyReferences[] = $identifier;
                return false;
            }

            // manually versioned package
            if (isset($data['version'])) {
                $data['version_normalized'] = $this->versionParser->normalize($data['version']);
            } else {
                // auto-versioned package, read value from tag
                $data['version'] = $tag;
                $data['version_normalized'] = $parsedTag;
            }

            // make sure tag packages have no -dev flag
            $data['version'] = preg_replace('{[.-]?dev$}i', '', $data['version']);
            $data['version_normalized'] = preg_replace('{(^dev-|[.-]?dev$)}i', '', $data['version_normalized']);

            // broken package, version doesn't match tag
            if ($data['version_normalized'] !== $parsedTag) {
                if ($isVeryVerbose) {
                    $this->io->writeError('<warning>Skipped tag '.$tag.', tag ('.$parsedTag.') does not match version ('.$data['version_normalized'].') in composer.json</warning>');
                }
                return false;
            }

            $tagPackageName = isset($data['name']) ? $data['name'] : $this->packageName;
            if ($existingPackage = $this->findPackage($tagPackageName, $data['version_normalized'])) {
                if ($isVeryVerbose) {
                    $this->io->writeError('<warning>Skipped tag '.$tag.', it conflicts with an another tag ('.$existingPackage->getPrettyVersion().') as both resolve to '.$data['version_normalized'].' internally</warning>');
                }
                return false;
            }

            if ($isVeryVerbose) {
                $this->io->writeError('Importing tag '.$tag.' ('.$data['version_normalized'].')');
            }
            if($driver->getBasePath() !== '') {
                $data['base_path'] = $driver->getBasePath();
            }
            $this->addPackage($this->loader->load($this->preProcess($driver, $data, $identifier), CompletePackage::class));
        } catch (\Exception $e) {
            if ($e instanceof TransportException && $e->getCode() === 404) {
                $this->emptyReferences[] = $identifier;
            }
            if ($isVeryVerbose) {
                $this->io->writeError('<warning>Skipped tag '.$tag.', '.($e instanceof TransportException ? 'no composer file was found' : $e->getMessage()).'</warning>');
            }
            return false;
        }
        return true;
    }

    protected function processBranch($driver, $branch, $identifier) {
        $isVerbose = $this->isVerbose;
        $isVeryVerbose = $this->isVeryVerbose;

        $msg = 'Reading composer.json of <info>' . ($this->packageName ?: $this->url) . '</info> (<comment>' . $branch . '</comment>)';
        if ($isVeryVerbose) {
            $this->io->writeError($msg);
        } elseif ($isVerbose) {
            $this->io->overwriteError($msg, false);
        }

        if ($branch === 'trunk' && isset($branches['master'])) {
            if ($isVeryVerbose) {
                $this->io->writeError('<warning>Skipped branch '.$branch.', can not parse both master and trunk branches as they both resolve to 9999999-dev internally</warning>');
            }
            return false;
        }

        if (!$parsedBranch = $this->validateBranch($branch)) {
            if ($isVeryVerbose) {
                $this->io->writeError('<warning>Skipped branch '.$branch.', invalid name</warning>');
            }
            return false;
        }

        // make sure branch packages have a dev flag
        if ('dev-' === substr($parsedBranch, 0, 4) || '9999999-dev' === $parsedBranch) {
            $version = 'dev-' . $branch;
        } else {
            $prefix = substr($branch, 0, 1) === 'v' ? 'v' : '';
            $version = $prefix . preg_replace('{(\.9{7})+}', '.x', $parsedBranch);
        }

//        $cachedPackage = $this->getCachedPackageVersion($version, $identifier, $isVerbose, $isVeryVerbose);
//        if ($cachedPackage) {
//            $this->addPackage($cachedPackage);
//
//            return false;
//        } elseif ($cachedPackage === false) {
//            $this->emptyReferences[] = $identifier;
//
//            return false;
//        }

        try {
            if (!$data = $driver->getComposerInformation($identifier)) {
                if ($isVeryVerbose) {
                    $this->io->writeError('<warning>Skipped branch '.$branch.', no composer file</warning>');
                }
                $this->emptyReferences[] = $identifier;
                return false;
            }

            // branches are always auto-versioned, read value from branch name
            $data['version'] = $version;
            $data['version_normalized'] = $parsedBranch;

            if ($isVeryVerbose) {
                $this->io->writeError('Importing branch '.$branch.' ('.$data['version'].')');
            }

            if($driver->getBasePath() !== '') {
                $data['base_path'] = $driver->getBasePath();
            }
            $packageData = $this->preProcess($driver, $data, $identifier);
            $package = $this->loader->load($packageData);
            if ($this->loader instanceof ValidatingArrayLoader && $this->loader->getWarnings()) {
                throw new InvalidPackageException($this->loader->getErrors(), $this->loader->getWarnings(), $packageData);
            }
            $this->addPackage($package);
        } catch (TransportException $e) {
            if ($e->getCode() === 404) {
                $this->emptyReferences[] = $identifier;
            }
            if ($isVeryVerbose) {
                $this->io->writeError('<warning>Skipped branch '.$branch.', no composer file was found</warning>');
            }
            return false;
        } catch (\Exception $e) {
            if (!$isVeryVerbose) {
                $this->io->writeError('');
            }
            $this->branchErrorOccurred = true;
            $this->io->writeError('<error>Skipped branch '.$branch.', '.$e->getMessage().'</error>');
            $this->io->writeError('');
            return false;
        }
    }


    protected function preProcess(VcsDriverInterface $driver, array $data, $identifier)
    {
        // keep the name of the main identifier for all packages
        $dataPackageName = isset($data['name']) ? $data['name'] : null;
        $data['name'] = $this->packageName ?: $dataPackageName;

        if (!isset($data['dist'])) {
            $data['dist'] = $driver->getDist($identifier);
        }
        if (!isset($data['source'])) {
            $data['source'] = $driver->getSource($identifier);
        }

        return $data;
    }

    private function validateBranch($branch)
    {
        try {
            return $this->versionParser->normalizeBranch($branch);
        } catch (\Exception $e) {
        }

        return false;
    }

    private function validateTag($version)
    {
        try {
            return $this->versionParser->normalize($version);
        } catch (\Exception $e) {
        }

        return false;
    }

    /**
     * @param $version
     * @param $identifier
     * @param $isVerbose
     * @param $isVeryVerbose
     * @return bool|void|null
     */
    private function getCachedPackageVersion($version, $identifier, $isVerbose, $isVeryVerbose)
    {
        if (!$this->versionCache) {
            return;
        }

        $cachedPackage = $this->versionCache->getVersionPackage($version, $identifier);
        if ($cachedPackage === false) {
            if ($isVeryVerbose) {
                $this->io->writeError('<warning>Skipped '.$version.', no composer file (cached from ref '.$identifier.')</warning>');
            }

            return false;
        }

        if ($cachedPackage) {
            $msg = 'Found cached composer.json of <info>' . ($this->packageName ?: $this->url) . '</info> (<comment>' . $version . '</comment>)';
            if ($isVeryVerbose) {
                $this->io->writeError($msg);
            } elseif ($isVerbose) {
                $this->io->overwriteError($msg, false);
            }

            if ($existingPackage = $this->findPackage($cachedPackage['name'], $cachedPackage['version_normalized'])) {
                if ($isVeryVerbose) {
                    $this->io->writeError('<warning>Skipped cached version '.$version.', it conflicts with an another tag ('.$existingPackage->getPrettyVersion().') as both resolve to '.$cachedPackage['version_normalized'].' internally</warning>');
                }
                $cachedPackage = null;
            }
        }

        if ($cachedPackage) {
            return $this->loader->load($cachedPackage);
        }

        return null;
    }
}