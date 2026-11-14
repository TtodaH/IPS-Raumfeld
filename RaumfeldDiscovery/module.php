<?php

declare(strict_types=1);

/**
 * Raumfeld Discovery
 *
 * Sucht Teufel Raumfeld Hosts im lokalen Netz per SSDP (Multicast) und
 * erlaubt zusaetzlich die manuelle Eingabe einer IP-Adresse.
 *
 * Gefundene Hosts koennen direkt als Configurator-Instanz angelegt werden.
 */
class RaumfeldDiscovery extends IPSModuleStrict
{
    private const CONFIGURATOR_MODULE = '{A7B3C1D2-4E5F-4A6B-8C9D-0E1F2A3B4C62}';
    private const SSDP_ADDRESS = '239.255.255.250';
    private const SSDP_PORT = 1900;
    private const ST_CONFIG_DEVICE = 'urn:schemas-raumfeld-com:device:ConfigDevice:1';

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyString('ManualHost', '');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->SetStatus(102);
    }

    public function GetConfigurationForm(): string
    {
        $hosts = $this->RFSearchHosts(3);

        $values = [];
        foreach ($hosts as $ip => $model) {
            $values[] = [
                'IP' => $ip,
                'Modell' => $model,
                'Status' => 'gefunden',
                'instanceID' => $this->RFInstanceForHost($ip),
                'create' => [
                    'moduleID' => self::CONFIGURATOR_MODULE,
                    'name' => 'Raumfeld ' . $ip,
                    'configuration' => ['Host' => $ip, 'UpdateInterval' => 0],
                ],
            ];
        }

        $manual = trim($this->ReadPropertyString('ManualHost'));
        if ($manual !== '' && !isset($hosts[$manual])) {
            $model = $this->RFPing($manual);
            $values[] = [
                'IP' => $manual,
                'Modell' => ($model === '' ? 'unbekannt' : $model),
                'Status' => ($model === '' ? 'nicht erreichbar' : 'manuell'),
                'instanceID' => $this->RFInstanceForHost($manual),
                'create' => [
                    'moduleID' => self::CONFIGURATOR_MODULE,
                    'name' => 'Raumfeld ' . $manual,
                    'configuration' => ['Host' => $manual, 'UpdateInterval' => 0],
                ],
            ];
        }

        return json_encode([
            'elements' => [
                [
                    'type' => 'Label',
                    'label' => 'Sucht Raumfeld-Hosts per SSDP. Falls Multicast im Netz blockiert ist, IP manuell eintragen und neu laden.',
                ],
                [
                    'type' => 'ValidationTextBox',
                    'name' => 'ManualHost',
                    'caption' => 'IP-Adresse manuell',
                    'validate' => '^$|^([0-9]{1,3}\\.){3}[0-9]{1,3}$',
                ],
            ],
            'actions' => [
                [
                    'type' => 'Button',
                    'label' => 'Erneut suchen',
                    'onClick' => 'IPS_ApplyChanges($id);',
                ],
            ],
            'values' => $values,
            'columns' => [
                ['name' => 'IP', 'caption' => 'IP-Adresse', 'width' => '160px'],
                ['name' => 'Modell', 'caption' => 'Modell', 'width' => 'auto'],
                ['name' => 'Status', 'caption' => 'Status', 'width' => '140px'],
            ],
        ]) ?: '{}';
    }

    // ------------------------------------------------------------ intern

    /**
     * Sendet ein SSDP M-SEARCH und sammelt Antworten.
     * Rueckgabe: [ip => modellname]
     */
    private function RFSearchHosts(int $TimeoutSeconds): array
    {
        $hosts = [];

        $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($socket === false) {
            $this->SendDebug('SSDP', 'socket_create fehlgeschlagen', 0);
            return $hosts;
        }

        @socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
        @socket_set_option($socket, IPPROTO_IP, IP_MULTICAST_TTL, 2);
        @socket_set_nonblock($socket);

        $message = "M-SEARCH * HTTP/1.1\r\n"
            . 'HOST: ' . self::SSDP_ADDRESS . ':' . self::SSDP_PORT . "\r\n"
            . "MAN: \"ssdp:discover\"\r\n"
            . 'ST: ' . self::ST_CONFIG_DEVICE . "\r\n"
            . "MX: 2\r\n\r\n";

        @socket_sendto($socket, $message, strlen($message), 0, self::SSDP_ADDRESS, self::SSDP_PORT);

        $deadline = microtime(true) + $TimeoutSeconds;
        while (microtime(true) < $deadline) {
            $buffer = '';
            $from = '';
            $port = 0;
            $read = [$socket];
            $write = null;
            $except = null;
            $ready = @socket_select($read, $write, $except, 0, 200000);
            if ($ready === false || $ready === 0) {
                continue;
            }
            $bytes = @socket_recvfrom($socket, $buffer, 2048, 0, $from, $port);
            if ($bytes === false || $bytes <= 0) {
                continue;
            }
            $location = $this->RFHeader($buffer, 'location');
            if ($location === '') {
                continue;
            }
            $parts = parse_url($location);
            $ip = $parts['host'] ?? '';
            if ($ip === '' || isset($hosts[$ip])) {
                continue;
            }
            $model = $this->RFPing($ip);
            $hosts[$ip] = ($model === '') ? 'Raumfeld' : $model;
        }

        @socket_close($socket);

        // Zusaetzlich: bereits konfigurierte Hosts immer anzeigen
        foreach (IPS_GetInstanceListByModuleID(self::CONFIGURATOR_MODULE) as $instanceID) {
            $data = json_decode(IPS_GetConfiguration($instanceID), true);
            $ip = is_array($data) ? trim((string) ($data['Host'] ?? '')) : '';
            if ($ip !== '' && !isset($hosts[$ip])) {
                $model = $this->RFPing($ip);
                $hosts[$ip] = ($model === '') ? 'konfiguriert' : $model;
            }
        }

        return $hosts;
    }

    private function RFHeader(string $Response, string $Name): string
    {
        foreach (preg_split('/\r\n/', $Response) ?: [] as $line) {
            $position = strpos($line, ':');
            if ($position === false) {
                continue;
            }
            if (strtolower(trim(substr($line, 0, $position))) === $Name) {
                return trim(substr($line, $position + 1));
            }
        }
        return '';
    }

    private function RFInstanceForHost(string $IP): int
    {
        foreach (IPS_GetInstanceListByModuleID(self::CONFIGURATOR_MODULE) as $instanceID) {
            $data = json_decode(IPS_GetConfiguration($instanceID), true);
            if (is_array($data) && trim((string) ($data['Host'] ?? '')) === $IP) {
                return (int) $instanceID;
            }
        }
        return 0;
    }

    private function RFPing(string $Host): string
    {
        $xml = @Sys_GetURLContentEx('http://' . $Host . ':47365/Ping', ['Timeout' => 2000]);
        if ($xml === false || $xml === '') {
            return '';
        }
        if (preg_match('#<modelName>([^<]+)</modelName>#', (string) $xml, $matches)) {
            return trim($matches[1]);
        }
        return 'Raumfeld';
    }
}
