<?php

declare(strict_types=1);

namespace EidCloud\ServerPlane\Services;

class FirewallService extends AbstractService
{
    public function getName(): string
    {
        return 'UFW (Uncomplicated Firewall)';
    }

    public function isInstalled(): bool
    {
        return $this->isCommandAvailable('ufw');
    }

    public function isRunning(): bool
    {
        $res = $this->executeCommand('ufw status 2>/dev/null');
        return $res['success'] && str_contains(strtolower($res['stdout']), 'status: active');
    }

    public function getRules(): array
    {
        $res = $this->executeCommand('ufw status numbered 2>/dev/null');
        if (!$res['success']) {
            return [];
        }

        $lines = explode("\n", $res['stdout']);
        $rules = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^\[\s*(\d+)\]\s+(.*?)\s+(ALLOW|DENY|REJECT|LIMIT)\s+(IN|OUT)?\s*(.*)$/i', $line, $matches)) {
                $rules[] = [
                    'index' => (int)$matches[1],
                    'to' => trim($matches[2]),
                    'action' => trim($matches[3]),
                    'direction' => trim($matches[4] ?? 'IN'),
                    'from' => trim($matches[5] ?? 'Anywhere')
                ];
            }
        }

        return $rules;
    }

    public function getStatus(): array
    {
        $installed = $this->isInstalled();
        $active = $installed && $this->isRunning();
        $rules = $installed ? $this->getRules() : [];

        return [
            'service' => 'ufw',
            'label' => $this->getName(),
            'installed' => $installed,
            'running' => $active,
            'enabled' => $active,
            'rules_count' => count($rules),
            'rules' => $rules
        ];
    }

    public function allowPort(string|int $port, string $proto = 'tcp'): array
    {
        return $this->executeCommand("ufw allow {$port}/{$proto}");
    }

    public function denyPort(string|int $port, string $proto = 'tcp'): array
    {
        return $this->executeCommand("ufw deny {$port}/{$proto}");
    }

    public function deleteRule(int $number): array
    {
        return $this->executeCommand("echo y | ufw delete {$number}");
    }

    public function start(): array
    {
        return $this->executeCommand('echo y | ufw enable');
    }

    public function stop(): array
    {
        return $this->executeCommand('ufw disable');
    }

    public function restart(): array
    {
        $this->executeCommand('ufw reload');
        return ['success' => true, 'code' => 0, 'stdout' => 'Firewall reloaded', 'stderr' => ''];
    }

    public function reload(): array
    {
        return $this->restart();
    }
}
