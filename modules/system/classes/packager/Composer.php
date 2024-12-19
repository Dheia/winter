<?php

namespace System\Classes\Packager;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use System\Classes\Packager\Commands\InfoCommand;
use System\Classes\Packager\Commands\RemoveCommand;
use System\Classes\Packager\Commands\SearchCommand;
use System\Classes\Packager\Commands\ShowCommand;
use System\Classes\Packager\Commands\UpdateCommand;
use System\Classes\Packager\Commands\RequireCommand;
use Winter\Packager\Composer as PackagerComposer;
use Winter\Storm\Support\Facades\File;
use Winter\Storm\Support\Str;

/**
 * @class Composer
 * @method static i(): array
 * @method static install(): array
 * @method static search(string $query, ?string $type = null, bool $onlyNames = false, bool $onlyVendors = false): \Winter\Packager\Commands\Search
 * @method static info(?string $package = null): array
 * @method static show(?string $mode = 'installed', string $package = null, bool $noDev = false, bool $path = false): object
 * @method static update(bool $includeDev = true, bool $lockFileOnly = false, bool $ignorePlatformReqs = false, string $installPreference = 'none', bool $ignoreScripts = false, bool $dryRun = false, ?string $package = null): \Winter\Packager\Commands\Update
 * @method static remove(?string $package = null, bool $dryRun = false): array
 * @method static require(?string $package = null, bool $dryRun = false, bool $dev = false): string
 * @method static version(string $detail = 'version'): array<string, string>|string
 */
class Composer
{
    public const COMPOSER_CACHE_KEY = 'winter.system.composer';

    protected static PackagerComposer $composer;

    protected static array $winterPackages;

    public static function make(bool $fresh = false): PackagerComposer
    {
        if (!$fresh && isset(static::$composer)) {
            return static::$composer;
        }

        static::$composer = new PackagerComposer();
        static::$composer->setWorkDir(base_path());

        static::$composer->setCommand('remove', new RemoveCommand(static::$composer));
        static::$composer->setCommand('require', new RequireCommand(static::$composer));
        static::$composer->setCommand('search', new SearchCommand(static::$composer));
        static::$composer->setCommand('show', new ShowCommand(static::$composer));
        static::$composer->setCommand('info', new InfoCommand(static::$composer));
        static::$composer->setCommand('update', new UpdateCommand(static::$composer));

        return static::$composer;
    }

    public static function __callStatic(string $name, array $args = []): mixed
    {
        if (!isset(static::$composer)) {
            static::make();
        }

        return static::$composer->{$name}(...$args);
    }

    public static function getWinterPackages(): array
    {
        $key = static::COMPOSER_CACHE_KEY . File::lastModified(base_path('composer.lock'));
        return static::$winterPackages = Cache::rememberForever($key, function () {
            $installed = static::info();
            $packages = [];
            foreach ($installed as $package) {
                $details = static::info($package['name']);

                $type = match ($details['type']) {
                    'winter-plugin', 'october-plugin' => 'plugins',
                    'winter-module', 'october-module' => 'modules',
                    'winter-theme', 'october-theme' => 'themes',
                    default => null
                };

                if (!$type) {
                    continue;
                }

                $packages[$type][$details['path']] = $details;
            }

            return $packages;
        });
    }

    public static function getAvailableUpdates(): array
    {
        $upgrades = Cache::remember(
            static::COMPOSER_CACHE_KEY . '.updates',
            60 * 5,
            fn () => static::update(dryRun: true)->getUpgraded()
        );

        $packages = static::getWinterPackageNames();

        return array_filter($upgrades, function ($key) use ($packages) {
            return in_array($key, $packages);
        }, ARRAY_FILTER_USE_KEY);
    }

    public static function updateAvailable(string $package): bool
    {
        return isset(static::getAvailableUpdates()[$package]);
    }

    public static function getPackageInfoByPath(string $path): array
    {
        return array_merge(...array_values(static::getWinterPackages()))[$path] ?? [];
    }

    public static function getWinterPackageNames(): array
    {
        return array_values(
            array_map(
                fn ($package) => $package['name'],
                array_merge(...array_values(static::getWinterPackages()))
            )
        );
    }
}
