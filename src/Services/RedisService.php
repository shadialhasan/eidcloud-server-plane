<?php

declare(strict_types=1);

namespace EidCloud\ServerPlane\Services;

class RedisService extends AbstractService
{
    protected ?string $detectedUnit = null;

    public function getName(): string
    {
        return 'Redis In-Memory Data Store';
    }

    public function detectServiceUnit(): string
    {
        if ($this->detectedUnit !== null) {
            return $this->detectedUnit;
        }

        $units = ['redis-server', 'redis'];
        foreach ($units as $unit) {
            $res = $this->executeCommand("systemctl status {$unit} 2>/dev/null");
            if (!str_contains($res['stdout'] . $res['stderr'], 'could not be found') && !str_contains($res['stdout'] . $res['stderr'], 'not-found')) {
                $this->detectedUnit = $unit;
                return $unit;
            }
        }

        $this->detectedUnit = 'redis-server';
        return 'redis-server';
    }

    public function isInstalled(): bool
    {
        return $this->isCommandAvailable('redis-server') || $this->isCommandAvailable('redis-cli');
    }

    public function isRunning(): bool
    {
        return $this->isUnitActive($this->detectServiceUnit());
    }

    public function getVersion(): ?string
    {
        $res = $this->executeCommand('redis-server --version 2>&1');
        if (preg_match('/v=([0-9\.]+)/i', $res['stdout'], $matches)) {
            return $matches[1];
        }
        return null;
    }

    public function ping(): bool
    {
        $res = $this->executeCommand('redis-cli ping 2>/dev/null');
        return $res['success'] && trim($res['stdout']) === 'PONG';
    }

    public function getStatus(): array
    {
        $unit = $this->detectServiceUnit();
        $installed = $this->isInstalled();
        $running = $installed && $this->isRunning();
        $enabled = $installed && $this->isUnitEnabled($unit);
        $version = $installed ? $this->getVersion() : null;
        $responsive = $running ? $this->ping() : false;

        return [
            'service' => 'redis',
            'unit' => $unit,
            'label' => $this->getName(),
            'installed' => $installed,
            'running' => $running,
            'enabled' => $enabled,
            'version' => $version,
            'ping_pong' => $responsive
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
        return $this->systemctl('restart', $this->detectServiceUnit());
    }
}
