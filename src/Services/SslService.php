<?php

declare(strict_types=1);

namespace EidCloud\ServerPlane\Services;

class SslService extends AbstractService
{
    public function getName(): string
    {
        return 'SSL Certificate Manager';
    }

    public function isInstalled(): bool
    {
        return $this->isCommandAvailable('certbot') || $this->isCommandAvailable('openssl');
    }

    public function isRunning(): bool
    {
        return true;
    }

    public function getCertificates(): array
    {
        $certs = [];

        // Check certbot certificates
        if ($this->isCommandAvailable('certbot')) {
            $res = $this->executeCommand('certbot certificates 2>/dev/null');
            if ($res['success']) {
                $output = $res['stdout'];
                $blocks = preg_split('/Certificate Name:\s*/', $output);
                array_shift($blocks);

                foreach ($blocks as $block) {
                    $lines = explode("\n", $block);
                    $name = trim($lines[0]);
                    $domains = [];
                    $expiry = null;
                    $daysRemaining = null;
                    $certPath = null;

                    foreach ($lines as $line) {
                        $line = trim($line);
                        if (str_starts_with($line, 'Domains:')) {
                            $domains = array_map('trim', explode(' ', substr($line, 8)));
                        } elseif (str_starts_with($line, 'Expiry Date:')) {
                            if (preg_match('/Expiry Date:\s*([0-9\-]+ [0-9:]+)/', $line, $m)) {
                                $expiry = $m[1];
                            }
                            if (preg_match('/VALID:\s*(\d+)\s*days/i', $line, $m)) {
                                $daysRemaining = (int)$m[1];
                            }
                        } elseif (str_starts_with($line, 'Certificate Path:')) {
                            $certPath = trim(substr($line, 17));
                        }
                    }

                    $certs[] = [
                        'name' => $name,
                        'domains' => $domains,
                        'expiry' => $expiry,
                        'days_remaining' => $daysRemaining,
                        'path' => $certPath,
                        'type' => 'certbot'
                    ];
                }
            }
        }

        // Fallback or scan standard Let's Encrypt directory
        if (empty($certs) && is_dir('/etc/letsencrypt/live')) {
            $dirs = @glob('/etc/letsencrypt/live/*', GLOB_ONLYDIR) ?: [];
            foreach ($dirs as $dir) {
                $name = basename($dir);
                if ($name === 'README') continue;
                $certFile = $dir . '/cert.pem';
                if (file_exists($certFile)) {
                    $parsed = $this->parseCertificateFile($certFile);
                    if ($parsed) {
                        $parsed['name'] = $name;
                        $parsed['type'] = 'letsencrypt';
                        $certs[] = $parsed;
                    }
                }
            }
        }

        return $certs;
    }

    public function parseCertificateFile(string $filePath): ?array
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return null;
        }

        $content = file_get_contents($filePath);
        $parsed = @openssl_x509_parse($content);
        if (!$parsed) {
            return null;
        }

        $validTo = $parsed['validTo_time_t'] ?? null;
        $now = time();
        $daysRemaining = $validTo ? (int)floor(($validTo - $now) / 86400) : null;

        $domains = [];
        if (!empty($parsed['subject']['CN'])) {
            $domains[] = $parsed['subject']['CN'];
        }
        if (!empty($parsed['extensions']['subjectAltName'])) {
            $sans = explode(',', $parsed['extensions']['subjectAltName']);
            foreach ($sans as $san) {
                $san = trim($san);
                if (str_starts_with($san, 'DNS:')) {
                    $domains[] = substr($san, 4);
                }
            }
        }

        return [
            'domains' => array_values(array_unique($domains)),
            'issuer' => $parsed['issuer']['O'] ?? ($parsed['issuer']['CN'] ?? 'Unknown'),
            'expiry' => $validTo ? date('Y-m-d H:i:s', $validTo) : null,
            'days_remaining' => $daysRemaining,
            'expired' => $daysRemaining !== null && $daysRemaining <= 0,
            'path' => $filePath
        ];
    }

    public function renewAll(): array
    {
        if (!$this->isCommandAvailable('certbot')) {
            return ['success' => false, 'code' => 127, 'stdout' => '', 'stderr' => 'certbot is not installed'];
        }
        return $this->executeCommand('certbot renew --non-interactive 2>&1');
    }

    public function getStatus(): array
    {
        $certs = $this->getCertificates();
        $installed = $this->isInstalled();
        $expiringSoon = 0;
        $expired = 0;

        foreach ($certs as $cert) {
            if (isset($cert['days_remaining'])) {
                if ($cert['days_remaining'] <= 0) {
                    $expired++;
                } elseif ($cert['days_remaining'] < 14) {
                    $expiringSoon++;
                }
            }
        }

        return [
            'service' => 'ssl',
            'label' => $this->getName(),
            'installed' => $installed,
            'certificates_count' => count($certs),
            'expiring_soon_count' => $expiringSoon,
            'expired_count' => $expired,
            'certificates' => $certs
        ];
    }

    public function start(): array
    {
        return ['success' => true, 'code' => 0, 'stdout' => 'SSL is managed via Certbot / OpenSSL', 'stderr' => ''];
    }

    public function stop(): array
    {
        return ['success' => true, 'code' => 0, 'stdout' => 'SSL service does not require stopping', 'stderr' => ''];
    }

    public function restart(): array
    {
        return $this->renewAll();
    }

    public function reload(): array
    {
        return $this->renewAll();
    }
}
