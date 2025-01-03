<?php

namespace System\Classes\Extensions\Source;

use Cms\Classes\ThemeManager;
use Illuminate\Console\View\Components\Error;
use Illuminate\Console\View\Components\Info;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;
use System\Classes\Core\MarketPlaceApi;
use System\Classes\Extensions\ExtensionManager;
use System\Classes\Extensions\ExtensionManagerInterface;
use System\Classes\Extensions\ModuleManager;
use System\Classes\Extensions\PluginManager;
use Winter\Storm\Foundation\Extension\WinterExtension;
use Winter\Storm\Packager\Composer;
use Winter\Packager\Exceptions\CommandException;
use Winter\Storm\Exception\ApplicationException;
use Winter\Storm\Support\Str;

class ExtensionSource
{
    public const SOURCE_COMPOSER = 'composer';
    public const SOURCE_MARKET = 'market';
    public const SOURCE_LOCAL = 'local';

    public const TYPE_PLUGIN = 'plugin';
    public const TYPE_THEME = 'theme';
    public const TYPE_MODULE = 'module';

    public const STATUS_UNINSTALLED = 'uninstalled';
    public const STATUS_INSTALLED = 'installed';
    public const STATUS_UNPACKED = 'unpacked';

    protected array $extensionManagerMapping = [
        self::TYPE_PLUGIN => PluginManager::class,
        self::TYPE_THEME => ThemeManager::class,
        self::TYPE_MODULE => ModuleManager::class,
    ];

    protected string $status = 'uninstalled';

    public function __construct(
        public string $source,
        public string $type,
        public ?string $code = null,
        public ?string $composerPackage = null,
        public ?string $path = null
    ) {
        if (!in_array($this->source, [static::SOURCE_COMPOSER, static::SOURCE_MARKET, static::SOURCE_LOCAL])) {
            throw new \InvalidArgumentException("Invalid source '{$this->source}'");
        }

        if (!in_array($this->type, [static::TYPE_PLUGIN, static::TYPE_THEME, static::TYPE_MODULE])) {
            throw new \InvalidArgumentException("Invalid type '{$this->type}'");
        }

        if ($this->source === static::SOURCE_COMPOSER && !$this->composerPackage) {
            throw new ApplicationException('You must provide a composer package for a composer source.');
        }

        if ($this->source !== static::SOURCE_COMPOSER && !$this->code) {
            if (!$this->path) {
                throw new ApplicationException('You must provide a code or path.');
            }

            $this->code = $this->guessCodeFromPath($this->path);
        }

        $this->status = $this->checkStatus();
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getCode(): ?string
    {
        if ($this->code) {
            return $this->code;
        }

        if (!$this->path) {
            return null;
        }

        return $this->code = $this->guessCodeFromPath($this->path);
    }

    public function getPath(): ?string
    {
        if ($this->path) {
            return $this->path;
        }

        if (!$this->code) {
            return null;
        }

        return $this->path = $this->guessPathFromCode($this->code);
    }

    /**
     * @throws ApplicationException
     */
    public function createFiles(): ?static
    {
        $manager = $this->getExtensionManager();

        switch ($this->source) {
            case static::SOURCE_COMPOSER:
                $manager->renderComponent(
                    Info::class,
                    'Requiring composer package: <fg=yellow>' . $this->composerPackage . '</>'
                );

                try {
                    Composer::require($this->composerPackage);
                } catch (CommandException $e) {
                    $manager->renderComponent(
                        Error::class,
                        'Unable to require composer package, details: <fg=yellow>' . $e->getMessage() . '</>'
                    );
                    return null;
                }

                $manager->renderComponent(Info::class, 'Composer require complete.');

                $info = Composer::show('installed', $this->composerPackage);
                $this->path = $this->relativePath($info['path']);
                $this->source = static::SOURCE_LOCAL;
                break;
            case static::SOURCE_MARKET:
                if (!in_array($this->type, [static::TYPE_PLUGIN, static::TYPE_THEME])) {
                    throw new ApplicationException("The market place only supports themes and plugins '{$this->type}'");
                }

                $manager->renderComponent(Info::class, 'Downloading ' . $this->type . ' details...');

                try {
                    $extensionDetails = MarketPlaceApi::instance()->request(match ($this->type) {
                        static::TYPE_THEME => MarketPlaceApi::REQUEST_THEME_DETAIL,
                        static::TYPE_PLUGIN => MarketPlaceApi::REQUEST_PLUGIN_DETAIL,
                    }, $this->code);
                } catch (\Throwable $e) {
                    $manager->renderComponent(
                        Error::class,
                        'Unable to download ' . $this->type . ' details: <fg=yellow>' . $e->getMessage() . '</>'
                    );
                    return null;
                }

                $manager->renderComponent(Info::class, 'Downloading ' . $this->type . '...');
                MarketPlaceApi::instance()->{'download' . ucfirst($this->type)}(
                    $extensionDetails['code'],
                    $extensionDetails['hash']
                );

                $manager->renderComponent(Info::class, 'Extracting ' . $this->type . '...');
                MarketPlaceApi::instance()->{'extract' . ucfirst($this->type)}(
                    $extensionDetails['code'],
                    $extensionDetails['hash']
                );

                $this->path = $this->guessPathFromCode($this->code);
                $this->source = static::SOURCE_LOCAL;

                break;
            case static::SOURCE_LOCAL:
                $extensionPath = $this->guessPathFromCode($this->code);
                if ($this->path !== $extensionPath) {
                    $manager->renderComponent(
                        Info::class,
                        'Moving ' . $this->type . ' to path <fg=yellow>' . $extensionPath . '</>...'
                    );

                    File::moveDirectory($this->path, $extensionPath);
                    $this->path = $extensionPath;
                }
                break;
        }

        if ($this->status !== static::STATUS_INSTALLED) {
            $this->status = static::STATUS_UNPACKED;
        }

        return $this;
    }

    /**
     * @throws ApplicationException
     */
    public function install(): ?WinterExtension
    {
        if ($this->status === static::STATUS_UNINSTALLED && !$this->createFiles()) {
            return null;
        }

        if ($this->status === static::STATUS_INSTALLED) {
            return $this->getExtensionManager()->get($this);
        }

        return $this->getExtensionManager()->install($this);
    }

    /**
     * @throws ApplicationException
     */
    public function uninstall(): bool
    {
        if ($this->status !== static::STATUS_INSTALLED) {
            throw new ApplicationException('Extension source is not installed');
        }

        return $this->getExtensionManager()->uninstall(
            $this->getExtensionManager()->get($this)
        );
    }

    protected function getExtensionManager(): ExtensionManager
    {
        return App::make($this->extensionManagerMapping[$this->type]);
    }

    protected function checkStatus(): string
    {
        switch ($this->source) {
            case static::SOURCE_COMPOSER:
                try {
                    $info = Composer::show('installed', $this->composerPackage);
                } catch (CommandException $e) {
                    return static::STATUS_UNINSTALLED;
                }

                $this->path = $this->relativePath($info['path']);

                if (!$this->getExtensionManager()->isInstalled($this)) {
                    return static::STATUS_UNPACKED;
                }
                break;
            case static::SOURCE_MARKET:
            case static::SOURCE_LOCAL:
                // Check the path the extension "should" be installed to
                if (!File::exists($this->guessPathFromCode($this->code))) {
                    return static::STATUS_UNINSTALLED;
                }
                break;
        }

        if (!$this->getExtensionManager()->isInstalled($this)) {
            return static::STATUS_UNPACKED;
        }

        return static::STATUS_INSTALLED;
    }

    protected function guessPathFromCode(string $code): ?string
    {
        return match ($this->type) {
            static::TYPE_PLUGIN => plugins_path(str_replace('.', '/', strtolower($code))),
            static::TYPE_THEME => themes_path(strtolower($code)),
            static::TYPE_MODULE => base_path('modules/' . strtolower($code)),
            default => null,
        };
    }

    /**
     * @throws \ReflectionException
     * @throws ApplicationException
     */
    protected function guessCodeFromPath(string $path): ?string
    {
        return match ($this->type) {
            static::TYPE_PLUGIN => str_replace('/', '.', ltrim(
                str_starts_with($path, plugins_path())
                    ? Str::after($path, basename(plugins_path()))
                    : $this->guessCodeFromPlugin($path),
            '/')),
            static::TYPE_THEME, static::TYPE_MODULE => basename($path),
            default => null,
        };
    }

    /**
     * @throws \ReflectionException
     * @throws ApplicationException
     */
    protected function guessCodeFromPlugin(string $path): string
    {
        // Get all files in the provided path
        $files = array_combine(array_map('strtolower', $files = scandir($path)), $files);

        // If there is no plugin.php in any casing, then throw
        if (!isset($files['plugin.php'])) {
            throw new ApplicationException(sprintf('Unable to locate plugin file in path: "%s"', $path));
        }

        // Create a full path to the plugin.php file
        $file = $path . DIRECTORY_SEPARATOR . $files['plugin.php'];

        // The following will create a child process to parse the plugins namespace, this is done to prevent pollution
        // of the current process (i.e. namespaces being incorrectly registered to paths where they won't be in the
        // future post installation)

        // Create a named pipe
        $pipeName = 'classTestPipe';
        posix_mkfifo($pipeName, 0777);

        // Fork the process
        if (!pcntl_fork()) {
            // Open the pipe now, so we can signal failure by closing it in case of a parse error
            $pipe = fopen($pipeName, 'w');
            try {
                require $file;
            } catch (\Throwable $e) {
                // Probably a parse error, close the pipe to tell the parent that something went wrong
                fclose($pipe);
                exit(1);
            }
            // Get all declared classes
            $classes = get_declared_classes();
            // Reflect on the last registered class, i.e. the one we loaded with require above
            $reflect = new \ReflectionClass(array_pop($classes));
            // Send the namespace over the pipe to the parent process
            fwrite($pipe, $reflect->getNamespaceName());
            fclose($pipe);
            exit(0);
        }

        // Read the pipe, this is blocking and will wait for the child to close the pipe handle in it's own process
        $pipe = fopen($pipeName, 'r');
        // Read the namespace from the pipe
        $namespace = fread($pipe, 4096);
        // Close and clean up
        fclose($pipe);
        unlink($pipeName);

        if (!$namespace) {
            throw new ApplicationException(sprintf('Unable to detect plugin namespace from `%s`. Is the file valid?', $file));
        }

        return PluginManager::instance()->getIdentifier($namespace);
    }

    protected function relativePath(string $path): string
    {
        return ltrim(Str::after($path, match ($this->type) {
            static::TYPE_PLUGIN, static::TYPE_THEME => base_path(),
            static::TYPE_MODULE => base_path('modules'),
        }), '/');
    }
}
