<?php

declare(strict_types=1);

namespace EidCloud\ServerPlane;

use EidCloud\ServerPlane\Services\ServiceInterface;
use EidCloud\ServerPlane\Services\NginxService;
use EidCloud\ServerPlane\Services\PhpFpmService;
use EidCloud\ServerPlane\Services\DatabaseService;
use EidCloud\ServerPlane\Services\RedisService;
use EidCloud\ServerPlane\Services\FirewallService;
use EidCloud\ServerPlane\Services\SslService;
use EidCloud\ServerPlane\Vitals\SystemVitals;
use EidCloud\ServerPlane\Audit\HealthAuditor;
use EidCloud\ServerPlane\UI\TerminalDashboard;

class ServerPlane
{
    public const VERSION = '1.0.0';

    /** @var array<string, ServiceInterface> */
    protected array $services = [];
    protected SystemVitals $vitals;
    protected HealthAuditor $auditor;
    protected TerminalDashboard $dashboard;

    public function __construct(
        ?SystemVitals $vitals = null,
        ?HealthAuditor $auditor = null,
        ?TerminalDashboard $dashboard = null
    ) {
        $this->vitals = $vitals ?? new SystemVitals();
        $this->auditor = $auditor ?? new HealthAuditor();
        $this->dashboard = $dashboard ?? new TerminalDashboard();

        $this->registerDefaultServices();
    }

    protected function registerDefaultServices(): void
    {
        $this->registerService('nginx', new NginxService());
        $this->registerService('php-fpm', new PhpFpmService());
        $this->registerService('mysql', new DatabaseService());
        $this->registerService('redis', new RedisService());
        $this->registerService('ufw', new FirewallService());
        $this->registerService('ssl', new SslService());
    }

    public function registerService(string $key, ServiceInterface $service): void
    {
        $this->services[strtolower($key)] = $service;
    }

    public function getService(string $key): ?ServiceInterface
    {
        return $this->services[strtolower($key)] ?? null;
    }

    /**
     * @return array<string, ServiceInterface>
     */
    public function getServices(): array
    {
        return $this->services;
    }

    public function getVitalsCollector(): SystemVitals
    {
        return $this->vitals;
    }

    public function getAuditor(): HealthAuditor
    {
        return $this->auditor;
    }

    public function getDashboard(): TerminalDashboard
    {
        return $this->dashboard;
    }

    /**
     * AI-ready full JSON state snapshot
     */
    public function getSnapshot(): array
    {
        $serviceStatuses = [];
        foreach ($this->services as $name => $svc) {
            $serviceStatuses[$name] = $svc->getStatus();
        }

        $vitalsData = $this->vitals->getAllVitals();
        $auditData = $this->auditor->audit($this->services, $vitalsData);

        return [
            'eidcloud_version' => self::VERSION,
            'generated_at' => date('c'),
            'vitals' => $vitalsData,
            'services' => $serviceStatuses,
            'audit' => $auditData
        ];
    }

    public function renderDashboard(bool $clear = false): string
    {
        return $this->dashboard->render($this->getSnapshot(), $clear);
    }
}
