<?php

declare(strict_types=1);

namespace EidCloud\ServerPlane\Services;

class NginxService extends AbstractService
{
    protected string $serviceName = 'nginx';

    public function getName(): string
    {
        return 'Nginx Web Server';
    }

    public function isInstalled(): bool
    {
        return $this->isCommandAvailable('nginx') || file_exists('/etc/nginx');
    }

    public function isRunning(): bool
    {
        return $this->isUnitActive($this->serviceName);
    }

    public function getVersion(): ?string
    {
        $res = $this->executeCommand('nginx -v 2>&1');
        if (preg_match('/nginx\/([0-9\.]+)/i', $res['stdout'] . ' ' . $res['stderr'], $matches)) {
            return $matches[1];
        }
        return null;
    }

    public function testConfig(): array
    {
        $res = $this->executeCommand('nginx -t 2>&1');
        $output = $res['stdout'] . ($res['stderr'] ? "\n" . $res['stderr'] : '');
        $syntaxOk = str_contains($output, 'syntax is ok');
        $testSuccessful = str_contains($output, 'test is successful');

        return [
            'valid' => $syntaxOk && $testSuccessful,
            'output' => trim($output)
        ];
    }

    public function getStatus(): array
    {
        $installed = $this->isInstalled();
        $running = $installed && $this->isRunning();
        $enabled = $installed && $this->isUnitEnabled($this->serviceName);
        $version = $installed ? $this->getVersion() : null;

        return [
            'service' => 'nginx',
            'label' => $this->getName(),
            'installed' => $installed,
            'running' => $running,
            'enabled' => $enabled,
            'version' => $version,
            'config_status' => $installed ? $this->testConfig() : ['valid' => false, 'output' => 'Not installed']
        ];
    }

    public function start(): array
    {
        return $this->systemctl('start', $this->serviceName);
    }

    public function stop(): array
    {
        return $this->systemctl('stop', $this->serviceName);
    }

    public function restart(): array
    {
        $test = $this->testConfig();
        if (!$test['valid']) {
            return [
                'success' => false,
                'code' => 1,
                'stdout' => '',
                'stderr' => "Cannot restart Nginx: Configuration test failed.\n" . $test['output']
            ];
        }
        return $this->systemctl('restart', $this->serviceName);
    }

    public function reload(): array
    {
        $test = $this->testConfig();
        if (!$test['valid']) {
            return [
                'success' => false,
                'code' => 1,
                'stdout' => '',
                'stderr' => "Cannot reload Nginx: Configuration test failed.\n" . $test['output']
            ];
        }
        return $this->systemctl('reload', $this->serviceName);
    }
}
