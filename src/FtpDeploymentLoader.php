<?php

// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 Jan Kuželka

declare(strict_types=1);

namespace RemoteIgnore;

/**
 * Loads dg/ftp-deployment either from Composer or from a nearby source tree.
 * The fallback keeps the extension usable with older standalone installations.
 */
final class FtpDeploymentLoader
{
    public static function load(string $baseDir): bool
    {
        if (class_exists('Deployment\\CliRunner')) {
            return true;
        }

        foreach (self::autoloadCandidates($baseDir) as $autoload) {
            if (is_file($autoload)) {
                require_once $autoload;
                if (class_exists('Deployment\\CliRunner')) {
                    return true;
                }
            }
        }

        foreach (self::sourceCandidates($baseDir) as $sourceFile) {
            if (is_file($sourceFile) && self::loadFromSourceEntryPoint($sourceFile)) {
                return true;
            }
        }

        return class_exists('Deployment\\CliRunner');
    }

    /** @return string[] */
    private static function autoloadCandidates(string $baseDir): array
    {
        return [
            // When this package is installed under vendor/<vendor>/<package> and
            // the binary is invoked directly instead of through Composer's proxy.
            dirname($baseDir, 2) . '/autoload.php',

            // Standalone checkout / legacy layouts used by the original utility.
            $baseDir . '/vendor/autoload.php',
            $baseDir . '/vendor/dg/ftp-deployment/vendor/autoload.php',
            $baseDir . '/vendor/ftp-deployment/vendor/autoload.php',
            $baseDir . '/vendor/deployment/vendor/autoload.php',
            $baseDir . '/dg/ftp-deployment/vendor/autoload.php',
            $baseDir . '/ftp-deployment/vendor/autoload.php',
            $baseDir . '/deployment/vendor/autoload.php',
        ];
    }

    /** @return string[] */
    private static function sourceCandidates(string $baseDir): array
    {
        return [
            $baseDir . '/vendor/dg/ftp-deployment/src/deployment.php',
            $baseDir . '/vendor/ftp-deployment/src/deployment.php',
            $baseDir . '/vendor/deployment/src/deployment.php',
            $baseDir . '/dg/ftp-deployment/src/deployment.php',
            $baseDir . '/ftp-deployment/src/deployment.php',
            $baseDir . '/deployment/src/deployment.php',
        ];
    }

    private static function loadFromSourceEntryPoint(string $sourceFile): bool
    {
        // Older 3.x releases explicitly require class files from src/deployment.php.
        // Parse those requires instead of executing the entry point, because doing
        // so would immediately start the upstream CLI runner.
        $content = file_get_contents($sourceFile);
        if ($content === false) {
            return false;
        }

        $sourceDir = dirname($sourceFile);

        if (preg_match_all("~require\\s+__DIR__\\s*\\.\\s*['\"]([^'\"]+)['\"]\\s*;~", $content, $matches)) {
            foreach ($matches[1] as $relativePath) {
                $path = $sourceDir . '/' . ltrim($relativePath, '/\\');
                if (is_file($path)) {
                    require_once $path;
                }
            }
        }

        if (class_exists('Deployment\\CliRunner')) {
            return true;
        }

        // Newer releases rely on Composer classmap autoloading. If only the source
        // tree is available, register the equivalent namespace-to-file mapping.
        $deploymentDir = $sourceDir . '/Deployment';
        if (is_dir($deploymentDir)) {
            spl_autoload_register(function ($class) use ($deploymentDir) {
                $prefix = 'Deployment\\';
                if (strpos($class, $prefix) !== 0) {
                    return;
                }

                $relative = substr($class, strlen($prefix));
                $path = $deploymentDir . '/' . str_replace('\\', '/', $relative) . '.php';
                if (is_file($path)) {
                    require_once $path;
                }
            });
        }

        return class_exists('Deployment\\CliRunner');
    }
}
