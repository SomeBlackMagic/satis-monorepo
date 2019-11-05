<?php

declare(strict_types=1);

namespace Composer\Satis\VendorOverwrite;


use Composer\Autoload\AutoloadGenerator;
use Composer\Composer;
use Composer\Config;
use Composer\Config\JsonConfigSource;
use Composer\Downloader\DownloadManager;
use Composer\EventDispatcher\Event;
use Composer\EventDispatcher\EventDispatcher;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\IO\NullIO;
use Composer\Json\JsonFile;
use Composer\Package\Archiver\PharArchiver;
use Composer\Package\Archiver\ZipArchiver;
use Composer\Package\Loader\RootPackageLoader;
use Composer\Package\Locker;
use Composer\Package\Version\VersionGuesser;
use Composer\Package\Version\VersionParser;
use Composer\Plugin\PluginEvents;
use Composer\Satis\VendorOverwrite\Package\Archiver\ArchiveManager;
use Composer\Util\ProcessExecutor;
use Seld\JsonLint\DuplicateKeyException;
use Seld\JsonLint\JsonParser;
use Composer\Package\RootPackage;

class ComposerFactory extends Factory
{
    public function createComposer(
        IOInterface $io,
        $localConfig = null,
        $disablePlugins = false,
        $cwd = null,
        $fullLoad = true
    ) {
        $cwd = $cwd ?: getcwd();

        // load Composer configuration
        if (null === $localConfig) {
            $localConfig = static::getComposerFile();
        }

        if (is_string($localConfig)) {
            $composerFile = $localConfig;

            $file = new JsonFile($localConfig, null, $io);

            if (!$file->exists()) {
                if ($localConfig === './composer.json' || $localConfig === 'composer.json') {
                    $message = 'Composer could not find a composer.json file in '.$cwd;
                } else {
                    $message = 'Composer could not find the config file: '.$localConfig;
                }
                $instructions = 'To initialize a project, please create a composer.json file as described in the https://getcomposer.org/ "Getting Started" section';
                throw new \InvalidArgumentException($message.PHP_EOL.$instructions);
            }

            $file->validateSchema(JsonFile::LAX_SCHEMA);
            $jsonParser = new JsonParser;
            try {
                $jsonParser->parse(file_get_contents($localConfig), JsonParser::DETECT_KEY_CONFLICTS);
            } catch (DuplicateKeyException $e) {
                $details = $e->getDetails();
                $io->writeError('<warning>Key '.$details['key'].' is a duplicate in '.$localConfig.' at line '.$details['line'].'</warning>');
            }

            $localConfig = $file->read();
        }

        // Load config and override with local config/auth config
        $config = static::createConfig($io, $cwd);
        $config->merge($localConfig);
        if (isset($composerFile)) {
            $io->writeError('Loading config file ' . $composerFile, true, IOInterface::DEBUG);
            $config->setConfigSource(new JsonConfigSource(new JsonFile(realpath($composerFile), null, $io)));

            $localAuthFile = new JsonFile(dirname(realpath($composerFile)) . '/auth.json', null, $io);
            if ($localAuthFile->exists()) {
                $io->writeError('Loading config file ' . $localAuthFile->getPath(), true, IOInterface::DEBUG);
                $config->merge(array('config' => $localAuthFile->read()));
                $config->setAuthConfigSource(new JsonConfigSource($localAuthFile, true));
            }
        }

        $vendorDir = $config->get('vendor-dir');

        // initialize composer
        $composer = new Composer();
        $composer->setConfig($config);

        if ($fullLoad) {
            // load auth configs into the IO instance
            $io->loadConfiguration($config);
        }

        $rfs = self::createRemoteFilesystem($io, $config);

        // initialize event dispatcher
        $dispatcher = new EventDispatcher($composer, $io);
        $composer->setEventDispatcher($dispatcher);

        // initialize repository manager
        $rm = RepositoryFactory::manager($io, $config, $dispatcher, $rfs);
        $composer->setRepositoryManager($rm);

        // load local repository
        $this->addLocalRepository($io, $rm, $vendorDir);

        // force-set the version of the global package if not defined as
        // guessing it adds no value and only takes time
        if (!$fullLoad && !isset($localConfig['version'])) {
            $localConfig['version'] = '1.0.0';
        }

        // load package
        $parser = new VersionParser;
        $guesser = new VersionGuesser($config, new ProcessExecutor($io), $parser);
        $loader = new RootPackageLoader($rm, $config, $parser, $guesser, $io);
        $package = $loader->load($localConfig, RootPackage::class, $cwd);
        $composer->setPackage($package);

        // initialize installation manager
        $im = $this->createInstallationManager();
        $composer->setInstallationManager($im);

        if ($fullLoad) {
            // initialize download manager
            $dm = $this->createDownloadManager($io, $config, $dispatcher, $rfs);
            $composer->setDownloadManager($dm);

            // initialize autoload generator
            $generator = new AutoloadGenerator($dispatcher, $io);
            $composer->setAutoloadGenerator($generator);

            // initialize archive manager
            $am = $this->createArchiveManager($config, $dm);
            $composer->setArchiveManager($am);
        }

        // add installers to the manager (must happen after download manager is created since they read it out of $composer)
        $this->createDefaultInstallers($im, $composer, $io);

        if ($fullLoad) {
            $globalComposer = null;
            if (realpath($config->get('home')) !== $cwd) {
                $globalComposer = $this->createGlobalComposer($io, $config, $disablePlugins);
            }

            $pm = $this->createPluginManager($io, $composer, $globalComposer, $disablePlugins);
            $composer->setPluginManager($pm);

            $pm->loadInstalledPlugins();
        }

        // init locker if possible
        if ($fullLoad && isset($composerFile)) {
            $lockFile = 'json' === pathinfo($composerFile, PATHINFO_EXTENSION)
                ? substr($composerFile, 0, -4).'lock'
                : $composerFile . '.lock';

            $locker = new Locker($io, new JsonFile($lockFile, null, $io), $rm, $im, file_get_contents($composerFile));
            $composer->setLocker($locker);
        }

        if ($fullLoad) {
            $initEvent = new Event(PluginEvents::INIT);
            $composer->getEventDispatcher()->dispatch($initEvent->getName(), $initEvent);

            // once everything is initialized we can
            // purge packages from local repos if they have been deleted on the filesystem
            if ($rm->getLocalRepository()) {
                $this->purgePackages($rm->getLocalRepository(), $im);
            }
        }

        return $composer;

    }


    /**
     * @param  Config                     $config The configuration
     * @param  DownloadManager $dm     Manager use to download sources
     * @return ArchiveManager
     */
    public function createArchiveManager(Config $config, DownloadManager $dm = null)
    {
        if (null === $dm) {
            $io = new NullIO();
            $io->loadConfiguration($config);
            $dm = $this->createDownloadManager($io, $config);
        }

        $am = new ArchiveManager($dm);
        $am->addArchiver(new ZipArchiver());
        $am->addArchiver(new PharArchiver());

        return $am;
    }


}