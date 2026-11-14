<?php

declare(strict_types=1);

/**
 * Raumfeld Renderer
 *
 * Steuert einen Raum (Lautsprecher) eines Teufel Raumfeld Systems.
 * Spricht den Renderer direkt per UPnP/SOAP an und den Host per HTTP-API.
 *
 * Wichtig: Der SOAP-Port des Renderers ist dynamisch und aendert sich nach
 * jedem Standby/Neustart des Geraets. Das Modul loest die Adresse deshalb
 * bei jedem Fehler neu auf und kann das Geraet per Wake-on-LAN wecken.
 *
 * Variablen:
 *   Volume          Lautstaerke 0..100 (steuerbar)
 *   Mute            Stummschaltung (steuerbar)
 *   TransportState  Wiedergabestatus (nur lesend)
 *   PowerState      Betriebszustand des Raums (nur lesend)
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
        $this->RegisterPropertyString('MacAddress', '');
        $this->RegisterPropertyInteger('UpdateInterval', 0);

        $this->RegisterAttributeString('RendererLocation', '');

        $this->RegisterTimer('RFUpdate', 0, 'RFR_UpdateState($_IPS[\'TARGET\']);');
    }

    public function Destroy(): void
    {
        $this->SetTimerInterval('RFUpdate', 0);
        parent::Destroy();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->SetTimerInterval('RFUpdate', 0);
        $this->WriteAttributeString('RendererLocation', '');

        // Ein/Aus: an = leaveStandby, aus = enterManualStandby
        $this->MaintainVariable('Power', 'Ein/Aus', VARIABLETYPE_BOOLEAN, '~Switch', 1, true);
        // Media-Player-Kachel: Integer mit ~PlaybackPreviousNext und Variablenaktion
        $this->MaintainVariable('Playback', 'Wiedergabe', VARIABLETYPE_INTEGER, '~PlaybackPreviousNext', 5, true);
        $this->MaintainVariable('Volume', 'Lautstaerke', VARIABLETYPE_INTEGER, '~Intensity.100', 10, true);
        // ~Mute leitet in der Kachel die Verwendung "Stumm schalten" ab
        $this->MaintainVariable('Mute', 'Stumm', VARIABLETYPE_BOOLEAN, '~Mute', 20, true);
        $this->MaintainVariable('TransportState', 'Transportstatus', VARIABLETYPE_STRING, '', 30, true);
        $this->MaintainVariable('PowerState', 'Betriebszustand', VARIABLETYPE_STRING, '', 40, true);

        $this->EnableAction('Power');
        $this->EnableAction('Playback');
        $this->EnableAction('Volume');
        $this->EnableAction('Mute');

        // Namen explizit setzen: MaintainVariable benennt bestehende Variablen
        // nicht immer um, was sonst zu zwei Variablen "Wiedergabe" fuehrt.
        $names = [
            'Playback' => 'Wiedergabe',
            'Volume' => 'Lautstaerke',
            'Mute' => 'Stumm',
            'TransportState' => 'Transportstatus',
            'PowerState' => 'Betriebszustand',
        ];
        foreach ($names as $ident => $name) {
            $variableID = @$this->GetIDForIdent($ident);
            if ($variableID > 0 && IPS_GetName($variableID) !== $name) {
                IPS_SetName($variableID, $name);
            }
        }

        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '') {
            $this->SetStatus(104);
            return;
        }

        // Raum und Renderer automatisch ermitteln, wenn nur die IP gesetzt ist.
        // Das Schreiben der Properties loest ApplyChanges erneut aus; beim
        // zweiten Durchlauf sind die Felder gefuellt und die Erkennung entfaellt.
        if ($this->ReadPropertyString('RoomUDN') === '' || $this->ReadPropertyString('RendererUDN') === '') {
            if ($this->RFAutoDetect($host)) {
                return;
            }
            $this->SetStatus(104);
            return;
        }

        if (!$this->RFResolveRenderer()) {
            $this->SetStatus(201);
            return;
        }

        $this->SetStatus(IS_ACTIVE);

        $interval = $this->ReadPropertyInteger('UpdateInterval');
        if ($interval > 0) {
            $this->SetTimerInterval('RFUpdate', $interval * 1000);
        }

        $this->RFUpdateState();
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'Power':
                if ((bool) $Value) {
                    $this->Wake();
                } else {
                    $this->Standby();
                }
                break;
            case 'Playback':
                $this->RFRSetPlayback((int) $Value);
                break;
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

    public function Reconnect(): void
    {
        $this->WriteAttributeString('RendererLocation', '');
        if ($this->RFResolveRenderer()) {
            $this->SetStatus(IS_ACTIVE);
            $this->RFUpdateState();
        } else {
            $this->SetStatus(201);
        }
    }

    public function Play(): void
    {
        $this->RFSoapAV('Play', ['InstanceID' => 0, 'Speed' => 1]);
        $this->RFUpdateState();
    }

    public function Pause(): void
    {
        $this->RFSoapAV('Pause', ['InstanceID' => 0]);
        $this->RFUpdateState();
    }

    public function Stop(): void
    {
        $this->RFSoapAV('Stop', ['InstanceID' => 0]);
        $this->RFUpdateState();
    }

    public function Next(): void
    {
        $this->RFSoapAV('Next', ['InstanceID' => 0]);
        $this->RFUpdateState();
    }

    public function Previous(): void
    {
        $this->RFSoapAV('Previous', ['InstanceID' => 0]);
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
        if ($room !== '') {
            $this->RFHostGet('/leaveStandby?roomUDN=' . rawurlencode($room));
        }

        if ($this->RFPingHost(trim($this->ReadPropertyString('Host'))) === '') {
            $this->WakeOnLan();
            usleep(2000000);
        }

        // Das Geraet braucht einen Moment, bis powerState auf ACTIVE wechselt.
        usleep(1500000);

        $this->Reconnect();
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

    public function WakeOnLan(): void
    {
        $mac = strtoupper(str_replace([':', '-'], '', trim($this->ReadPropertyString('MacAddress'))));
        if (strlen($mac) !== 12 || !ctype_xdigit($mac)) {
            $this->SendDebug('WOL', 'Keine gueltige MAC-Adresse konfiguriert', 0);
            return;
        }

        $packet = str_repeat(chr(255), 6);
        for ($i = 0; $i < 6; $i++) {
            $packet .= chr((int) hexdec(substr($mac, $i * 2, 2)));
        }
        $packet = str_repeat($packet, 16);

        $socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($socket === false) {
            $this->SendDebug('WOL', 'socket_create fehlgeschlagen', 0);
            return;
        }
        @socket_set_option($socket, SOL_SOCKET, SO_BROADCAST, 1);
        foreach (['255.255.255.255', '192.168.10.255'] as $target) {
            foreach ([9, 7] as $port) {
                @socket_sendto($socket, $packet, strlen($packet), 0, $target, $port);
            }
        }
        @socket_close($socket);
        $this->SendDebug('WOL', 'Magic Packet an ' . $mac . ' gesendet', 0);
    }

    // ------------------------------------------------------------ intern

    private function RFUpdateState(): void
    {
        if ($this->GetStatus() === 104) {
            return;
        }

        $volume = $this->RFXmlValue($this->RFSoapRC('GetVolume', ['InstanceID' => 0, 'Channel' => 'Master']), 'CurrentVolume');
        if ($volume !== '') {
            $this->SetValue('Volume', (int) $volume);
        }

        $mute = $this->RFXmlValue($this->RFSoapRC('GetMute', ['InstanceID' => 0, 'Channel' => 'Master']), 'CurrentMute');
        if ($mute !== '') {
            $this->SetValue('Mute', $mute === '1');
        }

        $transport = $this->RFXmlValue($this->RFSoapAV('GetTransportInfo', ['InstanceID' => 0]), 'CurrentTransportState');
        if ($transport !== '') {
            $this->SetValue('TransportState', $transport);
            $this->SetValue('Playback', $this->RFPlaybackFromTransport($transport));
        }

        // Selbstheilung: antwortet das Geraet, ist ein alter Fehlerstatus hinfaellig.
        if ($volume !== '' || $mute !== '' || $transport !== '') {
            if ($this->GetStatus() === 201) {
                $this->SetStatus(IS_ACTIVE);
            }
        }

        $power = $this->RFRoomPowerState();
        if ($power !== '') {
            $this->SetValue('PowerState', $power);
            $this->SetValue('Power', $power === 'ACTIVE');
            if ($power !== 'ACTIVE') {
                $this->SetStatus(202);
            } elseif ($this->GetStatus() === 202) {
                $this->SetStatus(IS_ACTIVE);
            }
        }
    }

    private function RFRoomPowerState(): string
    {
        $room = $this->ReadPropertyString('RoomUDN');
        if ($room === '') {
            return '';
        }
        $xml = $this->RFHostGet('/' . bin2hex(random_bytes(8)) . '/getZones');
        if ($xml === '') {
            return '';
        }
        $doc = $this->RFLoadXML($xml);
        if ($doc === null) {
            return '';
        }
        foreach ($doc->xpath('//*[local-name()="room"]') ?: [] as $node) {
            if ((string) $node['udn'] === $room) {
                return (string) $node['powerState'];
            }
        }
        return '';
    }

    /**
     * Bildet den UPnP-Transportzustand auf das Profil ~PlaybackPreviousNext ab.
     * 0 = Zurueck, 1 = Stop, 2 = Play, 3 = Pause, 4 = Weiter
     */
    private function RFPlaybackFromTransport(string $Transport): int
    {
        switch ($Transport) {
            case 'PLAYING':
            case 'TRANSITIONING':
                return 2;
            case 'PAUSED_PLAYBACK':
                return 3;
            case 'STOPPED':
            case 'NO_MEDIA_PRESENT':
            default:
                return 1;
        }
    }

    private function RFRSetPlayback(int $Value): void
    {
        switch ($Value) {
            case 0:
                $this->Previous();
                break;
            case 1:
                $this->Stop();
                break;
            case 2:
                $this->Play();
                break;
            case 3:
                $this->Pause();
                break;
            case 4:
                $this->Next();
                break;
            default:
                $this->SendDebug('Playback', 'Unbekannter Wert ' . $Value, 0);
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

    /**
     * Ermittelt Raum und Renderer vom Host, wenn nur die IP bekannt ist.
     * Bei mehreren Räumen wird der erste genommen. Gefundene Werte werden als
     * Property geschrieben; das loest ApplyChanges erneut aus, terminiert aber,
     * weil die Felder danach gefuellt sind.
     */
    private function RFAutoDetect(string $Host): bool
    {
        $xml = $this->RFHostGet('/' . bin2hex(random_bytes(8)) . '/getZones');
        $doc = $this->RFLoadXML($xml);
        if ($doc === null) {
            $this->SendDebug('AutoDetect', 'getZones nicht lesbar', 0);
            return false;
        }

        $roomUDN = '';
        $roomName = '';
        $rendererUDN = '';
        $rendererName = '';

        foreach ($doc->xpath('//*[local-name()="room"]') ?: [] as $room) {
            $renderers = $room->xpath('.//*[local-name()="renderer"]') ?: [];
            if (count($renderers) === 0) {
                continue;
            }
            $roomUDN = (string) $room['udn'];
            $roomName = (string) $room['name'];
            $rendererUDN = (string) $renderers[0]['udn'];
            $rendererName = (string) $renderers[0]['name'];
            break;
        }

        if ($roomUDN === '' || $rendererUDN === '') {
            $this->SendDebug('AutoDetect', 'kein Raum mit Renderer gefunden', 0);
            return false;
        }

        $this->SendDebug('AutoDetect', 'Raum ' . $roomName . ', Renderer ' . $rendererName, 0);

        IPS_SetProperty($this->InstanceID, 'RoomUDN', $roomUDN);
        IPS_SetProperty($this->InstanceID, 'RoomName', $roomName);
        IPS_SetProperty($this->InstanceID, 'RendererUDN', $rendererUDN);
        IPS_SetProperty($this->InstanceID, 'RendererName', $rendererName);
        IPS_ApplyChanges($this->InstanceID);
        return true;
    }

    private function RFResolveRenderer(): bool
    {
        $host = trim($this->ReadPropertyString('Host'));
        $udn = $this->ReadPropertyString('RendererUDN');

        $xml = $this->RFHostGet('/' . bin2hex(random_bytes(8)) . '/listDevices');
        if ($xml === '') {
            return false;
        }

        $doc = $this->RFLoadXML($xml);
        if ($doc === null) {
            return false;
        }

        foreach ($doc->xpath('//*[local-name()="device"]') ?: [] as $device) {
            if ((string) $device['udn'] !== $udn) {
                continue;
            }
            $location = (string) $device['location'];
            if ($location === '') {
                continue;
            }
            $this->WriteAttributeString('RendererLocation', $location);
            return true;
        }

        $this->SendDebug('Renderer', 'UDN ' . $udn . ' nicht in listDevices von ' . $host, 0);
        return false;
    }

    private function RFBaseURL(): string
    {
        $location = $this->ReadAttributeString('RendererLocation');
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
        return $this->RFSoapWithRecovery('/RenderingControl/ctrl', self::SERVICE_RC, $Action, $Args);
    }

    private function RFSoapAV(string $Action, array $Args): string
    {
        return $this->RFSoapWithRecovery('/AVTransport/ctrl', self::SERVICE_AV, $Action, $Args);
    }

    /**
     * Fuehrt einen SOAP-Aufruf aus. Schlaegt er fehl, wird die Renderer-Adresse
     * neu aufgeloest (dynamischer Port) und der Aufruf einmal wiederholt.
     */
    private function RFSoapWithRecovery(string $Path, string $Service, string $Action, array $Args): string
    {
        $base = $this->RFBaseURL();
        if ($base === '') {
            if (!$this->RFResolveRenderer()) {
                return '';
            }
            $base = $this->RFBaseURL();
            if ($base === '') {
                return '';
            }
        }

        $result = $this->RFSoap($base . $Path, $Service, $Action, $Args);
        if ($result !== '') {
            return $result;
        }

        if (!$this->RFResolveRenderer()) {
            return '';
        }
        $base = $this->RFBaseURL();
        if ($base === '') {
            return '';
        }

        return $this->RFSoap($base . $Path, $Service, $Action, $Args);
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
        if ($Host === '') {
            return '';
        }
        $xml = @Sys_GetURLContentEx('http://' . $Host . ':' . self::HOST_PORT . '/Ping', ['Timeout' => 3000]);
        if ($xml === false || $xml === '') {
            return '';
        }
        $model = $this->RFXmlValue((string) $xml, 'modelName');
        return ($model === '') ? 'erreichbar' : $model;
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

    private function RFXmlValue(string $XML, string $Tag): string
    {
        $doc = $this->RFLoadXML($XML);
        if ($doc === null) {
            return '';
        }
        $nodes = $doc->xpath('//*[local-name()="' . $Tag . '"]');
        if (!is_array($nodes) || count($nodes) === 0) {
            return '';
        }
        return trim((string) $nodes[0]);
    }
}
