<?php

declare(strict_types=1);

namespace EidCloud\ServerPlane\Services;

class PhpFpmService extends AbstractService
{
    protected ?string $detectedService = null;
    protected ?string $detectedVersion = null;

    public function getName(): string
    {
        return 'PHP-FPM Process Manager';
    }

    public function detectServiceUnit(): string
    {
        if ($this->detectedService !== null) {
            return $this->detectedService;
        }

        $versions = ['8.4', '8.3', '8.2', '8.1', '8.0', '7.4'];
        foreach ($versions as $ver) {
            $unit = "php{$ver}-fpm";
            $res = $this->executeCommand("systemctl status {$unit} 2>/dev/null");
            if ($res['success'] && !empty($res['stdout']) && !str_contains($res['stdout'], 'could not be found') && !str_contains($res['stdout'], 'not-found')) {
                $this->detectedService = $unit;
                $this->detectedVersion = $ver;
                return $unit;
            }
        }

        $res = $this->executeCommand("systemctl status php-fpm 2>/dev/null");
        if ($res['success'] && !empty($res['stdout']) && !str_contains($res['stdout'], 'could not be found') && !str_contains($res['stdout'], 'not-found')) {
            $this->detectedService = 'php-fpm';
            $this->detectedVersion = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
            return 'php-fpm';
        }

        $this->detectedService = 'php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '-fpm';
        $this->detectedVersion = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        return $this->detectedService;
    }

    public function isInstalled(): bool
    {
        $unit = $this->detectServiceUnit();
        $res = $this->executeCommand("systemctl list-unit-files {$unit}.service 2>/dev/null");
        if ($res['success'] && str_contains($res['stdout'], $unit)) {
            return true;
        }

        $binRes = $this->executeCommand("which php-fpm php-fpm" . PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION . " 2>/dev/null");
        return $binRes['success'] && !empty($binRes['stdout']);
    }

    public function isRunning(): bool
    {
        $unit = $this->detectServiceUnit();
        return $this->isUnitActive($unit);
    }

    public function getVersion(): ?string
    {
        $this->detectServiceUnit();
        return $this->detectedVersion;
    }

    public function testConfig(): array
    {
        $cmd = "php-fpm -t 2>&1";
        $ver = $this->getVersion();
        if ($ver) {
            $cmd = "php-fpm{$ver} -t 2>&1 || php-fpm -t 2>&1";
        }
        $res = $this->executeCommand($cmd);
        $output = $res['stdout'] . ($res['stderr'] ? "\n" . $res['stderr'] : '');
        $valid = str_contains(strtolower($output), 'test is successful') || str_contains(strtolower($output), 'syntax is ok');

        return [
            'valid' => $valid || empty($output),
            'output' => trim($output)
        ];
    }

    public function getStatus(): array
    {
        $unit = $this->detectServiceUnit();
        $installed = $this->isInstalled();
        $running = $installed && $this->isRunning();
        $enabled = $installed && $this->isUnitEnabled($unit);
        $version = $installed ? $this->getVersion() : null;

        return [
            'service' => 'php-fpm',
            'unit' => $unit,
            'label' => $this->getName(),
            'installed' => $installed,
            'running' => $running,
            'enabled' => $enabled,
            'version' => $version
        ];
    }

    public function start(): array
    {
        return $this->systemctl('start', $this->detectServiceUnit());
    }

    public function stop(): array
    {
        return $this->systemctl('stop', $this->detectServiceUnit());
    }

    public function restart(): array
    {
        return $this->systemctl('restart', $this->detectServiceUnit());
    }

    public function reload(): array
    {
        return $this->systemctl('reload', $this->detectServiceUnit());
    }
}
