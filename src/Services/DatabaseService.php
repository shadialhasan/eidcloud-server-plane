<?php

declare(strict_types=1);

namespace EidCloud\ServerPlane\Services;

class DatabaseService extends AbstractService
{
    protected ?string $detectedEngine = null;
    protected ?string $detectedUnit = null;

    public function getName(): string
    {
        return 'MySQL / MariaDB Database Server';
    }

    public function detectEngine(): array
    {
        if ($this->detectedUnit !== null) {
            return ['engine' => $this->detectedEngine, 'unit' => $this->detectedUnit];
        }

        $units = [
            'mariadb' => 'MariaDB',
            'mysql' => 'MySQL'
        ];

        foreach ($units as $unit => $engine) {
            $res = $this->executeCommand("systemctl status {$unit} 2>/dev/null");
            if ($res['success'] && !empty($res['stdout']) && !str_contains($res['stdout'], 'could not be found') && !str_contains($res['stdout'], 'not-found')) {
                $this->detectedEngine = $engine;
                $this->detectedUnit = $unit;
                return ['engine' => $engine, 'unit' => $unit];
            }
        }

        $this->detectedEngine = 'MySQL';
        $this->detectedUnit = 'mysql';
        return ['engine' => 'MySQL', 'unit' => 'mysql'];
    }

    public function isInstalled(): bool
    {
        $info = $this->detectEngine();
        $unit = $info['unit'];
        $res = $this->executeCommand("systemctl list-unit-files {$unit}.service 2>/dev/null");
        if ($res['success'] && str_contains($res['stdout'], $unit)) {
            return true;
        }

        return $this->isCommandAvailable('mysql') || $this->isCommandAvailable('mariadb');
    }

    public function isRunning(): bool
    {
        $info = $this->detectEngine();
        return $this->isUnitActive($info['unit']);
    }

    public function getVersion(): ?string
    {
        $res = $this->executeCommand('mysql --version 2>&1');
        if (preg_match('/(?:Distrib|Ver|from)\s+([0-9\.\-a-zA-Z]+)/i', $res['stdout'], $matches)) {
            return $matches[1];
        }
        return null;
    }

    public function getStatus(): array
    {
        $info = $this->detectEngine();
        $installed = $this->isInstalled();
        $running = $installed && $this->isRunning();
        $enabled = $installed && $this->isUnitEnabled($info['unit']);
        $version = $installed ? $this->getVersion() : null;

        return [
            'service' => 'database',
            'engine' => $info['engine'],
            'unit' => $info['unit'],
            'label' => $info['engine'] . ' Server',
            'installed' => $installed,
            'running' => $running,
            'enabled' => $enabled,
            'version' => $version
        ];
    }

    public function start(): array
    {
        $info = $this->detectEngine();
        return $this->systemctl('start', $info['unit']);
    }

    public function stop(): array
    {
        $info = $this->detectEngine();
        return $this->systemctl('stop', $info['unit']);
    }

    public function restart(): array
    {
        $info = $this->detectEngine();
        return $this->systemctl('restart', $info['unit']);
    }

    public function reload(): array
    {
        $info = $this->detectEngine();
        return $this->systemctl('reload', $info['unit']);
    }
}
