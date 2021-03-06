<?php
namespace Drush\Preflight;

use Drush\Drush;
use Drush\Config\Environment;
use Drush\Config\ConfigLocator;
use Drush\Config\EnvironmentConfigLoader;
use Drush\SiteAlias\SiteAliasManager;
use DrupalFinder\DrupalFinder;

/**
 * The Drush preflight determines what needs to be done for this request.
 * The preflight happens after Drush has loaded its autoload file, but
 * prior to loading Drupal's autoload file and setting up the DI container.
 *
 * - Pre-parse commandline arguements
 * - Read configuration .yml files
 * - Determine the site to use
 */
class Preflight
{
    /**
     * @var Environment $environment
     */
    protected $environment;

    /**
     * @var PreflightVerify
     */
    protected $verify;

    /**
     * @var ConfigLocator
     */
    protected $configLocator;

    /**
     * @var DrupalFinder
     */
    protected $drupalFinder;

    /**
     * @var PreflightArgs
     */
    protected $preflightArgs;

    /**
     * @var SiteAliasManager
     */
    protected $aliasManager;

    /**
     * Preflight constructor
     */
    public function __construct(Environment $environment, $verify = null, $configLocator = null)
    {
        $this->environment = $environment;
        $this->verify = $verify ?: new PreflightVerify();
        $this->configLocator = $configLocator ?: new ConfigLocator('DRUSH_');
        $this->drupalFinder = new DrupalFinder();
    }

    /**
     * Perform preliminary initialization. This mostly involves setting up
     * legacy systems.
     */
    public function init()
    {
        // Define legacy constants, and include legacy files that Drush still needs
        LegacyPreflight::includeCode($this->environment->drushBasePath());
        LegacyPreflight::defineConstants($this->environment, $this->preflightArgs->applicationPath());
        LegacyPreflight::setContexts($this->environment);
    }

    /**
     * Remapping table for arguments. Anything found in a key
     * here will be converted to the corresponding value entry.
     *
     * For example:
     *    --ssh-options='-i mysite_dsa'
     * will become:
     *    -Dssh.options='-i mysite_dsa'
     *
     * TODO: We could consider loading this from a file or some other
     * source. However, this table is needed very early -- even earlier
     * than config is loaded (since this is needed for preflighting the
     * arguments, which can select config files to load). Hardcoding
     * is probably best; we might want to move to another class, perhaps.
     * We also need this prior to Dependency Injection, though.
     *
     * Eventually, we might want to expose this table to some form of
     * 'help' output, so folks can see the available conversions.
     */
    protected function remapOptions()
    {
        return [
            '--ssh-options' => '-Dssh.options',
            '--php' => '-Druntime.php.path',
            '--php-options' => '-Druntime.php.options',
            '--php-notices' => '-Druntime.php.notices',
            '--halt-on-error' => '-Druntime.php.halt-on-error',
            '--output_charset' => '-Dio.output.charset',
            '--output-charset' => '-Dio.output.charset',
            '--db-su' => '-Dsql.db-su',
            '--notify' => '-Dnotify.duration',
            '--xh-link' => '-Dxh.link',
        ];
    }

    /**
     * Symfony Console dislikes certain command aliases, because
     * they are too similar to other Drush commands that contain
     * the same characters.  To avoid the "I don't know which
     * command you mean"-type errors, we will replace problematic
     * aliases with their longhand equivalents.
     *
     * This should be fixed in Symfony Console.
     */
    protected function remapCommandAliases()
    {
        return [
            'si' => 'site-install',
            'en' => 'pm-enable',
        ];
    }

    /**
     * Preprocess the args, removing any @sitealias that may be present.
     * Arguments and options not used during preflight will be processed
     * with an ArgvInput.
     */
    public function preflightArgs($argv)
    {
        $argProcessor = new ArgsPreprocessor();
        $remapper = new ArgsRemapper($this->remapOptions(), $this->remapCommandAliases());
        $preflightArgs = new PreflightArgs([]);
        $argProcessor->setArgsRemapper($remapper);

        $argProcessor->parse($argv, $preflightArgs);

        return $preflightArgs;
    }

    /**
     * Create the initial config locator object, and inject any needed
     * settings, paths and so on into it.
     */
    public function prepareConfig(Environment $environment)
    {
        // Make our environment settings available as configuration items
        $this->configLocator->addEnvironment($environment);

        $this->configLocator->setLocal($this->preflightArgs->isLocal());
        $this->configLocator->addUserConfig($this->preflightArgs->configPaths(), $environment->systemConfigPath(), $environment->userConfigPath());
        $this->configLocator->addDrushConfig($environment->drushBasePath());
    }

    /**
     * Start code coverage collection
     */
    public function startCoverage()
    {
        if ($coverage_file = $this->preflightArgs->coverageFile()) {
            // TODO: modernize code coverage handling
            drush_set_context('DRUSH_CODE_COVERAGE', $coverage_file);
            xdebug_start_code_coverage(XDEBUG_CC_UNUSED | XDEBUG_CC_DEAD_CODE);
            register_shutdown_function('drush_coverage_shutdown');
        }
    }

    public function createInput()
    {
        return $this->preflightArgs->createInput();
    }

    public function getCommandFilePaths()
    {
        // Find all of the available commandfiles, save for those that are
        // provided by modules in the selected site; those will be added
        // during bootstrap.
        return $this->configLocator->getCommandFilePaths($this->preflightArgs->commandPaths(), $this->drupalFinder()->getDrupalRoot());
    }

    public function loadSiteAutoloader()
    {
        return $this->environment()->loadSiteAutoloader($this->drupalFinder()->getDrupalRoot());
    }

    public function config()
    {
        return $this->configLocator->config();
    }

    public function preflight($argv)
    {
        // Fail fast if there is anything in our environment that does not check out
        $this->verify->verify($this->environment);

        // Get the preflight args and begin collecting configuration files.
        $this->preflightArgs = $this->preflightArgs($argv);
        $this->prepareConfig($this->environment);

        // Do legacy initialization (load static includes, define old constants, etc.)
        $this->init();

        // Start code coverage
        $this->startCoverage();

        // Get the config files provided by prepareConfig()
        $config = $this->config();

        // Copy items from the preflight args into configuration.
        // This will also load certain config values into the preflight args.
        $this->preflightArgs->applyToConfig($config);

        // Determine the local site targeted, if any.
        // Extend configuration and alias files to include files in
        // target site.
        $root = $this->findSelectedSite();
        $this->configLocator->addSitewideConfig($root);
        $this->configLocator->setComposerRoot($this->drupalFinder()->getComposerRoot());

        // Look up the locations where alias files may be found.
        $paths = $this->configLocator->getSiteAliasPaths($this->preflightArgs->aliasPaths(), $this->environment);

        // Configure alias manager.
        $this->aliasManager = (new SiteAliasManager())->addSearchLocations($paths);
        $selfAliasRecord = $this->aliasManager->findSelf($this->preflightArgs, $this->environment, $root);
        $this->configLocator->addAliasConfig($selfAliasRecord->exportConfig());

        // Process the selected alias. This might change the selected site,
        // so we will add new site-wide config location for the new root.
        $root = $this->setSelectedSite($selfAliasRecord->localRoot());

        // Now that we have our final Drupal root, check to see if there is
        // a site-local Drush. If there is, we will redispatch to it.
        // NOTE: termination handlers have not been set yet, so it is okay
        // to exit early without taking special action.
        $status = RedispatchToSiteLocal::redispatchIfSiteLocalDrush($argv, $root, $this->environment->vendorPath());
        if ($status !== false) {
            return $status;
        }

        // If we did not redispatch, then add the site-wide config for the
        // new root (if the root did in fact change) and continue.
        $this->configLocator->addSitewideConfig($root);

        // Remember the paths to all the files we loaded, so that we can
        // report on it from Drush status or wherever else it may be needed.
        $config->set('runtime.config.paths', $this->configLocator->configFilePaths());

        // We need to check the php minimum version again, in case anyone
        // has set it to something higher in one of the config files we loaded.
        $this->verify->confirmPhpVersion($config->get('drush.php.minimum-version'));

        return false;
    }

    /**
     * Find the site the user selected based on --root or cwd. If neither of
     * those result in a site, then we will fall back to the vendor path.
     */
    protected function findSelectedSite()
    {
        // TODO: If we want to support ONLY site-local Drush (which is
        // DIFFERENT than --local), then skip the call to `$preflightArgs->selectedSite`
        // and just assign `false` to $selectedRoot.
        $selectedRoot = $this->preflightArgs->selectedSite($this->environment->cwd());
        return $this->setSelectedSite($selectedRoot, $this->environment->vendorPath());
    }

    /**
     * Use the DrupalFinder to locate the Drupal Root + Composer Root at
     * the selected root, or, if nothing is found there, at a fallback path.
     *
     * @param string $selectedRoot The location to being searching for a site
     * @param string|bool $fallbackPath The secondary location to search (usualy the vendor director)
     */
    protected function setSelectedSite($selectedRoot, $fallbackPath = false)
    {
        $foundRoot = $this->drupalFinder->locateRoot($selectedRoot);
        if (!$foundRoot && $fallbackPath) {
            $this->drupalFinder->locateRoot($fallbackPath);
        }
        return $this->drupalFinder()->getDrupalRoot();
    }

    /**
     * Return the Drupal Finder
     *
     * @return DrupalFinder
     */
    public function drupalFinder()
    {
        return $this->drupalFinder;
    }

    /**
     * Return the alias manager
     *
     * @return SiteAliasManager
     */
    public function aliasManager()
    {
        return $this->aliasManager;
    }

    /**
     * Return the environment
     *
     * @return Environment
     */
    public function environment()
    {
        return $this->environment;
    }
}
