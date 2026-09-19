<?php

// SPDX-License-Identifier: BSD-3-Clause
// Copyright (c) 2026 Jan Kuželka

declare(strict_types=1);

namespace RemoteIgnore;

/**
 * Small compatibility bridge for dg/ftp-deployment internals.
 *
 * The upstream CliRunner/Deployer classes keep the configuration, server,
 * logger and deployment state private. The extension needs a narrow subset
 * of that state, so reflection is isolated here instead of spreading it
 * through the remote-ignore logic.
 */
final class CliRunnerBridge
{
    /**
     * @param object $target
     * @param mixed[] $arguments
     * @return mixed
     */
    public function call($target, string $method, array $arguments = [])
    {
        $reflection = new \ReflectionClass($target);
        if (!$reflection->hasMethod($method)) {
            throw new \RuntimeException(sprintf(
                'Method %s::%s() is not available in this ftp-deployment version.',
                get_class($target),
                $method
            ));
        }

        $refMethod = $reflection->getMethod($method);
        $refMethod->setAccessible(true);
        return $refMethod->invokeArgs($target, $arguments);
    }

    /**
     * @param object $target
     * @return mixed
     */
    public function get($target, string $property)
    {
        $refProperty = $this->property($target, $property);

        if (method_exists($refProperty, 'isInitialized') && !$refProperty->isInitialized($target)) {
            return null;
        }

        return $refProperty->getValue($target);
    }

    /**
     * @param object $target
     * @param mixed $value
     */
    public function set($target, string $property, $value): void
    {
        $refProperty = $this->property($target, $property);
        $refProperty->setValue($target, $value);
    }

    /** @param object $target */
    private function property($target, string $property): \ReflectionProperty
    {
        $reflection = new \ReflectionClass($target);
        if (!$reflection->hasProperty($property)) {
            throw new \RuntimeException(sprintf(
                'Property %s::$%s is not available in this ftp-deployment version.',
                get_class($target),
                $property
            ));
        }

        $refProperty = $reflection->getProperty($property);
        $refProperty->setAccessible(true);
        return $refProperty;
    }
}
