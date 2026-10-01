<?php

declare(strict_types=1);

namespace EidCloud\ServerPlane\Tests;

use EidCloud\ServerPlane\ServerPlane;
use EidCloud\ServerPlane\Services\NginxService;
use EidCloud\ServerPlane\Services\PhpFpmService;
use EidCloud\ServerPlane\Services\DatabaseService;
use EidCloud\ServerPlane\Services\RedisService;
use EidCloud\ServerPlane\Services\FirewallService;
use EidCloud\ServerPlane\Services\SslService;
use EidCloud\ServerPlane\Vitals\SystemVitals;
use EidCloud\ServerPlane\Audit\HealthAuditor;
use EidCloud\ServerPlane\UI\TerminalDashboard;

class ServerPlaneTest
{
    private int $assertions = 0;
    private int $passed = 0;
    private int $failed = 0;

    public function runAll(): bool
    {
        echo "\033[1mRunning EidCloud Server Plane Automated Test Suite\033[0m\n";
        echo str_repeat('=', 60) . "\n";

        $this->testCoreInstantiation();
        $this->testSystemVitals();
        $this->testNginxServiceMock();
        $this->testPhpFpmServiceMock();
        $this->testDatabaseServiceMock();
        $this->testRedisServiceMock();
        $this->testFirewallServiceMock();
        $this->testSslServiceMock();
        $this->testHealthAuditor();
        $this->testTerminalDashboard();
        $this->testFullSnapshotGeneration();

        echo str_repeat('=', 60) . "\n";
        printf("Tests: %d assertions, Passed: %d, Failed: %d\n", $this->assertions, $this->passed, $this->failed);

        return $this->failed === 0;
    }

    private function assert(bool $condition, string $message): void
    {
        $this->assertions++;
        if ($condition) {
            $this->passed++;
            echo "  \033[32m✔ PASS:\033[0m {$message}\n";
        } else {
            $this->failed++;
            echo "  \033[31m✖ FAIL:\033[0m {$message}\n";
        }
    }

    private function testCoreInstantiation(): void
    {
        echo "\n\033[36m[Test Group: Core Instantiation & Registration]\033[0m\n";
        $plane = new ServerPlane();
        $this->assert($plane instanceof ServerPlane, 'ServerPlane instantiates properly');
        $this->assert(count($plane->getServices()) >= 6, 'All 6 core services registered');
        $this->assert($plane->getService('nginx') instanceof NginxService, 'Nginx service retrieved');
        $this->assert($plane->getService('php-fpm') instanceof PhpFpmService, 'PHP-FPM service retrieved');
        $this->assert($plane->getService('mysql') instanceof DatabaseService, 'Database service retrieved');
        $this->assert($plane->getService('redis') instanceof RedisService, 'Redis service retrieved');
        $this->assert($plane->getService('ufw') instanceof FirewallService, 'Firewall service retrieved');
        $this->assert($plane->getService('ssl') instanceof SslService, 'SSL service retrieved');
    }

    private function testSystemVitals(): void
    {
        echo "\n\033[36m[Test Group: System Vitals Collector]\033[0m\n";
        $vitals = new SystemVitals();

        $host = $vitals->getHostInfo();
        $this->assert(!empty($host['hostname']), 'Host info contains hostname');
        $this->assert(!empty($host['os']), 'Host info contains OS name');

        $cpu = $vitals->getCpuUsage();
        $this->assert(isset($cpu['cores']) && $cpu['cores'] >= 1, 'CPU cores detected');
        $this->assert(isset($cpu['utilization_percent']), 'CPU utilization percentage calculated');

        $mem = $vitals->getMemoryUsage();
        $this->assert($mem['total_bytes'] > 0, 'Memory total bytes > 0');
        $this->assert(!empty($mem['total_human']), 'Memory human string formatted properly');

        $disk = $vitals->getDiskUsage();
        $this->assert($disk['total_bytes'] > 0, 'Disk total bytes > 0');
        $this->assert(isset($disk['percent']), 'Disk usage percent calculated');

        $net = $vitals->getNetworkStats();
        $this->assert(is_array($net) && count($net) > 0, 'Network interfaces retrieved');

        $ports = $vitals->getOpenPorts();
        $this->assert(is_array($ports), 'Open ports structure returned array');

        $procs = $vitals->getProcessTree(5);
        $this->assert(count($procs) <= 5, 'Top processes respects limit constraint');

        $formatted = $vitals->formatBytes(1073741824); // 1 GB
        $this->assert($formatted === '1 GB', "formatBytes(1GB) correctly returns '1 GB'");
    }

    private function testNginxServiceMock(): void
    {
        echo "\n\033[36m[Test Group: Nginx Service Execution & Mocking]\033[0m\n";
        $nginx = new NginxService();
        $nginx->setCommandExecutor(function (string $cmd) {
            if (str_contains($cmd, 'nginx -v')) {
                return ['success' => true, 'code' => 0, 'stdout' => '', 'stderr' => 'nginx version: nginx/1.24.0'];
            }
            if (str_contains($cmd, 'nginx -t')) {
                return ['success' => true, 'code' => 0, 'stdout' => 'nginx: configuration file /etc/nginx/nginx.conf syntax is ok', 'stderr' => 'nginx: configuration file /etc/nginx/nginx.conf test is successful'];
            }
            if (str_contains($cmd, 'is-active')) {
                return ['success' => true, 'code' => 0, 'stdout' => 'active', 'stderr' => ''];
            }
            if (str_contains($cmd, 'is-enabled')) {
                return ['success' => true, 'code' => 0, 'stdout' => 'enabled', 'stderr' => ''];
            }
            if (str_contains($cmd, 'command -v nginx')) {
                return ['success' => true, 'code' => 0, 'stdout' => '/usr/sbin/nginx', 'stderr' => ''];
            }
            if (str_contains($cmd, 'reload')) {
                return ['success' => true, 'code' => 0, 'stdout' => '', 'stderr' => ''];
            }
            return ['success' => true, 'code' => 0, 'stdout' => 'ok', 'stderr' => ''];
        });

        $this->assert($nginx->isInstalled(), 'Mocked Nginx detected as installed');
        $this->assert($nginx->isRunning(), 'Mocked Nginx detected as running');
        $this->assert($nginx->getVersion() === '1.24.0', 'Mocked Nginx version parsed correctly (1.24.0)');
        
        $testRes = $nginx->testConfig();
        $this->assert($testRes['valid'] === true, 'Mocked Nginx config test reports valid');

        $reloadRes = $nginx->reload();
        $this->assert($reloadRes['success'] === true, 'Mocked Nginx reload executed cleanly');

        $status = $nginx->getStatus();
        $this->assert($status['running'] === true && $status['version'] === '1.24.0', 'getStatus() returns structured active state');
    }

    private function testPhpFpmServiceMock(): void
    {
        echo "\n\033[36m[Test Group: PHP-FPM Service Detection & Control]\033[0m\n";
        $fpm = new PhpFpmService();
        $fpm->setCommandExecutor(function (string $cmd) {
            if (str_contains($cmd, 'systemctl status php8.2-fpm')) {
                return ['success' => true, 'code' => 0, 'stdout' => 'Loaded: ... Active: active (running)', 'stderr' => ''];
            }
            if (str_contains($cmd, 'is-active')) {
                return ['success' => true, 'code' => 0, 'stdout' => 'active', 'stderr' => ''];
            }
            if (str_contains($cmd, 'is-enabled')) {
                return ['success' => true, 'code' => 0, 'stdout' => 'enabled', 'stderr' => ''];
            }
            if (str_contains($cmd, 'list-unit-files')) {
                return ['success' => true, 'code' => 0, 'stdout' => 'php8.2-fpm.service enabled', 'stderr' => ''];
            }
            return ['success' => true, 'code' => 0, 'stdout' => '', 'stderr' => ''];
        });

        $unit = $fpm->detectServiceUnit();
        $this->assert($unit === 'php8.2-fpm', 'Detected PHP-FPM unit is php8.2-fpm');
        $this->assert($fpm->isInstalled(), 'PHP-FPM detected as installed');
        $this->assert($fpm->isRunning(), 'PHP-FPM detected as running');
        $this->assert($fpm->getVersion() === '8.2', 'PHP-FPM version parsed as 8.2');
    }

    private function testDatabaseServiceMock(): void
    {
        echo "\n\033[36m[Test Group: Database Service Detection (MySQL/MariaDB)]\033[0m\n";
        $db = new DatabaseService();
        $db->setCommandExecutor(function (string $cmd) {
            if (str_contains($cmd, 'systemctl status mariadb')) {
                return ['success' => true, 'code' => 0, 'stdout' => 'Active: active', 'stderr' => ''];
            }
            if (str_contains($cmd, 'list-unit-files mariadb')) {
                return ['success' => true, 'code' => 0, 'stdout' => 'mariadb.service enabled', 'stderr' => ''];
            }
            if (str_contains($cmd, 'is-active mariadb')) {
                return ['success' => true, 'code' => 0, 'stdout' => 'active', 'stderr' => ''];
            }
            if (str_contains($cmd, 'mysql --version')) {
                return ['success' => true, 'code' => 0, 'stdout' => 'mariadb from 11.2.2-MariaDB, client 15.2 for Linux', 'stderr' => ''];
            }
            return ['success' => true, 'code' => 0, 'stdout' => '', 'stderr' => ''];
        });

        $engine = $db->detectEngine();
        $this->assert($engine['engine'] === 'MariaDB', 'MariaDB engine correctly detected');
        $this->assert($db->isRunning(), 'Database service is running');
        $this->assert($db->getVersion() === '11.2.2-MariaDB', 'MariaDB version extracted accurately');
    }

    private function testRedisServiceMock(): void
    {
        echo "\n\033[36m[Test Group: Redis Service & In-Memory Store]\033[0m\n";
        $redis = new RedisService();
        $redis->setCommandExecutor(function (string $cmd) {
            if (str_contains($cmd, 'command -v redis-server')) {
                return ['success' => true, 'code' => 0, 'stdout' => '/usr/bin/redis-server', 'stderr' => ''];
            }
            if (str_contains($cmd, 'redis-server --version')) {
                return ['success' => true, 'code' => 0, 'stdout' => 'Redis server v=7.2.4 sha=00000000:0 malloc=jemalloc-5.3.0 bits=64', 'stderr' => ''];
            }
            if (str_contains($cmd, 'is-active')) {
                return ['success' => true, 'code' => 0, 'stdout' => 'active', 'stderr' => ''];
            }
            if (str_contains($cmd, 'redis-cli ping')) {
                return ['success' => true, 'code' => 0, 'stdout' => 'PONG', 'stderr' => ''];
            }
            return ['success' => true, 'code' => 0, 'stdout' => '', 'stderr' => ''];
        });

        $this->assert($redis->isInstalled(), 'Redis is installed');
        $this->assert($redis->isRunning(), 'Redis is active');
        $this->assert($redis->getVersion() === '7.2.4', 'Redis version identified as 7.2.4');
        $this->assert($redis->ping() === true, 'Redis CLI ping verified with PONG');
    }

    private function testFirewallServiceMock(): void
    {
        echo "\n\033[36m[Test Group: UFW Firewall Service]\033[0m\n";
        $ufw = new FirewallService();
        $ufw->setCommandExecutor(function (string $cmd) {
            if (str_contains($cmd, 'command -v ufw')) {
                return ['success' => true, 'code' => 0, 'stdout' => '/usr/sbin/ufw', 'stderr' => ''];
            }
            if (str_contains($cmd, 'ufw status numbered')) {
                $rulesOutput = "[ 1] 22/tcp                     ALLOW IN    Anywhere\n" .
                               "[ 2] 80/tcp                     ALLOW IN    Anywhere\n" .
                               "[ 3] 443/tcp                    ALLOW IN    Anywhere\n";
                return ['success' => true, 'code' => 0, 'stdout' => $rulesOutput, 'stderr' => ''];
            }
            if (str_contains($cmd, 'ufw status')) {
                return ['success' => true, 'code' => 0, 'stdout' => 'Status: active', 'stderr' => ''];
            }
            return ['success' => true, 'code' => 0, 'stdout' => '', 'stderr' => ''];
        });

        $this->assert($ufw->isInstalled(), 'UFW detected installed');
        $this->assert($ufw->isRunning(), 'UFW detected active');
        $rules = $ufw->getRules();
        $this->assert(count($rules) === 3, 'Parsed 3 active UFW rules');
        $this->assert($rules[0]['to'] === '22/tcp' && $rules[0]['action'] === 'ALLOW', 'Rule #1 is SSH allow');
    }

    private function testSslServiceMock(): void
    {
        echo "\n\033[36m[Test Group: SSL Certificate Manager]\033[0m\n";
        $ssl = new SslService();
        $ssl->setCommandExecutor(function (string $cmd) {
            if (str_contains($cmd, 'certbot certificates')) {
                $out = "Found the following certs:\n" .
                       "  Certificate Name: eidcloud.com\n" .
                       "    Serial Number: 48932049230492\n" .
                       "    Domains: eidcloud.com www.eidcloud.com\n" .
                       "    Expiry Date: 2026-12-31 23:59:59+00:00 (VALID: 90 days)\n" .
                       "    Certificate Path: /etc/letsencrypt/live/eidcloud.com/fullchain.pem\n";
                return ['success' => true, 'code' => 0, 'stdout' => $out, 'stderr' => ''];
            }
            if (str_contains($cmd, 'command -v certbot')) {
                return ['success' => true, 'code' => 0, 'stdout' => '/usr/bin/certbot', 'stderr' => ''];
            }
            return ['success' => true, 'code' => 0, 'stdout' => '', 'stderr' => ''];
        });

        $certs = $ssl->getCertificates();
        $this->assert(count($certs) === 1, 'Extracted 1 SSL certificate from certbot');
        $this->assert($certs[0]['name'] === 'eidcloud.com', 'Cert name matches eidcloud.com');
        $this->assert(in_array('www.eidcloud.com', $certs[0]['domains']), 'SAN includes www.eidcloud.com');
        $this->assert($certs[0]['days_remaining'] === 90, 'Cert remaining days is 90');
    }

    private function testHealthAuditor(): void
    {
        echo "\n\033[36m[Test Group: Health Check Auditor & Rules]\033[0m\n";
        $auditor = new HealthAuditor();

        // High load and stopped service scenario
        $mockVitals = [
            'cpu' => ['utilization_percent' => 95.0, 'cores' => 4, 'load_1m' => 8.5],
            'memory' => ['percent' => 88.0, 'free_human' => '1.5 GB'],
            'disk' => ['percent' => 92.0, 'free_human' => '5.0 GB', 'path' => '/'],
            'open_ports' => [
                ['proto' => 'tcp', 'address' => '0.0.0.0', 'port' => 3306, 'service' => 'MySQL / MariaDB']
            ]
        ];

        $mockNginx = new NginxService();
        $mockNginx->setCommandExecutor(fn() => ['success' => false, 'code' => 1, 'stdout' => 'inactive', 'stderr' => '']);

        $audit = $auditor->audit(['nginx' => $mockNginx], $mockVitals);
        $this->assert($audit['status'] === 'CRITICAL', 'High pressure scenario triggers CRITICAL status');
        $this->assert($audit['score'] < 60, 'Score is appropriately deducted under high pressure');
        $this->assert($audit['findings_count'] >= 4, 'Detected CPU, RAM, Disk, and MySQL public exposure findings');
    }

    private function testTerminalDashboard(): void
    {
        echo "\n\033[36m[Test Group: Terminal Dashboard Rendering]\033[0m\n";
        $dashboard = new TerminalDashboard();

        $snapshot = [
            'vitals' => [
                'host' => ['hostname' => 'prod-node-01', 'os' => 'Linux', 'kernel' => '6.5.0-35-generic', 'uptime' => ['formatted' => '42d 12h 5m']],
                'cpu' => ['utilization_percent' => 25.4, 'cores' => 8, 'load_1m' => 1.2, 'load_5m' => 0.8, 'load_15m' => 0.5],
                'memory' => ['percent' => 45.2, 'used_human' => '7.2 GB', 'total_human' => '16.0 GB', 'free_human' => '8.8 GB'],
                'disk' => ['percent' => 35.0, 'used_human' => '70.0 GB', 'total_human' => '200.0 GB', 'free_human' => '130.0 GB'],
            ],
            'services' => [
                ['service' => 'nginx', 'label' => 'Nginx Web Server', 'installed' => true, 'running' => true, 'version' => '1.24.0'],
                ['service' => 'php-fpm', 'label' => 'PHP-FPM Process Manager', 'installed' => true, 'running' => true, 'version' => '8.2']
            ],
            'audit' => [
                'status' => 'HEALTHY',
                'score' => 100,
                'findings_count' => 0,
                'findings' => []
            ]
        ];

        $rendered = $dashboard->render($snapshot, false);
        $this->assert(str_contains($rendered, 'EIDCLOUD SERVER PLANE'), 'Dashboard header contains title');
        $this->assert(str_contains($rendered, 'prod-node-01'), 'Dashboard displays hostname');
        $this->assert(str_contains($rendered, 'Nginx Web Server'), 'Dashboard lists services');
        $this->assert(str_contains($rendered, 'HEALTH AUDIT:'), 'Dashboard presents health score');
    }

    private function testFullSnapshotGeneration(): void
    {
        echo "\n\033[36m[Test Group: AI-Ready System State Snapshot]\033[0m\n";
        $plane = new ServerPlane();
        $snapshot = $plane->getSnapshot();

        $this->assert(isset($snapshot['eidcloud_version']), 'Snapshot contains eidcloud_version');
        $this->assert(isset($snapshot['generated_at']), 'Snapshot contains timestamp');
        $this->assert(isset($snapshot['vitals']['host']), 'Snapshot has host vitals');
        $this->assert(isset($snapshot['services']['nginx']), 'Snapshot has nginx service');
        $this->assert(isset($snapshot['audit']['status']), 'Snapshot has health audit status');

        $json = json_encode($snapshot);
        $this->assert(!empty($json) && json_last_error() === JSON_ERROR_NONE, 'Snapshot serializes cleanly into standard JSON');
    }
}
