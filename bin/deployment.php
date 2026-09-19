#!/usr/bin/env php
<?php

// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 Jan Kuželka

declare(strict_types=1);

use RemoteIgnore\CliRunnerBridge;
use RemoteIgnore\FtpDeploymentLoader;
use RemoteIgnore\RemoteIgnoreExtension;

if (PHP_VERSION_ID < 70126) {
    fwrite(STDERR, "Error: ftp-deployment-remote-ignore requires PHP 7.1.26 or newer.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/src/FtpDeploymentLoader.php';
require_once $root . '/src/CliRunnerBridge.php';
require_once $root . '/src/RemoteIgnoreExtension.php';

if (!FtpDeploymentLoader::load($root)) {
    fwrite(STDERR, "Error: dg/ftp-deployment not found. Install it with Composer or place it under vendor/dg/ftp-deployment.\n");
    exit(1);
}

// Strip the extension command before delegating so the original CliRunner receives
// exactly the arguments it already understands.
$args = isset($_SERVER['argv']) ? $_SERVER['argv'] : [];
$command = null;
foreach (RemoteIgnoreExtension::commands() as $candidate) {
    $index = array_search($candidate, $args, true);
    if ($index !== false) {
        $command = $candidate;
        unset($_SERVER['argv'][$index]);
        $_SERVER['argv'] = array_values($_SERVER['argv']);
        $_SERVER['argc'] = count($_SERVER['argv']);
        break;
    }
}

try {
    if ($command !== null) {
        $extension = new RemoteIgnoreExtension(new CliRunnerBridge());
        exit($extension->run($command));
    }

    // No extension command: behave as a transparent wrapper around the upstream CLI.
    $runner = new \Deployment\CliRunner();
    exit((int) $runner->run());
} catch (\Throwable $e) {
    fwrite(STDERR, "ftp-deployment-remote-ignore failed: {$e->getMessage()}\n");
    exit(1);
}
