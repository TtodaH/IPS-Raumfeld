<?php

declare(strict_types=1);

/**
 * Raumfeld Renderer
 *
 * Steuert einen Raum (Lautsprecher) eines Teufel Raumfeld Systems.
 * Spricht den Renderer direkt per UPnP/SOAP an und den Host per HTTP-API.
 *
 * Variablen:
 *   Volume          Lautstaerke 0..100 (steuerbar)
 *   Mute            Stummschaltung (steuerbar)
 *   TransportState  Wiedergabestatus (nur lesend)
 */
class RaumfeldRenderer extends IPSModuleStrict
{
    private const SERVICE_RC = 'urn:schemas-upnp-org:service:RenderingControl:1';
    private const SERVICE_AV = 'urn:schemas-upnp-org:service:AVTransport:1';
    private const HOST_PORT = 47365;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyString('RoomUDN', '');
        $this->RegisterPropertyString('RoomName', '');
        $this->RegisterPropertyString('RendererUDN', '');
        $this->RegisterPropertyString('RendererName', '');
        $this->RegisterPropertyInteger('UpdateInterval', 0);

        $this->RegisterVariableInteger('Volume', 'Lautstaerke', '~Intensity.100', 10);
        $this->EnableAction('Volume');
        $this->RegisterVariableBoolean('Mute', 'Stumm', '~Switch', 20);
        $this->EnableAction('Mute');
        $this->RegisterVariableString('TransportState', 'Wiedergabe', '', 30);

        $this->RegisterTimer('RFUpdate', 0, 'RFR_UpdateState($_IPS[\'TARGET\']);');
    }

    public function Destroy(): void
    {
        $this->UnregisterTimer('RFUpdate');
        parent::Destroy();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->SetTimerInterval('RFUpdate', 0);
        $this->SetBuffer('RendererLocation', '');

        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '' || $this->ReadPropertyString('RendererUDN') === '') {
            $this->SetStatus(104);
            return;
        }

        if (!$this->RFResolveRenderer()) {
            $this->SetStatus(201);
            return;
        }

        $this->SetStatus(102);

        $interval = $this->ReadPropertyInteger('UpdateInterval');
        if ($interval > 0) {
            $this->SetTimerInterval('RFUpdate', $interval * 1000);
        }

        $this->RFUpdateState();
    }

    public function GetConfigurationForm(): string
    {
        $host = trim($this->ReadPropertyString('Host'));
        $info = '';
        if ($host !== '') {
            $ping = $this->RFPingHost($host);
            $info = ($ping === '') ? 'Host ' . $host . ' antwortet nicht.' : 'Host: ' . $host . ' (' . $ping . ')';
        }

        return json_encode([
            'elements' => [
                [
                    'type' => 'Label',
                    'label' => $info,
                ],
                [
                    'type' => 'ExpansionPanel',
                    'caption' => 'Geraet',
                    'items' => [
                        [
                            'type' => 'ValidationTextBox',
                            'name' => 'Host',
                            'caption' => 'Host / IP',
                            'validate' => '^([0-9]{1,3}\\.){3}[0-9]{1,3}$',
                        ],
                        [
                            'type' => 'ValidationTextBox',
                            'name' => 'RoomUDN',
                            'caption' => 'Raum-UDN',
                        ],
                        [
                            'type' => 'ValidationTextBox',
                            'name' => 'RoomName',
                            'caption' => 'Raumname',
                        ],
                        [
                            'type' => 'ValidationTextBox',
                            'name' => 'RendererUDN',
                            'caption' => 'Renderer-UDN',
                        ],
                        [
                            'type' => 'ValidationTextBox',
                            'name' => 'RendererName',
                            'caption' => 'Renderername',
                        ],
                        [
                            'type' => 'NumberSpinner',
                            'name' => 'UpdateInterval',
                            'caption' => 'Statusabfrage (Sekunden, 0 = aus)',
                            'minimum' => 0,
                            'maximum' => 3600,
                        ],
                    ],
                ],
            ],
            'actions' => [
                [
                    'type' => 'Button',
                    'label' => 'Status jetzt aktualisieren',
                    'onClick' => 'RFR_UpdateState($id);',
                ],
                [
                    'type' => 'Button',
                    'label' => 'Raum aufwecken',
                    'onClick' => 'RFR_Wake($id);',
                ],
                [
                    'type' => 'Button',
                    'label' => 'Raum in Standby',
                    'onClick' => 'RFR_Standby($id);',
                ],
            ],
            'status' => [
                [
                    'code' => 104,
                    'icon' => 'inactive',
                    'caption' => 'Host oder Renderer nicht konfiguriert',
                ],
                [
                    'code' => 201,
                    'icon' => 'error',
                    'caption' => 'Renderer nicht erreichbar',
                ],
            ],
        ]);
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'Volume':
                $this->RFRSetVolume((int) $Value);
                break;
            case 'Mute':
                $this->RFRSetMute((bool) $Value);
                break;
            default:
                throw new Exception('Ungueltige Aktion: ' . $Ident);
        }
    }

    // ------------------------------------------------------------ oeffentliche Funktionen

    public function UpdateState(): void
    {
        $this->RFUpdateState();
    }

    public function Play(): void
    {
        $this->RFSoapAV('Play', ['InstanceID' => 0, 'Speed' => 1]);
        $this->ReloadForm();
        $this->RFUpdateState();
    }

    public function Pause(): void
    {
        $this->RFSoapAV('Pause', ['InstanceID' => 0]);
        $this->ReloadForm();
        $this->RFUpdateState();
    }

    public function Stop(): void
    {
        $this->RFSoapAV('Stop', ['InstanceID' => 0]);
        $this->ReloadForm();
        $this->RFUpdateState();
    }

    public function Next(): void
    {
        $this->RFSoapAV('Next', ['InstanceID' => 0]);
        $this->ReloadForm();
        $this->RFUpdateState();
    }

    public function Previous(): void
    {
        $this->RFSoapAV('Previous', ['InstanceID' => 0]);
        $this->ReloadForm();
        $this->RFUpdateState();
    }

    public function TogglePlayPause(): void
    {
        if ($this->GetValue('TransportState') === 'PLAYING') {
            $this->Pause();
        } else {
            $this->Play();
        }
    }

    public function Wake(): void
    {
        $room = $this->ReadPropertyString('RoomUDN');
        if ($room === '') {
            return;
        }
        $this->RFHostGet('/leaveStandby?roomUDN=' . rawurlencode($room));
        $this->RFUpdateState();
    }

    public function Standby(): void
    {
        $room = $this->ReadPropertyString('RoomUDN');
        if ($room === '') {
            return;
        }
        $this->RFHostGet('/enterManualStandby?roomUDN=' . rawurlencode($room));
        $this->RFUpdateState();
    }

    // ------------------------------------------------------------ intern

    private function RFUpdateState(): void
    {
        if ($this->GetStatus() !== 102) {
            return;
        }
        $vol = $this->RFXmlValue($this->RFSoapRC('GetVolume', ['InstanceID' => 0, 'Channel' => 'Master']), 'CurrentVolume');
        if ($vol !== '') {
            $this->SetValue('Volume', (int) $vol);
        }
        $mute = $this->RFXmlValue($this->RFSoapRC('GetMute', ['InstanceID' => 0, 'Channel' => 'Master']), 'CurrentMute');
        if ($mute !== '') {
            $this->SetValue('Mute', $mute === '1');
        }
        $state = $this->RFXmlValue($this->RFSoapAV('GetTransportInfo', ['InstanceID' => 0]), 'CurrentTransportState');
        if ($state !== '') {
            $this->SetValue('TransportState', $state);
        }
    }

    private function RFRSetVolume(int $Volume): void
    {
        $Volume = max(0, min(100, $Volume));
        $this->RFSoapRC('SetVolume', ['InstanceID' => 0, 'Channel' => 'Master', 'DesiredVolume' => $Volume]);
        $this->SetValue('Volume', $Volume);
    }

    private function RFRSetMute(bool $Mute): void
    {
        $this->RFSoapRC('SetMute', ['InstanceID' => 0, 'Channel' => 'Master', 'DesiredMute' => $Mute ? 1 : 0]);
        $this->SetValue('Mute', $Mute);
    }

    private function RFResolveRenderer(): bool
    {
        $host = trim($this->ReadPropertyString('Host'));
        $udn = $this->ReadPropertyString('RendererUDN');

        $xml = $this->RFHostGet('/' . bin2hex(random_bytes(8)) . '/listDevices');
        if ($xml === '') {
            return false;
        }

        $location = '';
        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        libxml_use_internal_errors($prev);
        if ($doc !== false) {
            foreach ($doc->xpath('//*[local-name()="device"]') ?: [] as $device) {
                if ((string) $device['udn'] === $udn) {
                    $location = (string) $device['location'];
                    break;
                }
            }
        }

        if ($location === '') {
            $this->SendDebug('Renderer', 'UDN ' . $udn . ' nicht in listDevices von ' . $host, 0);
            return false;
        }

        $this->SetBuffer('RendererLocation', $location);
        return true;
    }

    private function RFBaseURL(): string
    {
        $location = $this->GetBuffer('RendererLocation');
        if ($location === '') {
            return '';
        }
        $parts = parse_url($location);
        if (!isset($parts['scheme'], $parts['host'])) {
            return '';
        }
        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    private function RFSoapRC(string $Action, array $Args): string
    {
        $base = $this->RFBaseURL();
        if ($base === '') {
            return '';
        }
        return $this->RFSoap($base . '/RenderingControl/ctrl', self::SERVICE_RC, $Action, $Args);
    }

    private function RFSoapAV(string $Action, array $Args): string
    {
        $base = $this->RFBaseURL();
        if ($base === '') {
            return '';
        }
        return $this->RFSoap($base . '/AVTransport/ctrl', self::SERVICE_AV, $Action, $Args);
    }

    private function RFSoap(string $URL, string $Service, string $Action, array $Args): string
    {
        $argXML = '';
        foreach ($Args as $key => $value) {
            $argXML .= '<' . $key . '>' . htmlspecialchars((string) $value, ENT_XML1) . '</' . $key . '>';
        }
        $body = '<?xml version="1.0"?>'
            . '<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" '
            . 's:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">'
            . '<s:Body><u:' . $Action . ' xmlns:u="' . $Service . '">' . $argXML . '</u:' . $Action . '></s:Body>'
            . '</s:Envelope>';

        $ch = curl_init($URL);
        if ($ch === false) {
            return '';
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => [
                'Content-Type: text/xml; charset=utf-8',
                'SOAPACTION: "' . $Service . '#' . $Action . '"',
            ],
        ]);
        $result = curl_exec($ch);
        if ($result === false) {
            $this->SendDebug('SOAP', $Action . ': ' . curl_error($ch), 0);
            $result = '';
        }
        curl_close($ch);
        return (string) $result;
    }

    private function RFHostGet(string $Path): string
    {
        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '') {
            return '';
        }
        $url = 'http://' . $host . ':' . self::HOST_PORT . $Path;
        $result = @Sys_GetURLContentEx($url, [
            'Timeout' => 5000,
            'Headers' => "User-Agent: IPS-Raumfeld\r\n",
        ]);
        return ($result === false) ? '' : (string) $result;
    }

    private function RFPingHost(string $Host): string
    {
        $xml = @Sys_GetURLContentEx('http://' . $Host . ':' . self::HOST_PORT . '/Ping', ['Timeout' => 3000]);
        if ($xml === false || $xml === '') {
            return '';
        }
        $model = $this->RFXmlValue((string) $xml, 'modelName');
        return ($model === '') ? 'erreichbar' : $model;
    }

    private function RFXmlValue(string $XML, string $Tag): string
    {
        if ($XML === '') {
            return '';
        }
        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($XML);
        libxml_use_internal_errors($prev);
        if ($doc === false) {
            return '';
        }
        $nodes = $doc->xpath('//*[local-name()="' . $Tag . '"]');
        if (!is_array($nodes) || count($nodes) === 0) {
            return '';
        }
        return trim((string) $nodes[0]);
    }
}
