<?php

declare(strict_types=1);

namespace EidCloud\ServerPlane\UI;

class TerminalDashboard
{
    public const RESET = "\033[0m";
    public const BOLD = "\033[1m";
    public const DIM = "\033[2m";
    public const RED = "\033[31m";
    public const GREEN = "\033[32m";
    public const YELLOW = "\033[33m";
    public const BLUE = "\033[34m";
    public const MAGENTA = "\033[35m";
    public const CYAN = "\033[36m";
    public const WHITE = "\033[37m";
    public const BG_BLUE = "\033[44m";
    public const BG_DARK = "\033[48;5;236m";

    public function render(array $snapshot, bool $clear = false): string
    {
        $vitals = $snapshot['vitals'] ?? [];
        $services = $snapshot['services'] ?? [];
        $audit = $snapshot['audit'] ?? [];
        $host = $vitals['host'] ?? [];
        $cpu = $vitals['cpu'] ?? [];
        $mem = $vitals['memory'] ?? [];
        $disk = $vitals['disk'] ?? [];

        $out = '';
        if ($clear) {
            $out .= "\033[2J\033[H";
        }

        $w = 84;
        $bar = str_repeat('─', $w);

        $out .= self::BOLD . self::CYAN . "┌" . $bar . "┐\n" . self::RESET;
        $title = " 🎛️  EIDCLOUD SERVER PLANE  │  UNIFIED LINUX CONTROL PLANE ";
        $out .= self::BOLD . self::CYAN . "│" . self::WHITE . str_pad($title, $w) . self::CYAN . "│\n" . self::RESET;
        $sub = sprintf(" Host: %s (%s)  │  Kernel: %s  │  Uptime: %s",
            $host['hostname'] ?? 'localhost',
            $host['os'] ?? 'Linux',
            $host['kernel'] ?? 'N/A',
            $host['uptime']['formatted'] ?? 'N/A'
        );
        $out .= self::BOLD . self::CYAN . "│" . self::DIM . str_pad($sub, $w) . self::RESET . self::CYAN . "│\n" . self::RESET;
        $out .= self::BOLD . self::CYAN . "├" . $bar . "┤\n" . self::RESET;

        // Vitals Section
        $out .= self::BOLD . self::CYAN . "│ " . self::YELLOW . "SYSTEM VITALS & RESOURCE UTILIZATION" . str_repeat(' ', $w - 38) . self::CYAN . "│\n" . self::RESET;
        
        $cpuBar = $this->renderProgressBar($cpu['utilization_percent'] ?? 0, 24);
        $cpuStr = sprintf(" CPU [%s] %5.1f%% (%d Cores | Load: %.2f %.2f %.2f)",
            $cpuBar,
            $cpu['utilization_percent'] ?? 0,
            $cpu['cores'] ?? 1,
            $cpu['load_1m'] ?? 0,
            $cpu['load_5m'] ?? 0,
            $cpu['load_15m'] ?? 0
        );
        $out .= self::CYAN . "│" . self::WHITE . str_pad($cpuStr, $w) . self::CYAN . "│\n" . self::RESET;

        $memBar = $this->renderProgressBar($mem['percent'] ?? 0, 24);
        $memStr = sprintf(" RAM [%s] %5.1f%% (%s / %s | Free: %s)",
            $memBar,
            $mem['percent'] ?? 0,
            $mem['used_human'] ?? '0 B',
            $mem['total_human'] ?? '0 B',
            $mem['free_human'] ?? '0 B'
        );
        $out .= self::CYAN . "│" . self::WHITE . str_pad($memStr, $w) . self::CYAN . "│\n" . self::RESET;

        $diskBar = $this->renderProgressBar($disk['percent'] ?? 0, 24);
        $diskStr = sprintf(" DSK [%s] %5.1f%% (%s / %s | Free: %s)",
            $diskBar,
            $disk['percent'] ?? 0,
            $disk['used_human'] ?? '0 B',
            $disk['total_human'] ?? '0 B',
            $disk['free_human'] ?? '0 B'
        );
        $out .= self::CYAN . "│" . self::WHITE . str_pad($diskStr, $w) . self::CYAN . "│\n" . self::RESET;

        $out .= self::BOLD . self::CYAN . "├" . $bar . "┤\n" . self::RESET;

        // Managed Services
        $out .= self::BOLD . self::CYAN . "│ " . self::YELLOW . "MANAGED SERVICES" . str_repeat(' ', $w - 18) . self::CYAN . "│\n" . self::RESET;
        foreach ($services as $svc) {
            $label = $svc['label'] ?? $svc['service'];
            $statusBadge = $this->formatServiceStatus($svc);
            $ver = !empty($svc['version']) ? "v{$svc['version']}" : "detected";
            $line = sprintf("   %-34s %s  %s", $label, $statusBadge, self::DIM . $ver . self::RESET);
            // strip ANSI for padding calculation
            $plainLen = strlen(preg_replace('/\033\[[0-9;]*m/', '', $line));
            $pad = max(0, $w - $plainLen);
            $out .= self::CYAN . "│" . $line . str_repeat(' ', $pad) . self::CYAN . "│\n" . self::RESET;
        }

        $out .= self::BOLD . self::CYAN . "├" . $bar . "┤\n" . self::RESET;

        // Health Status & Audit
        $score = $audit['score'] ?? 100;
        $healthState = $audit['status'] ?? 'HEALTHY';
        $healthColor = match ($healthState) {
            'HEALTHY' => self::GREEN,
            'DEGRADED' => self::YELLOW,
            default => self::RED
        };
        $auditHeader = sprintf(" HEALTH AUDIT: %s%s (%d/100)%s  │  Findings: %d",
            $healthColor . self::BOLD,
            $healthState,
            $score,
            self::RESET . self::WHITE,
            $audit['findings_count'] ?? 0
        );
        $plainH = strlen(preg_replace('/\033\[[0-9;]*m/', '', $auditHeader));
        $out .= self::CYAN . "│" . self::WHITE . $auditHeader . str_repeat(' ', max(0, $w - $plainH)) . self::CYAN . "│\n" . self::RESET;

        if (!empty($audit['findings'])) {
            foreach (array_slice($audit['findings'], 0, 3) as $f) {
                $lvlColor = $f['level'] === 'CRITICAL' ? self::RED : self::YELLOW;
                $fLine = sprintf("   %s[%s]%s %s: %s",
                    $lvlColor,
                    $f['level'],
                    self::RESET,
                    $f['title'],
                    $f['recommendation']
                );
                $cleanF = preg_replace('/\033\[[0-9;]*m/', '', $fLine);
                if (strlen($cleanF) > $w - 2) {
                    $fLine = substr($fLine, 0, $w + 10) . '...';
                }
                $cleanLen = strlen(preg_replace('/\033\[[0-9;]*m/', '', $fLine));
                $out .= self::CYAN . "│" . $fLine . str_repeat(' ', max(0, $w - $cleanLen)) . self::CYAN . "│\n" . self::RESET;
            }
        } else {
            $fLine = "   " . self::GREEN . "✔ All checks passed. Zero critical vulnerabilities or service outages." . self::RESET;
            $cleanLen = strlen(preg_replace('/\033\[[0-9;]*m/', '', $fLine));
            $out .= self::CYAN . "│" . $fLine . str_repeat(' ', max(0, $w - $cleanLen)) . self::CYAN . "│\n" . self::RESET;
        }

        $out .= self::BOLD . self::CYAN . "└" . $bar . "┘\n" . self::RESET;

        return $out;
    }

    protected function renderProgressBar(float $percent, int $width = 20): string
    {
        $percent = max(0, min(100, $percent));
        $filled = (int)round(($percent / 100) * $width);
        $empty = max(0, $width - $filled);

        $color = match (true) {
            $percent > 85 => self::RED,
            $percent > 70 => self::YELLOW,
            default => self::GREEN
        };

        return $color . str_repeat('█', $filled) . self::DIM . str_repeat('░', $empty) . self::RESET;
    }

    protected function formatServiceStatus(array $svc): string
    {
        if (!($svc['installed'] ?? false)) {
            return self::DIM . "[NOT INSTALLED]" . self::RESET;
        }

        if ($svc['running'] ?? false) {
            return self::GREEN . self::BOLD . "[● ACTIVE]" . self::RESET;
        }

        return self::RED . self::BOLD . "[■ STOPPED]" . self::RESET;
    }
}
