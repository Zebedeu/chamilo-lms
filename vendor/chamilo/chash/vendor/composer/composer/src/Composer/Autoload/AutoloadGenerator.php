<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Autoload;

use Composer\Config;
use Composer\Installer\InstallationManager;
use Composer\Package\AliasPackage;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Util\Filesystem;
use Composer\Script\EventDispatcher;
use Composer\Script\ScriptEvents;

/**
 * @author Igor Wiedler <igor@wiedler.ch>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class AutoloadGenerator
{
    /**
     * @var EventDispatcher
     */
    private $eventDispatcher;

    public function __construct(EventDispatcher $eventDispatcher)
    {
        $this->eventDispatcher = $eventDispatcher;
    }

    public function dump(Config $config, InstalledRepositoryInterface $localRepo, PackageInterface $mainPackage, InstallationManager $installationManager, $targetDir, $scanPsr0Packages = false, $suffix = '')
    {
        $this->eventDispatcher->dispatch(ScriptEvents::PRE_AUTOLOAD_DUMP);

        $filesystem = new Filesystem();
        $filesystem->ensureDirectoryExists($config->get('vendor-dir'));
        $basePath = $filesystem->normalizePath(getcwd());
        $vendorPath = $filesystem->normalizePath(realpath($config->get('vendor-dir')));
        $useGlobalIncludePath = (bool) $config->get('use-include-path');
        $targetDir = $vendorPath.'/'.$targetDir;
        $filesystem->ensureDirectoryExists($targetDir);

        $vendorPathCode = $filesystem->findShortestPathCode(realpath($targetDir), $vendorPath, true);
        $vendorPathCode52 = str_replace('__DIR__', 'dirname(__FILE__)', $vendorPathCode);
        $vendorPathToTargetDirCode = $filesystem->findShortestPathCode($vendorPath, realpath($targetDir), true);

        $appBaseDirCode = $filesystem->findShortestPathCode($vendorPath, $basePath, true);
        $appBaseDirCode = str_replace('__DIR__', '$vendorDir', $appBaseDirCode);

        $namespacesFile = <<<EOF
<?php

// autoload_namespaces.php @generated by Composer

\$vendorDir = $vendorPathCode52;
\$baseDir = $appBaseDirCode;

return array(

EOF;

        $packageMap = $this->buildPackageMap($installationManager, $mainPackage, $localRepo->getCanonicalPackages());
        $autoloads = $this->parseAutoloads($packageMap, $mainPackage);

        foreach ($autoloads['psr-0'] as $namespace => $paths) {
            $exportedPaths = array();
            foreach ($paths as $path) {
                $exportedPaths[] = $this->getPathCode($filesystem, $basePath, $vendorPath, $path);
            }
            $exportedPrefix = var_export($namespace, true);
            $namespacesFile .= "    $exportedPrefix => ";
            $namespacesFile .= "array(".implode(', ', $exportedPaths)."),\n";
        }
        $namespacesFile .= ");\n";

        $classmapFile = <<<EOF
<?php

// autoload_classmap.php @generated by Composer

\$vendorDir = $vendorPathCode52;
\$baseDir = $appBaseDirCode;

return array(

EOF;

        // add custom psr-0 autoloading if the root package has a target dir
        $targetDirLoader = null;
        $mainAutoload = $mainPackage->getAutoload();
        if ($mainPackage->getTargetDir() && !empty($mainAutoload['psr-0'])) {
            $levels = count(explode('/', $filesystem->normalizePath($mainPackage->getTargetDir())));
            $prefixes = implode(', ', array_map(function ($prefix) {
                return var_export($prefix, true);
            }, array_keys($mainAutoload['psr-0'])));
            $baseDirFromTargetDirCode = $filesystem->findShortestPathCode($targetDir, $basePath, true);

            $targetDirLoader = <<<EOF

    public static function autoload(\$class)
    {
        \$dir = $baseDirFromTargetDirCode . '/';
        \$prefixes = array($prefixes);
        foreach (\$prefixes as \$prefix) {
            if (0 !== strpos(\$class, \$prefix)) {
                continue;
            }
            \$path = \$dir . implode('/', array_slice(explode('\\\\', \$class), $levels)).'.php';
            if (!\$path = stream_resolve_include_path(\$path)) {
                return false;
            }
            require \$path;

            return true;
        }
    }

EOF;
        }

        // flatten array
        $classMap = array();
        if ($scanPsr0Packages) {
            foreach ($autoloads['psr-0'] as $namespace => $paths) {
                foreach ($paths as $dir) {
                    $dir = $filesystem->normalizePath($filesystem->isAbsolutePath($dir) ? $dir : $basePath.'/'.$dir);
                    if (!is_dir($dir)) {
                        continue;
                    }
                    $whitelist = sprintf(
                        '{%s/%s.+(?<!(?<!/)Test\.php)$}',
                        preg_quote($dir),
                        strpos($namespace, '_') === false ? preg_quote(strtr($namespace, '\\', '/')) : ''
                    );
                    foreach (ClassMapGenerator::createMap($dir, $whitelist) as $class => $path) {
                        if ('' === $namespace || 0 === strpos($class, $namespace)) {
                            if (!isset($classMap[$class])) {
                                $path = $this->getPathCode($filesystem, $basePath, $vendorPath, $path);
                                $classMap[$class] = $path.",\n";
                            }
                        }
                    }
                }
            }
        }

        $autoloads['classmap'] = new \RecursiveIteratorIterator(new \RecursiveArrayIterator($autoloads['classmap']));
        foreach ($autoloads['classmap'] as $dir) {
            foreach (ClassMapGenerator::createMap($dir) as $class => $path) {
                $path = $this->getPathCode($filesystem, $basePath, $vendorPath, $path);
                $classMap[$class] = $path.",\n";
            }
        }

        ksort($classMap);
        foreach ($classMap as $class => $code) {
            $classmapFile .= '    '.var_export($class, true).' => '.$code;
        }
        $classmapFile .= ");\n";

        if (!$suffix) {
            $suffix = md5(uniqid('', true));
        }

        file_put_contents($targetDir.'/autoload_namespaces.php', $namespacesFile);
        file_put_contents($targetDir.'/autoload_classmap.php', $classmapFile);
        if ($includePathFile = $this->getIncludePathsFile($packageMap, $filesystem, $basePath, $vendorPath, $vendorPathCode52, $appBaseDirCode)) {
            file_put_contents($targetDir.'/include_paths.php', $includePathFile);
        }
        if ($includeFilesFile = $this->getIncludeFilesFile($autoloads['files'], $filesystem, $basePath, $vendorPath, $vendorPathCode52, $appBaseDirCode)) {
            file_put_contents($targetDir.'/autoload_files.php', $includeFilesFile);
        }
        file_put_contents($vendorPath.'/autoload.php', $this->getAutoloadFile($vendorPathToTargetDirCode, $suffix));
        file_put_contents($targetDir.'/autoload_real.php', $this->getAutoloadRealFile(true, true, (bool) $includePathFile, $targetDirLoader, (bool) $includeFilesFile, $vendorPathCode, $appBaseDirCode, $suffix, $useGlobalIncludePath));

        // use stream_copy_to_stream instead of copy
        // to work around https://bugs.php.net/bug.php?id=64634
        $sourceLoader = fopen(__DIR__.'/ClassLoader.php', 'r');
        $targetLoader = fopen($targetDir.'/ClassLoader.php', 'w+');
        stream_copy_to_stream($sourceLoader, $targetLoader);
        fclose($sourceLoader);
        fclose($targetLoader);
        unset($sourceLoader, $targetLoader);

        $this->eventDispatcher->dispatch(ScriptEvents::POST_AUTOLOAD_DUMP);
    }

    public function buildPackageMap(InstallationManager $installationManager, PackageInterface $mainPackage, array $packages)
    {
        // build package => install path map
        $packageMap = array(array($mainPackage, ''));

        foreach ($packages as $package) {
            if ($package instanceof AliasPackage) {
                continue;
            }

            $packageMap[] = array(
                $package,
                $installationManager->getInstallPath($package)
            );
        }

        return $packageMap;
    }

    /**
     * Compiles an ordered list of namespace => path mappings
     *
     * @param  array            $packageMap  array of array(package, installDir-relative-to-composer.json)
     * @param  PackageInterface $mainPackage root package instance
     * @return array            array('psr-0' => array('Ns\\Foo' => array('installDir')))
     */
    public function parseAutoloads(array $packageMap, PackageInterface $mainPackage)
    {
        $mainPackageMap = array_shift($packageMap);
        $sortedPackageMap = $this->sortPackageMap($packageMap);
        $sortedPackageMap[] = $mainPackageMap;
        array_unshift($packageMap, $mainPackageMap);

        $psr0 = $this->parseAutoloadsType($packageMap, 'psr-0', $mainPackage);
        $classmap = $this->parseAutoloadsType($sortedPackageMap, 'classmap', $mainPackage);
        $files = $this->parseAutoloadsType($sortedPackageMap, 'files', $mainPackage);

        krsort($psr0);

        return array('psr-0' => $psr0, 'classmap' => $classmap, 'files' => $files);
    }

    /**
     * Registers an autoloader based on an autoload map returned by parseAutoloads
     *
     * @param  array       $autoloads see parseAutoloads return value
     * @return ClassLoader
     */
    public function createLoader(array $autoloads)
    {
        $loader = new ClassLoader();

        if (isset($autoloads['psr-0'])) {
            foreach ($autoloads['psr-0'] as $namespace => $path) {
                $loader->add($namespace, $path);
            }
        }

        return $loader;
    }

    protected function getIncludePathsFile(array $packageMap, Filesystem $filesystem, $basePath, $vendorPath, $vendorPathCode, $appBaseDirCode)
    {
        $includePaths = array();

        foreach ($packageMap as $item) {
            list($package, $installPath) = $item;

            if (null !== $package->getTargetDir() && strlen($package->getTargetDir()) > 0) {
                $installPath = substr($installPath, 0, -strlen('/'.$package->getTargetDir()));
            }

            foreach ($package->getIncludePaths() as $includePath) {
                $includePath = trim($includePath, '/');
                $includePaths[] = empty($installPath) ? $includePath : $installPath.'/'.$includePath;
            }
        }

        if (!$includePaths) {
            return;
        }

        $includePathsCode = '';
        foreach ($includePaths as $path) {
            $includePathsCode .= "    " . $this->getPathCode($filesystem, $basePath, $vendorPath, $path) . ",\n";
        }

        return <<<EOF
<?php

// include_paths.php @generated by Composer

\$vendorDir = $vendorPathCode;
\$baseDir = $appBaseDirCode;

return array(
$includePathsCode);

EOF;
    }

    protected function getIncludeFilesFile(array $files, Filesystem $filesystem, $basePath, $vendorPath, $vendorPathCode, $appBaseDirCode)
    {
        $filesCode = '';
        $files = new \RecursiveIteratorIterator(new \RecursiveArrayIterator($files));
        foreach ($files as $functionFile) {
            $filesCode .= '    '.$this->getPathCode($filesystem, $basePath, $vendorPath, $functionFile).",\n";
        }

        if (!$filesCode) {
            return FALSE;
        }

        return <<<EOF
<?php

// autoload_files.php @generated by Composer

\$vendorDir = $vendorPathCode;
\$baseDir = $appBaseDirCode;

return array(
$filesCode);
EOF;
    }

    protected function getPathCode(Filesystem $filesystem, $basePath, $vendorPath, $path)
    {
        if (!$filesystem->isAbsolutePath($path)) {
            $path = $basePath . '/' . $path;
        }
        $path = $filesystem->normalizePath($path);

        $baseDir = '';
        if (strpos($path, $vendorPath) === 0) {
            $path = substr($path, strlen($vendorPath));
            $baseDir = '$vendorDir';

            if ($path !== false) {
                $baseDir .= " . ";
            }
        } else {
            $path = $filesystem->normalizePath($filesystem->findShortestPath($basePath, $path, true));
            if (!$filesystem->isAbsolutePath($path)) {
                $baseDir = '$baseDir . ';
                $path = '/' . $path;
            }
        }

        if (preg_match('/\.phar$/', $path)) {
            $baseDir = "'phar://' . " . $baseDir;
        }

        return $baseDir . (($path !== false) ? var_export($path, true) : "");
    }

    protected function getAutoloadFile($vendorPathToTargetDirCode, $suffix)
    {
        return <<<AUTOLOAD
<?php

// autoload.php @generated by Composer

require_once $vendorPathToTargetDirCode . '/autoload_real.php';

return ComposerAutoloaderInit$suffix::getLoader();

AUTOLOAD;
    }

    protected function getAutoloadRealFile($usePSR0, $useClassMap, $useIncludePath, $targetDirLoader, $useIncludeFiles, $vendorPathCode, $appBaseDirCode, $suffix, $useGlobalIncludePath)
    {
        // TODO the class ComposerAutoloaderInit should be revert to a closure
        // when APC has been fixed:
        // - https://github.com/composer/composer/issues/959
        // - https://bugs.php.net/bug.php?id=52144
        // - https://bugs.php.net/bug.php?id=61576
        // - https://bugs.php.net/bug.php?id=59298

        $file = <<<HEADER
<?php

// autoload_real.php @generated by Composer

class ComposerAutoloaderInit$suffix
{
    private static \$loader;

    public static function loadClassLoader(\$class)
    {
        if ('Composer\\Autoload\\ClassLoader' === \$class) {
            require __DIR__ . '/ClassLoader.php';
        }
    }

    public static function getLoader()
    {
        if (null !== self::\$loader) {
            return self::\$loader;
        }

        spl_autoload_register(array('ComposerAutoloaderInit$suffix', 'loadClassLoader'), true, true);
        self::\$loader = \$loader = new \\Composer\\Autoload\\ClassLoader();
        spl_autoload_unregister(array('ComposerAutoloaderInit$suffix', 'loadClassLoader'));

        \$vendorDir = $vendorPathCode;
        \$baseDir = $appBaseDirCode;


HEADER;

        if ($useIncludePath) {
            $file .= <<<'INCLUDE_PATH'
        $includePaths = require __DIR__ . '/include_paths.php';
        array_push($includePaths, get_include_path());
        set_include_path(join(PATH_SEPARATOR, $includePaths));


INCLUDE_PATH;
        }

        if ($usePSR0) {
            $file .= <<<'PSR0'
        $map = require __DIR__ . '/autoload_namespaces.php';
        foreach ($map as $namespace => $path) {
            $loader->set($namespace, $path);
        }


PSR0;
        }

        if ($useClassMap) {
            $file .= <<<'CLASSMAP'
        $classMap = require __DIR__ . '/autoload_classmap.php';
        if ($classMap) {
            $loader->addClassMap($classMap);
        }


CLASSMAP;
        }

        if ($useGlobalIncludePath) {
            $file .= <<<'INCLUDEPATH'
        $loader->setUseIncludePath(true);

INCLUDEPATH;
        }

        if ($targetDirLoader) {
            $file .= <<<REGISTER_AUTOLOAD
        spl_autoload_register(array('ComposerAutoloaderInit$suffix', 'autoload'), true, true);


REGISTER_AUTOLOAD;

        }

        $file .= <<<REGISTER_LOADER
        \$loader->register(true);


REGISTER_LOADER;

        if ($useIncludeFiles) {
            $file .= <<<'INCLUDE_FILES'
        $includeFiles = require __DIR__ . '/autoload_files.php';
        foreach ($includeFiles as $file) {
            require $file;
        }


INCLUDE_FILES;

        }

        $file .= <<<METHOD_FOOTER
        return \$loader;
    }

METHOD_FOOTER;

        $file .= $targetDirLoader;

        return $file . <<<FOOTER
}

FOOTER;

    }

    protected function parseAutoloadsType(array $packageMap, $type, PackageInterface $mainPackage)
    {
        $autoloads = array();

        foreach ($packageMap as $item) {
            list($package, $installPath) = $item;

            $autoload = $package->getAutoload();

            // skip misconfigured packages
            if (!isset($autoload[$type]) || !is_array($autoload[$type])) {
                continue;
            }
            if (null !== $package->getTargetDir() && $package !== $mainPackage) {
                $installPath = substr($installPath, 0, -strlen('/'.$package->getTargetDir()));
            }

            foreach ($autoload[$type] as $namespace => $paths) {
                foreach ((array) $paths as $path) {
                    // remove target-dir from file paths of the root package
                    if ($type === 'files' && $package === $mainPackage && $package->getTargetDir() && !is_readable($installPath.'/'.$path)) {
                        $targetDir = str_replace('\\<dirsep\\>', '[\\\\/]', preg_quote(str_replace(array('/', '\\'), '<dirsep>', $package->getTargetDir())));
                        $path = ltrim(preg_replace('{^'.$targetDir.'}', '', ltrim($path, '\\/')), '\\/');
                    }

                    // add target-dir from file paths that don't have it
                    if ($type === 'files' && $package !== $mainPackage && $package->getTargetDir() && !is_readable($installPath.'/'.$path)) {
                        $path = $package->getTargetDir() . '/' . $path;
                    }

                    // remove target-dir from classmap entries of the root package
                    if ($type === 'classmap' && $package === $mainPackage && $package->getTargetDir() && !is_readable($installPath.'/'.$path)) {
                        $targetDir = str_replace('\\<dirsep\\>', '[\\\\/]', preg_quote(str_replace(array('/', '\\'), '<dirsep>', $package->getTargetDir())));
                        $path = ltrim(preg_replace('{^'.$targetDir.'}', '', ltrim($path, '\\/')), '\\/');
                    }

                    // add target-dir to classmap entries that don't have it
                    if ($type === 'classmap' && $package !== $mainPackage && $package->getTargetDir() && !is_readable($installPath.'/'.$path)) {
                        $path = $package->getTargetDir() . '/' . $path;
                    }

                    if (empty($installPath)) {
                        $autoloads[$namespace][] = empty($path) ? '.' : $path;
                    } else {
                        $autoloads[$namespace][] = $installPath.'/'.$path;
                    }
                }
            }
        }

        return $autoloads;
    }

    protected function sortPackageMap(array $packageMap)
    {
        $positions = array();
        $names = array();
        $indexes = array();

        foreach ($packageMap as $position => $item) {
            $mainName = $item[0]->getName();
            $names = array_merge(array_fill_keys($item[0]->getNames(), $mainName), $names);
            $names[$mainName] = $mainName;
            $indexes[$mainName] = $positions[$mainName] = $position;
        }

        foreach ($packageMap as $item) {
            $position = $positions[$item[0]->getName()];
            foreach (array_merge($item[0]->getRequires(), $item[0]->getDevRequires()) as $link) {
                $target = $link->getTarget();
                if (!isset($names[$target])) {
                    continue;
                }

                $target = $names[$target];
                if ($positions[$target] <= $position) {
                    continue;
                }

                foreach ($positions as $key => $value) {
                    if ($value >= $position) {
                        break;
                    }
                    $positions[$key]--;
                }

                $positions[$target] = $position - 1;
            }
            asort($positions);
        }

        $sortedPackageMap = array();
        foreach (array_keys($positions) as $packageName) {
            $sortedPackageMap[] = $packageMap[$indexes[$packageName]];
        }

        return $sortedPackageMap;
    }
}
