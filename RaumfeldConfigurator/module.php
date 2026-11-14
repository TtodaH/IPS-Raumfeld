<?php

declare(strict_types=1);

/**
 * Raumfeld Configurator
 *
 * Verbindet sich mit dem Raumfeld-Host (Port 47365), liest Geraete- und
 * Zonenliste und legt fuer jeden Raum eine Renderer-Instanz an.
 */
class RaumfeldConfigurator extends IPSModuleStrict
{
    private const HOST_PORT = 47365;
    private const RENDERER_MODULE = '{A7B3C1D2-4E5F-4A6B-8C9D-0E1F2A3B4C63}';

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('UpdateInterval', 0);
        $this->RegisterTimer('RFRefresh', 0, 'RFC_Refresh($_IPS[\'TARGET\']);');
    }

    public function Destroy(): void
    {
        $this->UnregisterTimer('RFRefresh');
        parent::Destroy();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->SetTimerInterval('RFRefresh', 0);

        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '') {
            $this->SetStatus(104);
            return;
        }

        $model = $this->RFPing($host);
        if ($model === '') {
            $this->SetStatus(201);
            return;
        }

        $this->SetStatus(102);

        $interval = $this->ReadPropertyInteger('UpdateInterval');
        if ($interval > 0) {
            $this->SetTimerInterval('RFRefresh', $interval * 1000);
        }
    }

    public function GetConfigurationForm(): string
    {
        $host = trim($this->ReadPropertyString('Host'));
        $model = ($host === '') ? '' : $this->RFPing($host);

        $info = ($host === '')
            ? 'Bitte die IP des Raumfeld-Hosts eintragen (Port 47365).'
            : (($model === '') ? 'Host ' . $host . ' antwortet nicht auf Port 47365.' : 'Verbunden mit: ' . $model);

        $values = [];
        if ($model !== '') {
            $values = $this->RFCollectRooms($host);
        }

        return json_encode([
            'elements' => [
                ['type' => 'Label', 'label' => $info],
                [
                    'type' => 'ExpansionPanel',
                    'caption' => 'Host',
                    'items' => [
                        [
                            'type' => 'ValidationTextBox',
                            'name' => 'Host',
                            'caption' => 'IP-Adresse',
                            'validate' => '^([0-9]{1,3}\\.){3}[0-9]{1,3}$',
                        ],
                        [
                            'type' => 'NumberSpinner',
                            'name' => 'UpdateInterval',
                            'caption' => 'Automatische Aktualisierung (Sekunden, 0 = aus)',
                            'minimum' => 0,
                            'maximum' => 3600,
                        ],
                    ],
                ],
            ],
            'actions' => [
                [
                    'type' => 'Button',
                    'label' => 'Geraeteliste neu laden',
                    'onClick' => 'IPS_ApplyChanges($id);',
                ],
            ],
            'values' => $values,
            'columns' => [
                ['name' => 'Zone', 'caption' => 'Zone', 'width' => '150px'],
                ['name' => 'Raum', 'caption' => 'Raum', 'width' => '150px'],
                ['name' => 'Renderer', 'caption' => 'Renderer', 'width' => 'auto'],
                ['name' => 'UDN', 'caption' => 'UDN', 'width' => '260px'],
            ],
            'status' => [
                ['code' => 104, 'icon' => 'inactive', 'caption' => 'Kein Host konfiguriert'],
                ['code' => 201, 'icon' => 'error', 'caption' => 'Host nicht erreichbar'],
            ],
        ]) ?: '{}';
    }

    /**
     * Liefert die Konfigurationsliste fuer den Configurator.
     * Wird von GetConfigurationForm als 'values' benoetigt.
     */
    public function GetConfigurationValues(): string
    {
        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '') {
            return '[]';
        }
        return json_encode($this->RFCollectRooms($host)) ?: '[]';
    }

    public function Refresh(): void
    {
        $this->ReloadForm();
    }

    // ------------------------------------------------------------ intern

    private function RFCollectRooms(string $Host): array
    {
        $devices = $this->RFListDevices($Host);
        $zones = $this->RFGetZones($Host);

        $rows = [];
        foreach ($zones as $zone) {
            foreach ($zone['rooms'] as $room) {
                foreach ($room['renderers'] as $renderer) {
                    $rows[] = $this->RFRow(
                        $Host,
                        $zone['name'],
                        $room['udn'],
                        $room['name'],
                        $renderer['udn'],
                        $renderer['name'],
                        $devices[$renderer['udn']] ?? ''
                    );
                }
            }
        }

        return $rows;
    }

    private function RFRow(string $Host, string $ZoneName, string $RoomUDN, string $RoomName, string $RendererUDN, string $RendererName, string $Location): array
    {
        $instanceID = $this->RFInstanceForRenderer($RendererUDN);
        $row = [
            'Zone' => $ZoneName,
            'Raum' => $RoomName,
            'Renderer' => $RendererName,
            'UDN' => $RendererUDN,
            'create' => [
                'moduleID' => self::RENDERER_MODULE,
                'name' => ($RoomName !== '' ? $RoomName : $RendererName),
                'configuration' => [
                    'Host' => $Host,
                    'RoomUDN' => $RoomUDN,
                    'RoomName' => $RoomName,
                    'RendererUDN' => $RendererUDN,
                    'RendererName' => $RendererName,
                    'UpdateInterval' => 0,
                ],
            ],
        ];

        if ($instanceID > 0) {
            $row['instanceID'] = $instanceID;
        }

        return $row;
    }

    private function RFInstanceForRenderer(string $RendererUDN): int
    {
        foreach (IPS_GetInstanceListByModuleID(self::RENDERER_MODULE) as $instanceID) {
            $configuration = IPS_GetConfiguration($instanceID);
            $data = json_decode($configuration, true);
            if (is_array($data) && ($data['RendererUDN'] ?? '') === $RendererUDN) {
                return (int) $instanceID;
            }
        }
        return 0;
    }

    private function RFSession(string $Host, string $Path): string
    {
        $url = 'http://' . $Host . ':' . self::HOST_PORT . '/' . bin2hex(random_bytes(8)) . $Path;
        $result = @Sys_GetURLContentEx($url, [
            'Timeout' => 5000,
            'Headers' => "User-Agent: IPS-Raumfeld\r\n",
        ]);
        return ($result === false) ? '' : (string) $result;
    }

    private function RFListDevices(string $Host): array
    {
        $xml = $this->RFSession($Host, '/listDevices');
        $devices = [];
        $doc = $this->RFLoadXML($xml);
        if ($doc === null) {
            return $devices;
        }
        foreach ($doc->xpath('//*[local-name()="device"]') ?: [] as $device) {
            $devices[(string) $device['udn']] = (string) $device['location'];
        }
        return $devices;
    }

    private function RFGetZones(string $Host): array
    {
        $xml = $this->RFSession($Host, '/getZones');
        $zones = [];
        $doc = $this->RFLoadXML($xml);
        if ($doc === null) {
            return $zones;
        }

        foreach ($doc->xpath('//*[local-name()="zone"]') ?: [] as $zoneNode) {
            $zone = ['udn' => (string) $zoneNode['udn'], 'name' => '', 'rooms' => []];
            foreach ($zoneNode->xpath('.//*[local-name()="room"]') ?: [] as $roomNode) {
                $zone['rooms'][] = $this->RFRoomNode($roomNode);
            }
            $zones[] = $zone;
        }

        // Nicht zugeordnete Räume als eigene Pseudo-Zone
        foreach ($doc->xpath('//*[local-name()="unassignedRooms"]') ?: [] as $unassigned) {
            $zone = ['udn' => '', 'name' => 'Nicht zugeordnet', 'rooms' => []];
            foreach ($unassigned->xpath('.//*[local-name()="room"]') ?: [] as $roomNode) {
                $zone['rooms'][] = $this->RFRoomNode($roomNode);
            }
            if (count($zone['rooms']) > 0) {
                $zones[] = $zone;
            }
        }

        return $zones;
    }

    private function RFRoomNode($roomNode): array
    {
        $room = [
            'udn' => (string) $roomNode['udn'],
            'name' => (string) $roomNode['name'],
            'renderers' => [],
        ];
        foreach ($roomNode->xpath('.//*[local-name()="renderer"]') ?: [] as $rendererNode) {
            $room['renderers'][] = [
                'udn' => (string) $rendererNode['udn'],
                'name' => (string) $rendererNode['name'],
            ];
        }
        return $room;
    }

    private function RFLoadXML(string $XML)
    {
        if ($XML === '') {
            return null;
        }
        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($XML);
        libxml_use_internal_errors($prev);
        return ($doc === false) ? null : $doc;
    }

    private function RFPing(string $Host): string
    {
        $xml = @Sys_GetURLContentEx('http://' . $Host . ':' . self::HOST_PORT . '/Ping', ['Timeout' => 3000]);
        if ($xml === false || $xml === '') {
            return '';
        }
        $doc = $this->RFLoadXML((string) $xml);
        if ($doc === null) {
            return '';
        }
        $nodes = $doc->xpath('//*[local-name()="modelName"]');
        return (is_array($nodes) && count($nodes) > 0) ? trim((string) $nodes[0]) : 'Raumfeld';
    }
}
