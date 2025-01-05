<?php

namespace System\Classes\Extensions;

use Carbon\Carbon;
use Illuminate\Console\View\Components\Error;
use Illuminate\Console\View\Components\Info;
use Illuminate\Console\View\Components\Task;
use Illuminate\Support\Facades\File;
use stdClass;
use System\Classes\VersionYamlProcessor;
use Winter\Storm\Database\Updater;
use Winter\Storm\Support\Facades\DB;
use Winter\Storm\Support\Facades\Yaml;

/**
 * Version manager
 *
 * Manages the versions and database updates for plugins.
 *
 * @package winter\wn-system-module
 * @author Alexey Bobkov, Samuel Georges
 */
class PluginVersionManager
{
    /**
     * Value when no updates are found.
     */
    public const NO_VERSION_VALUE = '0';

    /**
     * Morph types for history table.
     */
    public const HISTORY_TYPE_COMMENT = 'comment';
    public const HISTORY_TYPE_SCRIPT = 'script';

    /**
     * Cache of plugin versions as files.
     */
    protected $fileVersions;

    /**
     * Cache of database versions
     */
    protected $databaseVersions;

    /**
     * Cache of database history
     */
    protected $databaseHistory;

    protected Updater $updater;

    protected PluginManager $pluginManager;

    public function __construct(PluginManager $pluginManager)
    {
        $this->pluginManager = $pluginManager;
        $this->updater = new Updater();
    }

    /**
     * Updates a single plugin by its code or object with it's latest changes.
     * If the $stopAfterVersion parameter is specified, the process stops after
     * the specified version is applied.
     */
    public function updatePlugin($plugin, $stopAfterVersion = null): ?bool
    {
        $code = is_string($plugin) ? $plugin : $this->pluginManager->getIdentifier($plugin);

        if (!$this->hasVersionFile($code)) {
            return false;
        }

        $currentVersion = $this->getLatestFileVersion($code);
        $databaseVersion = $this->getDatabaseVersion($code);

        // No updates needed
        if ($currentVersion === (string) $databaseVersion) {
            $this->pluginManager->renderComponent(Info::class, 'Nothing to migrate.');
            return null;
        }

        $newUpdates = $this->getNewFileVersions($code, $databaseVersion);

        $this->pluginManager->renderComponent(Info::class, 'Running migrations.');

        foreach ($newUpdates as $version => $details) {
            $this->applyPluginUpdate($code, $version, $details);

            if ($stopAfterVersion === $version) {
                return true;
            }
        }

        // @TODO: do better
        $this->pluginManager->getOutput()->writeln('');

        return true;
    }

    /**
     * Update the current replaced plugin's version to reference the replacing plugin.
     */
    public function replacePlugin(PluginBase $plugin, string $replace): void
    {
        $currentVersion = $this->getDatabaseVersion($replace);
        if ($currentVersion === static::NO_VERSION_VALUE) {
            return;
        }

        // We only care about the database version of the replaced plugin at this point
        if (!$plugin->canReplacePlugin($replace, $currentVersion)) {
            return;
        }

        $code = $plugin->getPluginIdentifier();

        // Replace existing migration information with the new identifier
        if ($versions = $this->getOldFileVersions($code, $currentVersion)) {
            foreach ($versions as $version => $details) {
                list($comments, $scripts) = $this->extractScriptsAndComments($details);
                $now = now()->toDateTimeString();

                foreach ($scripts as $script) {
                    DB::table('system_plugin_history')->insert([
                        'code'       => $code,
                        'type'       => self::HISTORY_TYPE_SCRIPT,
                        'version'    => $version,
                        'detail'     => $script,
                        'created_at' => $now,
                    ]);
                }

                foreach ($comments as $comment) {
                    $this->applyDatabaseComment($code, $version, $comment);
                }
            }

            // delete replaced plugin history
            DB::table('system_plugin_history')->where('code', $replace)->delete();

            // replace installed version
            DB::table('system_plugin_versions')
                ->where('code', '=', $replace)
                ->update([
                    'code' => $code
                ]);
        }
    }

    /**
     * Returns a list of unapplied plugin versions.
     */
    public function listNewVersions($plugin)
    {
        $code = is_string($plugin) ? $plugin : $this->pluginManager->getIdentifier($plugin);

        if (!$this->hasVersionFile($code)) {
            return [];
        }

        $databaseVersion = $this->getDatabaseVersion($code);
        return $this->getNewFileVersions($code, $databaseVersion);
    }

    /**
     * Applies a single version update to a plugin.
     */
    protected function applyPluginUpdate($code, $version, $details)
    {
        list($comments, $scripts) = $this->extractScriptsAndComments($details);

        $updateFn = function () use ($code, $version, $comments, $scripts) {
            /*
            * Apply scripts, if any
            */
            foreach ($scripts as $script) {
                if ($this->hasDatabaseHistory($code, $version, $script)) {
                    continue;
                }

                $this->applyDatabaseScript($code, $version, $script);
            }

            /*
            * Register the comment and update the version
            */
            if (!$this->hasDatabaseHistory($code, $version)) {
                foreach ($comments as $comment) {
                    $this->applyDatabaseComment($code, $version, $comment);
                }
            }

            $this->setDatabaseVersion($code, $version);
        };

        $this->pluginManager->renderComponent(
            Task::class,
            sprintf(
                '<info>%s</info>%s',
                str_pad($version . ':', 10),
                (strlen($comments[0]) > 120) ? substr($comments[0], 0, 120) . '...' : $comments[0]
            ),
            $updateFn
        );
    }

    /**
     * Removes and packs down a plugin from the system. Files are left intact.
     * If the $stopOnVersion parameter is specified, the process stops after
     * the specified version is rolled back.
     *
     * @param mixed $plugin Either the identifier of a plugin as a string, or a Plugin class.
     * @param string $stopOnVersion
     * @param bool $stopCurrentVersion
     * @return bool
     */
    public function removePlugin($plugin, $stopOnVersion = null, $stopCurrentVersion = false)
    {
        $code = is_string($plugin) ? $plugin : $this->pluginManager->getIdentifier($plugin);

        if (!$this->hasVersionFile($code)) {
            return false;
        }

        $pluginHistory = $this->getDatabaseHistory($code);
        $pluginHistory = array_reverse($pluginHistory);

        $stopOnNextVersion = false;
        $newPluginVersion = null;

        try {
            foreach ($pluginHistory as $history) {
                if ($stopCurrentVersion && $stopOnVersion === $history->version) {
                    $newPluginVersion = $history->version;
                    break;
                }

                if ($stopOnNextVersion && $history->version !== $stopOnVersion) {
                    // Stop if the $stopOnVersion value was found and
                    // this is a new version. The history could contain
                    // multiple items for a single version (comments and scripts).
                    $newPluginVersion = $history->version;
                    break;
                }

                if ($history->type == self::HISTORY_TYPE_COMMENT) {
                    $this->removeDatabaseComment($code, $history->version);
                } elseif ($history->type == self::HISTORY_TYPE_SCRIPT) {
                    $this->removeDatabaseScript($code, $history->version, $history->detail);
                }

                if ($stopOnVersion === $history->version) {
                    $stopOnNextVersion = true;
                }
            }
        } catch (\Exception $exception) {
            $lastHistory = $this->getLastHistory($code);
            if ($lastHistory) {
                $this->setDatabaseVersion($code, $lastHistory->version);
            }
            throw $exception;
        }

        $this->setDatabaseVersion($code, $newPluginVersion);

        if (isset($this->fileVersions[$code])) {
            unset($this->fileVersions[$code]);
        }
        if (isset($this->databaseVersions[$code])) {
            unset($this->databaseVersions[$code]);
        }
        if (isset($this->databaseHistory[$code])) {
            unset($this->databaseHistory[$code]);
        }
        return true;
    }

    /**
     * Deletes all records from the version and history tables for a plugin.
     * @param string $pluginCode Plugin code
     * @return bool
     */
    public function purgePlugin(string $pluginCode): bool
    {
        $versions = DB::table('system_plugin_versions')->where('code', $pluginCode);
        if ($countVersions = $versions->count()) {
            $versions->delete();
        }

        $history = DB::table('system_plugin_history')->where('code', $pluginCode);
        if ($countHistory = $history->count()) {
            $history->delete();
        }

        return ($countHistory + $countVersions) > 0;
    }

    //
    // File representation
    //

    /**
     * Returns the latest version of a plugin from its version file.
     */
    protected function getLatestFileVersion($code)
    {
        $versionInfo = $this->getFileVersions($code);
        if (!$versionInfo) {
            return self::NO_VERSION_VALUE;
        }

        return trim(key(array_slice($versionInfo, -1, 1)));
    }

    /**
     * Returns older versions up to a supplied version, ie. applied versions.
     */
    protected function getOldFileVersions($code, $version = null)
    {
        if ($version === null) {
            $version = self::NO_VERSION_VALUE;
        }

        $versions = $this->getFileVersions($code);
        $maxVersions = 0;
        foreach ($versions as $v => $details) {
            if (version_compare($v, $version, '<=')) {
                $maxVersions++;
            }
        }

        return array_slice($versions, 0, $maxVersions);
    }

    /**
     * Returns any new versions from a supplied version, ie. unapplied versions.
     */
    protected function getNewFileVersions($code, $version = null)
    {
        if ($version === null) {
            $version = self::NO_VERSION_VALUE;
        }

        $versions = $this->getFileVersions($code);
        $position = array_search($version, array_keys($versions), true);

        if ($position === false) {
            return $versions;
        }

        return array_slice($versions, ++$position);
    }

    /**
     * Returns all versions of a plugin from its version file.
     */
    protected function getFileVersions($code)
    {
        if ($this->fileVersions !== null && array_key_exists($code, $this->fileVersions)) {
            return $this->fileVersions[$code];
        }

        $versionFile = $this->getVersionFile($code);
        $versionInfo = Yaml::withProcessor(new VersionYamlProcessor, function ($yaml) use ($versionFile) {
            return $yaml->parseFile($versionFile);
        });

        if (!is_array($versionInfo)) {
            $versionInfo = [];
        }

        $normalizedVersions = [];
        foreach ($versionInfo as $version => $info) {
            $normalizedVersions[$this->normalizeVersion($version)] = $info;
        }

        if ($normalizedVersions) {
            uksort($normalizedVersions, function ($a, $b) {
                return version_compare($a, $b);
            });
        }

        return $this->fileVersions[$code] = $normalizedVersions;
    }

    /**
     * Normalize a version identifier by removing the optional 'v' prefix
     */
    protected function normalizeVersion(string $version): string
    {
        return ltrim($version, 'v');
    }

    /**
     * Returns the absolute path to a version file for a plugin.
     */
    protected function getVersionFile($code)
    {
        $versionFile = $this->pluginManager->getPluginPath($code) . '/updates/version.yaml';
        return $versionFile;
    }

    /**
     * Checks if a plugin has a version file.
     */
    protected function hasVersionFile($code)
    {
        $versionFile = $this->getVersionFile($code);
        return File::isFile($versionFile);
    }

    //
    // Database representation
    //

    /**
     * Returns the latest version of a plugin from the database.
     */
    protected function getDatabaseVersion($code)
    {
        if ($this->databaseVersions === null) {
            $this->databaseVersions = DB::table('system_plugin_versions')->lists('version', 'code');
        }

        if (!isset($this->databaseVersions[$code])) {
            $this->databaseVersions[$code] = DB::table('system_plugin_versions')
                ->where('code', $code)
                ->value('version');
        }

        return $this->normalizeVersion((string) ($this->databaseVersions[$code] ?? self::NO_VERSION_VALUE));
    }

    /**
     * Updates a plugin version in the database.
     */
    protected function setDatabaseVersion($code, $version = null)
    {
        $currentVersion = $this->getDatabaseVersion($code);

        if ($version && !$currentVersion) {
            DB::table('system_plugin_versions')->insert([
                'code'       => $code,
                'version'    => $version,
                'created_at' => new Carbon
            ]);
        } elseif ($version && $currentVersion) {
            DB::table('system_plugin_versions')->where('code', $code)->update([
                'version'    => $version,
                'created_at' => new Carbon
            ]);
        } elseif ($currentVersion) {
            DB::table('system_plugin_versions')->where('code', $code)->delete();
        }

        $this->databaseVersions[$code] = $version;
    }

    /**
     * Registers a database update comment in the history table.
     */
    protected function applyDatabaseComment($code, $version, $comment)
    {
        DB::table('system_plugin_history')->insert([
            'code'       => $code,
            'type'       => self::HISTORY_TYPE_COMMENT,
            'version'    => $version,
            'detail'     => $comment,
            'created_at' => new Carbon
        ]);
    }

    /**
     * Removes a database update comment in the history table.
     */
    protected function removeDatabaseComment($code, $version)
    {
        DB::table('system_plugin_history')
            ->where('code', $code)
            ->where('type', self::HISTORY_TYPE_COMMENT)
            ->where('version', $version)
            ->delete();
    }

    /**
     * Registers a database update script in the history table.
     */
    protected function applyDatabaseScript($code, $version, $script)
    {
        /*
         * Execute the database PHP script
         */
        $updateFile = $this->pluginManager->getPluginPath($code) . '/updates/' . $script;

        if (!File::isFile($updateFile)) {
            $this->pluginManager->renderComponent(Error::class, sprintf('Migration file "%s" not found.', $script));
            return;
        }

        $this->updater->setUp($updateFile);

        DB::table('system_plugin_history')->insert([
            'code'       => $code,
            'type'       => self::HISTORY_TYPE_SCRIPT,
            'version'    => $version,
            'detail'     => $script,
            'created_at' => new Carbon
        ]);
    }

    /**
     * Removes a database update script in the history table.
     */
    protected function removeDatabaseScript($code, $version, $script)
    {
        /*
         * Execute the database PHP script
         */
        $updateFile = $this->pluginManager->getPluginPath($code) . '/updates/' . $script;

        $this->updater->packDown($updateFile);

        DB::table('system_plugin_history')
            ->where('code', $code)
            ->where('type', self::HISTORY_TYPE_SCRIPT)
            ->where('version', $version)
            ->where('detail', $script)
            ->delete();
    }

    /**
     * Returns all the update history for a plugin.
     */
    public function getDatabaseHistory($code)
    {
        if ($this->databaseHistory !== null && array_key_exists($code, $this->databaseHistory)) {
            return $this->databaseHistory[$code];
        }

        $historyInfo = DB::table('system_plugin_history')
            ->where('code', $code)
            ->orderBy('id')
            ->get()
            ->all();

        return $this->databaseHistory[$code] = $historyInfo;
    }

    /**
     * Returns the last update history for a plugin.
     *
     * @param string $code The plugin identifier
     * @return stdClass|null
     */
    protected function getLastHistory($code)
    {
        return DB::table('system_plugin_history')
            ->where('code', $code)
            ->orderBy('id', 'DESC')
            ->first();
    }

    /**
     * Checks if a plugin has an applied update version.
     */
    protected function hasDatabaseHistory($code, $version, $script = null)
    {
        $historyInfo = $this->getDatabaseHistory($code);
        if (!$historyInfo) {
            return false;
        }

        foreach ($historyInfo as $history) {
            if ($history->version != $version) {
                continue;
            }

            if ($history->type == self::HISTORY_TYPE_COMMENT && !$script) {
                return true;
            }

            if ($history->type == self::HISTORY_TYPE_SCRIPT && $history->detail == $script) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract script and comments from version details
     * @return array
     */
    protected function extractScriptsAndComments($details): array
    {
        if (is_array($details)) {
            $fileNamePattern = "/^[a-z0-9\_\-\.\/\\\]+\.php$/i";

            $comments = array_values(array_filter($details, function ($detail) use ($fileNamePattern) {
                return !preg_match($fileNamePattern, $detail);
            }));

            $scripts = array_values(array_filter($details, function ($detail) use ($fileNamePattern) {
                return preg_match($fileNamePattern, $detail);
            }));
        }
        else {
            $comments = (array)$details;
            $scripts = [];
        }

        return [$comments, $scripts];
    }

    /**
     * Get the currently installed version of the plugin.
     *
     * @param string|PluginBase $plugin Either the identifier of a plugin as a string, or a Plugin class.
     * @return string
     */
    public function getCurrentVersion($plugin): string
    {
        $code = $this->pluginManager->getIdentifier($plugin);
        return $this->getDatabaseVersion($code);
    }

    /**
     * Check if a certain version of the plugin exists in the plugin history database.
     *
     * @param string|PluginBase $plugin Either the identifier of a plugin as a string, or a Plugin class.
     * @param string $version
     * @return bool
     */
    public function hasDatabaseVersion($plugin, string $version): bool
    {
        $code = $this->pluginManager->getIdentifier($plugin);
        $histories = $this->getDatabaseHistory($code);
        foreach ($histories as $history) {
            if ($history->version === $version) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get last version note
     *
     * @param string|PluginBase $plugin
     * @return string
     */
    public function getCurrentVersionNote($plugin): string
    {
        $code = $this->pluginManager->getIdentifier($plugin);
        $histories = $this->getDatabaseHistory($code);
        $lastHistory = array_last(array_where($histories, function ($history) {
            return $history->type === self::HISTORY_TYPE_COMMENT;
        }));
        return $lastHistory ? $lastHistory->detail : '';
    }
}