<?php

declare(strict_types=1);

namespace EidCloud\ServerPlane\Audit;

use EidCloud\ServerPlane\Services\ServiceInterface;

class HealthAuditor
{
    public const LEVEL_OK = 'OK';
    public const LEVEL_WARN = 'WARN';
    public const LEVEL_CRITICAL = 'CRITICAL';

    /**
     * @param ServiceInterface[] $services
     * @param array $vitals
     */
    public function audit(array $services, array $vitals): array
    {
        $findings = [];
        $score = 100;

        // 1. Audit CPU Load
        $cpu = $vitals['cpu'] ?? [];
        $utilization = $cpu['utilization_percent'] ?? 0;
        if ($utilization > 90) {
            $findings[] = [
                'category' => 'Compute',
                'level' => self::LEVEL_CRITICAL,
                'title' => 'Critical CPU Load Detected',
                'detail' => "CPU load is at {$utilization}%, nearing complete exhaustion.",
                'recommendation' => 'Inspect top processes (`top` or `eidcloud-server status`) and scale compute or kill rogue workers.'
            ];
            $score -= 20;
        } elseif ($utilization > 75) {
            $findings[] = [
                'category' => 'Compute',
                'level' => self::LEVEL_WARN,
                'title' => 'Elevated CPU Utilization',
                'detail' => "CPU load is at {$utilization}%.",
                'recommendation' => 'Monitor high-load cron jobs and configure PHP-FPM process throttling.'
            ];
            $score -= 10;
        }

        // 2. Audit RAM
        $mem = $vitals['memory'] ?? [];
        $memPercent = $mem['percent'] ?? 0;
        if ($memPercent > 90) {
            $findings[] = [
                'category' => 'Memory',
                'level' => self::LEVEL_CRITICAL,
                'title' => 'Severe Memory Pressure (OOM Risk)',
                'detail' => "Memory usage is {$memPercent}% with {$mem['free_human']} available.",
                'recommendation' => 'Reduce PHP-FPM pm.max_children or Redis maxmemory to prevent Linux OOM killer termination.'
            ];
            $score -= 25;
        } elseif ($memPercent > 80) {
            $findings[] = [
                'category' => 'Memory',
                'level' => self::LEVEL_WARN,
                'title' => 'High Memory Utilization',
                'detail' => "Memory usage is at {$memPercent}%.",
                'recommendation' => 'Review memory leaks in background daemon workers and configure swap allocation.'
            ];
            $score -= 10;
        }

        // 3. Audit Disk Storage
        $disk = $vitals['disk'] ?? [];
        $diskPercent = $disk['percent'] ?? 0;
        if ($diskPercent > 90) {
            $findings[] = [
                'category' => 'Storage',
                'level' => self::LEVEL_CRITICAL,
                'title' => 'Root Disk Exhaustion Imminent',
                'detail' => "Storage is {$diskPercent}% full ({$disk['free_human']} free on {$disk['path']}).",
                'recommendation' => 'Rotate `/var/log/*`, purge old journal logs via `journalctl --vacuum-time=3d`, or expand volume.'
            ];
            $score -= 30;
        } elseif ($diskPercent > 80) {
            $findings[] = [
                'category' => 'Storage',
                'level' => self::LEVEL_WARN,
                'title' => 'High Disk Utilization',
                'detail' => "Storage is {$diskPercent}% full.",
                'recommendation' => 'Inspect large log directories and prune unneeded docker images/build cache.'
            ];
            $score -= 10;
        }

        // 4. Audit Core Services
        foreach ($services as $service) {
            $status = $service->getStatus();
            $label = $status['label'] ?? $service->getName();

            if ($status['installed'] && !$status['running']) {
                $findings[] = [
                    'category' => 'Service',
                    'level' => self::LEVEL_CRITICAL,
                    'title' => "Service Inactive: {$label}",
                    'detail' => "{$label} is installed but currently stopped.",
                    'recommendation' => "Attempt recovery using `eidcloud-server " . ($status['service'] ?? 'service') . " --restart` and review `systemctl status`."
                ];
                $score -= 15;
            }

            // Specific checks
            if (($status['service'] ?? '') === 'nginx' && isset($status['config_status'])) {
                if (!$status['config_status']['valid'] && $status['installed']) {
                    $findings[] = [
                        'category' => 'Service',
                        'level' => self::LEVEL_CRITICAL,
                        'title' => 'Nginx Configuration Error',
                        'detail' => 'Nginx syntax test failed: ' . $status['config_status']['output'],
                        'recommendation' => 'Fix syntax error in `/etc/nginx/nginx.conf` or `/etc/nginx/sites-enabled/*` before reloading.'
                    ];
                    $score -= 20;
                }
            }

            if (($status['service'] ?? '') === 'ssl') {
                if (($status['expired_count'] ?? 0) > 0) {
                    $findings[] = [
                        'category' => 'Security',
                        'level' => self::LEVEL_CRITICAL,
                        'title' => "Expired SSL Certificates ({$status['expired_count']})",
                        'detail' => 'One or more SSL certificates have expired, resulting in browser trust warnings.',
                        'recommendation' => 'Run `certbot renew --force-renewal` or check DNS verification challenges.'
                    ];
                    $score -= 25;
                } elseif (($status['expiring_soon_count'] ?? 0) > 0) {
                    $findings[] = [
                        'category' => 'Security',
                        'level' => self::LEVEL_WARN,
                        'title' => "SSL Certificates Expiring Soon ({$status['expiring_soon_count']})",
                        'detail' => 'SSL certificates will expire in less than 14 days.',
                        'recommendation' => 'Verify certbot systemd timer: `systemctl list-timers | grep certbot`.'
                    ];
                    $score -= 10;
                }
            }

            if (($status['service'] ?? '') === 'ufw') {
                if ($status['installed'] && !$status['running']) {
                    $findings[] = [
                        'category' => 'Security',
                        'level' => self::LEVEL_WARN,
                        'title' => 'Firewall Inactive',
                        'detail' => 'UFW is installed on the host but the firewall is currently inactive.',
                        'recommendation' => 'Enable UFW (`ufw enable`) ensuring port 22/SSH is allowed first.'
                    ];
                    $score -= 15;
                }
            }
        }

        // Open Ports Security Check
        $openPorts = $vitals['open_ports'] ?? [];
        foreach ($openPorts as $p) {
            if ($p['port'] === 3306 && ($p['address'] === '0.0.0.0' || $p['address'] === '::')) {
                $findings[] = [
                    'category' => 'Security',
                    'level' => self::LEVEL_WARN,
                    'title' => 'MySQL Bound to Public Address',
                    'detail' => 'MySQL port 3306 is listening on all network interfaces (0.0.0.0).',
                    'recommendation' => 'Bind MySQL to 127.0.0.1 in `mysqld.cnf` or enforce UFW restrict rules.'
                ];
                $score -= 10;
            }
        }

        $score = max(0, min(100, $score));

        $healthStatus = match (true) {
            $score >= 85 => 'HEALTHY',
            $score >= 60 => 'DEGRADED',
            default => 'CRITICAL'
        };

        return [
            'status' => $healthStatus,
            'score' => $score,
            'findings_count' => count($findings),
            'findings' => $findings,
            'generated_at' => date('c')
        ];
    }
}
