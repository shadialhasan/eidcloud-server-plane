<?php

declare(strict_types=1);

namespace EidCloud\ServerPlane\Services;

interface ServiceInterface
{
    public function getName(): string;
    public function isInstalled(): bool;
    public function isRunning(): bool;
    public function getStatus(): array;
    public function start(): array;
    public function stop(): array;
    public function restart(): array;
    public function reload(): array;
}
