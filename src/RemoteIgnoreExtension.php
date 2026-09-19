<?php

// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 Jan Kuželka
// Portions adapted from dg/ftp-deployment, Copyright (c) 2009 David Grudl.

declare(strict_types=1);

namespace RemoteIgnore;

final class RemoteIgnoreExtension
{
    public const COMMAND_IGNORE = '--ignore-remote';
    public const COMMAND_UNIGNORE = '--unignore-remote';
    public const COMMAND_BACKUP = '--backup-remote-deployment-file';
    public const COMMAND_RESTORE = '--restore-remote-deployment-file';
    public const COMMAND_DELETE_BACKUP = '--delete-backup-remote-deployment-file';

    private const SUFFIX_BACKUP = '.backup';
    private const SUFFIX_IGNORED = '.ignore.remote';

    /** @var CliRunnerBridge */
    private $bridge;

    /** @var \Deployment\Logger|null */
    private $logger;

    public function __construct(CliRunnerBridge $bridge = null)
    {
        $this->bridge = $bridge ?: new CliRunnerBridge();
    }

    /** @return string[] */
    public static function commands(): array
    {
        return [
            self::COMMAND_IGNORE,
            self::COMMAND_UNIGNORE,
            self::COMMAND_BACKUP,
            self::COMMAND_RESTORE,
            self::COMMAND_DELETE_BACKUP,
        ];
    }

    public function run(string $command): int
    {
        if (!in_array($command, self::commands(), true)) {
            throw new \InvalidArgumentException('Unknown command ' . $command);
        }

        // Reuse the upstream CLI lifecycle so configuration parsing, batches and locks
        // behave exactly like a normal dg/ftp-deployment run.
        $runner = new \Deployment\CliRunner();
        $bootstrapLogger = new \Deployment\Logger('php://memory');
        $this->bridge->set($runner, 'logger', $bootstrapLogger);
        $this->bridge->call($runner, 'setupPhp');

        $config = $this->bridge->call($runner, 'loadConfig');
        if (!$config) {
            return 1;
        }

        $this->logger = new \Deployment\Logger($config['log'] . '_remote_ignore.log');
        $this->logger->useColors = (bool) $config['colors'];
        $this->logger->showProgress = (bool) $config['progress'];
        $this->bridge->set($runner, 'logger', $this->logger);

        $tempDir = $config['tempdir'];
        if (!is_dir($tempDir)) {
            $this->logger->log("Creating temporary directory $tempDir");
            if (!mkdir($tempDir, 0777, true) && !is_dir($tempDir)) {
                throw new \RuntimeException("Unable to create temporary directory $tempDir.");
            }
        }

        $started = time();
        $this->logger->log('============ ' . __CLASS__ . ' ============', 'lime');
        $this->logger->log('Started at ' . date('[Y/m/d H:i]'));
        $this->logger->log('Config file is ' . $this->bridge->get($runner, 'configFile'));

        $result = 0;
        try {
            foreach ($this->bridge->get($runner, 'batches') as $name => $batch) {
                if ($name !== '') {
                    $this->logger->log("\nProcessing $name");
                }

                $deployer = $this->createConnectedDeployer($runner, $batch, $tempDir);

                try {
                    if (!$this->executeCommand($command, $deployer, $batch)) {
                        $result = 1;
                    }
                } catch (\Deployment\JobException $e) {
                    $this->logDeploymentException($e);
                    $result = 1;
                } catch (\Deployment\ServerException $e) {
                    $this->logDeploymentException($e);
                    $result = 1;
                }

                $this->logger->log("\n");
            }
        } finally {
            $this->releaseRunnerLock($runner);
        }

        $elapsed = time() - $started;
        $this->logger->log('Finished at ' . date('[Y/m/d H:i]') . " (in $elapsed seconds)\n----------------------------------------------\n", 'lime');
        return $result;
    }

    /**
     * Build the upstream Deployer for one configured batch and connect it to the target.
     *
     * @param array<string,mixed> $batch
     */
    private function createConnectedDeployer($runner, array $batch, string $tempDir)
    {
        // createDeployer() is private upstream; keeping this access behind the bridge
        // avoids duplicating dg/ftp-deployment's configuration-to-server setup logic.
        /** @var \Deployment\Deployer $deployer */
        $deployer = $this->bridge->call($runner, 'createDeployer', [$batch]);
        $deployer->tempDir = $tempDir;

        if ($deployer->testMode) {
            $this->logger->log('Test mode', 'lime');
        } else {
            $this->logger->log('Live mode', 'aqua');
        }

        $this->logger->log('Connecting to server');
        $server = $this->bridge->get($deployer, 'server');
        $server->connect();
        $this->bridge->set($deployer, 'remoteDir', $server->getDir());

        return $deployer;
    }

    /**
     * Dispatch only the extension-specific commands; normal deployments never reach here.
     *
     * @param array<string,mixed> $batch
     */
    private function executeCommand(string $command, $deployer, array $batch): bool
    {
        switch ($command) {
            case self::COMMAND_IGNORE:
                $masks = \Deployment\CliRunner::toArray(isset($batch['ignoreremote']) ? $batch['ignoreremote'] : '');
                if (!$masks) {
                    $this->logger->log('ignoreremote section in the configuration is empty.');
                    return true;
                }
                return $this->ignoreRemote($deployer, $masks);

            case self::COMMAND_UNIGNORE:
                return $this->ignoreRemote($deployer, []);

            case self::COMMAND_BACKUP:
                $this->logger->log('Backup deployment file');
                $ok = $this->backupRemoteDeploymentFile($deployer, []);
                if ($ok) {
                    $this->logger->log('Done!');
                }
                return $ok;

            case self::COMMAND_RESTORE:
                return $this->restoreRemoteDeploymentFile($deployer);

            case self::COMMAND_DELETE_BACKUP:
                return $this->deleteBackupRemoteDeploymentFile($deployer);
        }

        return false;
    }

    /**
     * Repartition the remote deployment state into active and remote-ignored entries.
     * Passing an empty mask list performs the inverse operation (--unignore-remote).
     *
     * @param string[] $remoteIgnore
     */
    private function ignoreRemote($deployer, array $remoteIgnore): bool
    {
        $this->logger->log('Load deployment file');
        $remotePaths = $this->loadRemoteDeploymentFile($deployer);

        $this->logger->log('Backup deployment file');
        if (!$this->backupRemoteDeploymentFile($deployer, $remotePaths)) {
            return false;
        }

        // Bring previously ignored entries back into the working set before applying
        // the current masks. This makes --ignore-remote idempotent and allows masks to change.
        $this->logger->log('Load ignore.remote deployment file');
        $previouslyIgnored = $this->loadRemoteDeploymentFile($deployer, self::SUFFIX_IGNORED, false, 'irdf');
        $remotePaths = $this->mergePathEntries($remotePaths, $previouslyIgnored, false);

        $this->logger->log('Ignore remote paths');
        $processed = $this->partitionRemotePaths($deployer, $remotePaths, $remoteIgnore);

        // Ignored entries live in a sidecar state file, therefore the original deployer
        // cannot treat them as missing local files and delete or overwrite them.
        $this->logger->log('Write ignore.remote deployment file');
        if (!$this->writeRemoteDeploymentFile($deployer, $processed['ignored'], false, self::SUFFIX_IGNORED)) {
            return false;
        }

        $this->logger->log('Write deployment file');
        if (!$this->writeRemoteDeploymentFile($deployer, $processed['active'], true)) {
            return false;
        }

        $this->logger->log('Done!');
        return true;
    }

    /**
     * @param array<string,mixed> $remotePaths
     * @param string[] $remoteIgnore
     * @return array{ignored: array<string,mixed>, active: array<string,mixed>}
     */
    private function partitionRemotePaths($deployer, array $remotePaths, array $remoteIgnore): array
    {
        $ignored = [];
        $active = [];
        $counter = 0;
        $localDir = $this->bridge->get($deployer, 'localDir');

        if (!$remoteIgnore) {
            return ['ignored' => [], 'active' => $remotePaths];
        }

        // Use the upstream matcher so ignoreRemote follows the same mask semantics as
        // dg/ftp-deployment's normal ignore rules. Directory detection mirrors upstream.
        foreach ($remotePaths as $path => $value) {
            if ($path === '.' || $path === '..') {
                continue;
            }

            $isDir = is_dir($localDir . str_replace('/', DIRECTORY_SEPARATOR, $path));
            $this->logger->progress(str_pad(str_repeat('.', $counter++ % 40), 40));

            if (\Deployment\Helpers::matchMask($path, $remoteIgnore, $isDir)) {
                $this->logger->log(str_pad("Ignoring remote .$path", 40), 'gray');
                $ignored[$path] = $value;
            } else {
                $active[$path] = $value;
            }
        }

        return ['ignored' => $ignored, 'active' => $active];
    }

    /**
     * Create the safety backup once and keep it unchanged across repeated ignore runs.
     *
     * @param array<string,mixed> $remotePaths
     */
    private function backupRemoteDeploymentFile($deployer, array $remotePaths): bool
    {
        $backupExists = $this->loadRemoteDeploymentFile($deployer, self::SUFFIX_BACKUP, true, 'rdbf');
        if ($backupExists) {
            return true;
        }

        $this->logger->log('Creating remote backup to ' . $deployer->deploymentFile . self::SUFFIX_BACKUP . ' file', 'gray');
        if (!$remotePaths) {
            $remotePaths = $this->loadRemoteDeploymentFile($deployer);
        }

        return !$remotePaths || $this->writeRemoteDeploymentFile($deployer, $remotePaths, false, self::SUFFIX_BACKUP);
    }

    /** Restore the original state file and remove any remote-ignore sidecar state. */
    private function restoreRemoteDeploymentFile($deployer): bool
    {
        $this->logger->log('Load backup deployment file');
        if (!$this->loadRemoteDeploymentFile($deployer, self::SUFFIX_BACKUP, true, 'rdbf')) {
            $this->logger->log('Done!');
            return true;
        }

        $this->logger->log('Restore deployment file');
        if (!$this->renameRemoteDeploymentFile($deployer, self::SUFFIX_BACKUP, '')) {
            return false;
        }

        $this->logger->log('Load ignore.remote deployment file');
        if ($this->loadRemoteDeploymentFile($deployer, self::SUFFIX_IGNORED, true, 'irdf')) {
            $this->logger->log('Delete ignore.remote deployment file');
            if (!$this->deleteRemoteDeploymentFile($deployer, self::SUFFIX_IGNORED)) {
                return false;
            }
        }

        $this->logger->log('Done!');
        return true;
    }

    /** Remove only the state backup; deployed application files are never touched here. */
    private function deleteBackupRemoteDeploymentFile($deployer): bool
    {
        $this->logger->log('Load backup deployment file');
        if ($this->loadRemoteDeploymentFile($deployer, self::SUFFIX_BACKUP, true, 'ddbf')) {
            $this->logger->log('Delete backup deployment file');
            if (!$this->deleteRemoteDeploymentFile($deployer, self::SUFFIX_BACKUP)) {
                return false;
            }
        }
        $this->logger->log('Done!');
        return true;
    }

    /**
     * Read and decode an upstream deployment-state file from the remote server.
     * Presence-only mode avoids decoding when callers only need an existence check.
     *
     * @return array<string,mixed>|bool
     */
    private function loadRemoteDeploymentFile($deployer, string $suffix = '', bool $presenceOnly = false, string $tmpPrefix = 'rdf')
    {
        $server = $this->bridge->get($deployer, 'server');
        $remoteDir = $this->bridge->get($deployer, 'remoteDir');
        $targetFile = $deployer->deploymentFile . $suffix;
        $targetRemoteFile = $remoteDir . '/' . $targetFile;
        $tmpFile = tempnam($deployer->tempDir, $tmpPrefix);
        if ($tmpFile === false) {
            throw new \RuntimeException('Unable to create a temporary file.');
        }

        try {
            if ($server instanceof \Deployment\RetryServer) {
                $server->noRetry('readFile', $targetRemoteFile, $tmpFile);
            } else {
                $server->readFile($targetRemoteFile, $tmpFile);
            }
        } catch (\Deployment\ServerException $e) {
            @unlink($tmpFile);
            $this->logger->log("Remote $targetFile file not found or it's empty", 'gray');
            return $presenceOnly ? false : [];
        }

        if (!is_file($tmpFile) || filesize($tmpFile) < 1) {
            @unlink($tmpFile);
            $this->logger->log("Remote $targetFile file not found or it's empty", 'gray');
            return $presenceOnly ? false : [];
        }

        if ($presenceOnly) {
            @unlink($tmpFile);
            $this->logger->log("Remote $targetFile file exists", 'gray');
            return true;
        }

        $raw = file_get_contents($tmpFile);
        @unlink($tmpFile);
        if ($raw === false) {
            return [];
        }

        // ftp-deployment 3.3 writes raw DEFLATE, newer versions use gzip.
        $content = @gzinflate($raw);
        if ($content === false) {
            $content = @gzdecode($raw);
        }
        if ($content === false) {
            throw new \RuntimeException("Unable to decode remote $targetFile file.");
        }

        $paths = [];
        foreach (explode("\n", $content) as $item) {
            $parts = explode('=', $item, 2);
            if (count($parts) === 2) {
                $paths[$parts[1]] = $parts[0] === '1' ? true : $parts[0];
            }
        }

        $this->logger->log("Loaded remote $targetFile file", 'gray');
        return $paths;
    }

    /**
     * Serialize deployment-state entries in the format understood by dg/ftp-deployment.
     * An empty non-persistent state removes the corresponding sidecar file.
     *
     * @param array<string,mixed> $remotePaths
     */
    private function writeRemoteDeploymentFile($deployer, array $remotePaths, bool $writeEmpty = true, string $suffix = ''): bool
    {
        $server = $this->bridge->get($deployer, 'server');
        $remoteDir = $this->bridge->get($deployer, 'remoteDir');
        $targetFile = $deployer->deploymentFile . $suffix;
        $targetRemoteFile = $remoteDir . '/' . $targetFile;

        $this->logger->log('Writing ' . count($remotePaths) . " entries to remote $targetFile file", 'gray');

        if ($deployer->testMode) {
            $written = true;
        } else {
            $plain = '';
            foreach ($remotePaths as $path => $value) {
                $plain .= $value . '=' . $path . "\n";
            }

            $tmpFile = tempnam($deployer->tempDir, 'df');
            if ($tmpFile === false) {
                throw new \RuntimeException('Unable to create a temporary file.');
            }

            // Raw DEFLATE is intentionally kept for compatibility with older 3.x releases.
            $compressed = gzdeflate($plain, 9);
            if ($compressed === false || file_put_contents($tmpFile, $compressed) === false) {
                @unlink($tmpFile);
                throw new \RuntimeException('Unable to prepare the deployment state file.');
            }

            try {
                $server->createDir(str_replace('\\', '/', dirname($targetRemoteFile)));
                $server->writeFile($tmpFile, $targetRemoteFile);
                $written = true;
            } catch (\Deployment\ServerException $e) {
                $this->logger->log("Failed to write remote $targetFile file", 'red');
                $written = false;
            }
            @unlink($tmpFile);
        }

        if (!$remotePaths && !$writeEmpty) {
            return $written && $this->deleteRemoteDeploymentFile($deployer, $suffix);
        }

        return $written;
    }

    /** Delete one deployment-state file through the upstream server abstraction. */
    private function deleteRemoteDeploymentFile($deployer, string $suffix = ''): bool
    {
        $server = $this->bridge->get($deployer, 'server');
        $remoteDir = $this->bridge->get($deployer, 'remoteDir');
        $targetFile = $deployer->deploymentFile . $suffix;
        $targetRemoteFile = $remoteDir . '/' . $targetFile;

        if ($deployer->testMode) {
            return true;
        }

        try {
            $server->removeFile($targetRemoteFile);
            return true;
        } catch (\Deployment\ServerException $e) {
            $this->logger->log("Failed to delete remote $targetFile file", 'red');
            return false;
        }
    }

    /** Rename a deployment-state file on the remote server (used by restore). */
    private function renameRemoteDeploymentFile($deployer, string $sourceSuffix, string $targetSuffix): bool
    {
        $server = $this->bridge->get($deployer, 'server');
        $remoteDir = $this->bridge->get($deployer, 'remoteDir');
        $sourceFile = $deployer->deploymentFile . $sourceSuffix;
        $targetFile = $deployer->deploymentFile . $targetSuffix;

        if ($deployer->testMode) {
            return true;
        }

        try {
            $server->renameFile($remoteDir . '/' . $sourceFile, $remoteDir . '/' . $targetFile);
            return true;
        } catch (\Deployment\ServerException $e) {
            $this->logger->log("Failed to rename remote $sourceFile file to $targetFile file", 'red');
            return false;
        }
    }

    /**
     * @param array<string,mixed> $original
     * @param array<string,mixed> $updates
     * @return array<string,mixed>
     */
    private function mergePathEntries(array $original, array $updates, bool $overwriteExisting): array
    {
        // Active state wins by default; the sidecar only fills entries that are currently absent.
        $result = $original;
        foreach ($updates as $path => $value) {
            if ($overwriteExisting || !array_key_exists($path, $result)) {
                $result[$path] = $value;
            }
        }
        return $result;
    }

    private function releaseRunnerLock($runner): void
    {
        $lock = $this->bridge->get($runner, 'lock');
        if (is_resource($lock)) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function logDeploymentException(\Throwable $e): void
    {
        $this->logger->log("Error: {$e->getMessage()} in {$e->getFile()}:{$e->getLine()}\n\n$e", 'red');
    }
}
