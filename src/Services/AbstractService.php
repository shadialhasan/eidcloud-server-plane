<?php

declare(strict_types=1);

namespace EidCloud\ServerPlane\Services;

abstract class AbstractService implements ServiceInterface
{
    protected string $systemctlPath = 'systemctl';
    protected ?\Closure $commandExecutor = null;

    public function setCommandExecutor(?callable $executor): void
    {
        $this->commandExecutor = $executor ? \Closure::fromCallable($executor) : null;
    }

    public function executeCommand(string $command): array
    {
        if ($this->commandExecutor !== null) {
            return ($this->commandExecutor)($command);
        }

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ];

        $process = @proc_open($command, $descriptorSpec, $pipes);

        if (!is_resource($process)) {
            return [
                'success' => false,
                'code' => 127,
                'stdout' => '',
                'stderr' => 'Failed to execute command: ' . $command
            ];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            'success' => $exitCode === 0,
            'code' => $exitCode,
            'stdout' => trim((string)$stdout),
            'stderr' => trim((string)$stderr)
        ];
    }

    protected function isCommandAvailable(string $cmd): bool
    {
        $res = $this->executeCommand("command -v {$cmd} 2>/dev/null || which {$cmd} 2>/dev/null");
        return $res['success'] && !empty($res['stdout']);
    }

    protected function systemctl(string $action, string $unit): array
    {
        return $this->executeCommand("systemctl {$action} {$unit} 2>&1");
    }

    protected function isUnitActive(string $unit): bool
    {
        $res = $this->executeCommand("systemctl is-active {$unit} 2>/dev/null");
        return $res['success'] && trim($res['stdout']) === 'active';
    }

    protected function isUnitEnabled(string $unit): bool
    {
        $res = $this->executeCommand("systemctl is-enabled {$unit} 2>/dev/null");
        return $res['success'] && trim($res['stdout']) === 'enabled';
    }
}
