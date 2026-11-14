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
        // Ein/Aus als eigene Variable in der Instanz. Abschaltbar, weil die
        // Kombination Schalter + Helligkeitsregler von der Visu als Licht
        // erkannt wird und dann die Media-Player-Kachel verdraengt.
        $this->RegisterPropertyBoolean('PowerVariable', false);

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

        // Ein/Aus: an = leaveStandby, aus = enterManualStandby.
        // keep=false entfernt die Variable, was die Licht-Erkennung der Kachel
        // (Schalter + Helligkeitsregler) aufloest.
        $this->MaintainVariable('Power', 'Ein/Aus', VARIABLETYPE_BOOLEAN, '~Switch', 1, $this->ReadPropertyBoolean('PowerVariable'));
        // Media-Player-Kachel: Integer mit ~PlaybackPreviousNext und Variablenaktion
        $this->MaintainVariable('Playback', 'Wiedergabe', VARIABLETYPE_INTEGER, '~PlaybackPreviousNext', 5, true);
        $this->MaintainVariable('Volume', 'Lautstaerke', VARIABLETYPE_INTEGER, '~Intensity.100', 10, true);
        // ~Mute leitet in der Kachel die Verwendung "Stumm schalten" ab
        $this->MaintainVariable('Mute', 'Stumm', VARIABLETYPE_BOOLEAN, '~Mute', 20, true);
        $this->MaintainVariable('TransportState', 'Transportstatus', VARIABLETYPE_STRING, '', 30, true);
        $this->MaintainVariable('PowerState', 'Betriebszustand', VARIABLETYPE_STRING, '', 40, true);

        // Geraeteeinstellungen aus der Raumfeld-App (WAMP, Port 55555)
        $this->MaintainVariable('Leds', 'LEDs', VARIABLETYPE_BOOLEAN, '~Switch', 50, true);
        $this->MaintainVariable('NightMode', 'Nachtmodus', VARIABLETYPE_BOOLEAN, '~Switch', 55, true);
        $this->MaintainVariable('InputSource', 'Eingangsquelle', VARIABLETYPE_INTEGER, 'Raumfeld.InputSource', 60, true);
        $this->MaintainVariable('SubwooferLevel', 'Subwoofer-Pegel', VARIABLETYPE_INTEGER, 'Raumfeld.Subwoofer', 65, true);
        $this->MaintainVariable('StandbyDelay', 'Standby nach', VARIABLETYPE_INTEGER, 'Raumfeld.StandbyDelay', 70, true);

        $this->EnableAction('Power');
        $this->EnableAction('Playback');
        $this->EnableAction('Volume');
        $this->EnableAction('Mute');
        $this->EnableAction('Leds');
        $this->EnableAction('NightMode');
        $this->EnableAction('InputSource');
        $this->EnableAction('SubwooferLevel');
        $this->EnableAction('StandbyDelay');

        $this->RFCreateProfiles();

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

        // Timer auch bei nicht erreichbarem Renderer setzen: der Dienst des
        // Geraets setzt zeitweise aus (beobachtet), die Instanz soll sich dann
        // selbst erholen statt dauerhaft auf 201 zu bleiben.
        $interval = $this->ReadPropertyInteger('UpdateInterval');
        if ($interval > 0) {
            $this->SetTimerInterval('RFUpdate', $interval * 1000);
        }

        if (!$this->RFResolveRenderer()) {
            $this->SetStatus(201);
            return;
        }

        $this->SetStatus(IS_ACTIVE);

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
            case 'Leds':
                $this->RFWampWrite('led_brightness', (bool) $Value ? 100 : 0);
                break;
            case 'NightMode':
                $this->RFWampWrite('night_mode', (bool) $Value);
                break;
            case 'InputSource':
                $this->RFWampWrite('audio_input_source', (int) $Value);
                break;
            case 'SubwooferLevel':
                $this->RFWampWrite('subwoofer_volume_adjustment', (int) $Value);
                break;
            case 'StandbyDelay':
                $this->RFWampWrite('auto_standby_delay', (int) $Value);
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

        // Geraeteeinstellungen nur lesen, wenn das Geraet antwortet.
        if ($volume !== '' || $transport !== '') {
            $this->RFReadDeviceSettings();
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

    // ------------------------------------------------------------ WAMP-Einstellungen

    /**
     * Legt die eigenen Variablenprofile an (idempotent).
     */
    private function RFCreateProfiles(): void
    {
        if (!IPS_VariableProfileExists('Raumfeld.InputSource')) {
            IPS_CreateVariableProfile('Raumfeld.InputSource', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileAssociation('Raumfeld.InputSource', 0, 'Teufel Streaming', '', -1);
            IPS_SetVariableProfileAssociation('Raumfeld.InputSource', 1, 'Analog', '', -1);
            IPS_SetVariableProfileAssociation('Raumfeld.InputSource', 2, 'Optisch', '', -1);
            IPS_SetVariableProfileAssociation('Raumfeld.InputSource', 3, 'TV', '', -1);
        }

        if (!IPS_VariableProfileExists('Raumfeld.Subwoofer')) {
            IPS_CreateVariableProfile('Raumfeld.Subwoofer', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileValues('Raumfeld.Subwoofer', -10, 10, 1);
            IPS_SetVariableProfileText('Raumfeld.Subwoofer', '', ' dB');
        }

        if (!IPS_VariableProfileExists('Raumfeld.StandbyDelay')) {
            IPS_CreateVariableProfile('Raumfeld.StandbyDelay', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileValues('Raumfeld.StandbyDelay', 0, 7200, 60);
            IPS_SetVariableProfileText('Raumfeld.StandbyDelay', '', ' s');
        }
    }

    private function RFWampTopic(string $Setting): string
    {
        $udn = $this->ReadPropertyString('RendererUDN');
        $safe = str_replace([':', '-'], '_', $udn);
        return 'com.raumfeld.devices.' . $safe . '.settings.' . $Setting;
    }

    private function RFWampOpen(): ?RaumfeldWamp
    {
        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '') {
            return null;
        }
        $client = new RaumfeldWamp();
        if (!$client->connect($host)) {
            $this->SendDebug('WAMP', 'Verbindung zu ' . $host . ':55555 fehlgeschlagen', 0);
            return null;
        }
        return $client;
    }

    private function RFWampWrite(string $Setting, $Value): void
    {
        $client = $this->RFWampOpen();
        if ($client === null) {
            return;
        }
        try {
            $client->set($this->RFWampTopic($Setting), $Value);
        } finally {
            $client->close();
        }
        $this->SendDebug('WAMP', 'set ' . $Setting . ' = ' . json_encode($Value), 0);
    }

    /**
     * Liest die Geraeteeinstellungen ueber eine gemeinsame WAMP-Verbindung.
     */
    private function RFReadDeviceSettings(): void
    {
        $client = $this->RFWampOpen();
        if ($client === null) {
            return;
        }

        try {
            $led = $client->get($this->RFWampTopic('led_brightness'));
            if ($led !== null) {
                $this->SetValue('Leds', (int) $led > 0);
            }

            $night = $client->get($this->RFWampTopic('night_mode'));
            if ($night !== null) {
                $this->SetValue('NightMode', (bool) $night);
            }

            $input = $client->get($this->RFWampTopic('audio_input_source'));
            if ($input !== null) {
                $this->SetValue('InputSource', (int) $input);
            }

            $sub = $client->get($this->RFWampTopic('subwoofer_volume_adjustment'));
            if ($sub !== null) {
                $this->SetValue('SubwooferLevel', (int) $sub);
            }

            $delay = $client->get($this->RFWampTopic('auto_standby_delay'));
            if ($delay !== null) {
                $this->SetValue('StandbyDelay', (int) $delay);
            }
        } finally {
            $client->close();
        }
    }

    /**
     * Loest die Renderer-Adresse auf. Die UDN eines Raumfeld-Renderers kann
     * sich aendern (beobachtet nach Zonenwechsel: 23ac20e8 -> da9fbf4a).
     * Deshalb drei Stufen: exakte UDN, Renderer des konfigurierten Raums,
     * einziger MediaRenderer. Gefundene UDN wird als Property uebernommen.
     */
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

        $devices = [];
        $renderers = [];
        foreach ($doc->xpath('//*[local-name()="device"]') ?: [] as $device) {
            $deviceUDN = (string) $device['udn'];
            $location = (string) $device['location'];
            if ($deviceUDN === '' || $location === '') {
                continue;
            }
            $devices[$deviceUDN] = $location;
            if (strpos((string) $device['type'], 'MediaRenderer') !== false) {
                $renderers[$deviceUDN] = $location;
            }
        }

        // 1. Exakte UDN
        if (isset($devices[$udn])) {
            $this->WriteAttributeString('RendererLocation', $devices[$udn]);
            return true;
        }

        // 2. Renderer des konfigurierten Raums
        $room = $this->ReadPropertyString('RoomUDN');
        if ($room !== '') {
            $zoneXML = $this->RFHostGet('/' . bin2hex(random_bytes(8)) . '/getZones');
            $zoneDoc = $this->RFLoadXML($zoneXML);
            if ($zoneDoc !== null) {
                foreach ($zoneDoc->xpath('//*[local-name()="room"]') ?: [] as $roomNode) {
                    if ((string) $roomNode['udn'] !== $room) {
                        continue;
                    }
                    foreach ($roomNode->xpath('.//*[local-name()="renderer"]') ?: [] as $rendererNode) {
                        $candidate = (string) $rendererNode['udn'];
                        if (!isset($devices[$candidate])) {
                            continue;
                        }
                        $this->RFAdoptRenderer($candidate, $devices[$candidate], (string) $rendererNode['name']);
                        return true;
                    }
                }
            }
        }

        // 3. Genau ein MediaRenderer vorhanden
        if (count($renderers) === 1) {
            $onlyUDN = array_key_first($renderers);
            $this->RFAdoptRenderer($onlyUDN, $renderers[$onlyUDN], '');
            return true;
        }

        $this->SendDebug('Renderer', 'UDN ' . $udn . ' nicht in listDevices von ' . $host, 0);
        return false;
    }

    /**
     * Uebernimmt eine neu gefundene Renderer-UDN in die Konfiguration.
     * Das Schreiben loest ApplyChanges erneut aus; der zweite Durchlauf findet
     * die UDN dann exakt und terminiert.
     */
    private function RFAdoptRenderer(string $UDN, string $Location, string $Name): void
    {
        $this->WriteAttributeString('RendererLocation', $Location);
        if ($this->ReadPropertyString('RendererUDN') === $UDN) {
            return;
        }
        $this->SendDebug('Renderer', 'UDN uebernommen: ' . $UDN, 0);
        IPS_SetProperty($this->InstanceID, 'RendererUDN', $UDN);
        if ($Name !== '' && $this->ReadPropertyString('RendererName') !== $Name) {
            IPS_SetProperty($this->InstanceID, 'RendererName', $Name);
        }
        IPS_ApplyChanges($this->InstanceID);
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

/**
 * Minimaler WAMP-v2-JSON-Client fuer den Raumfeld-Host (Port 55555).
 *
 * Der Host bietet neben der Web-API und UPnP einen WAMP-Broker an. Darueber
 * liegen die Geraeteeinstellungen, die in der Raumfeld-App sichtbar sind
 * (LED-Helligkeit, Nachtmodus, Eingangsquelle, Subwoofer, Standby-Zeit).
 *
 * Protokoll:
 *   HELLO     [1, "raumfeld", {...}]         -> WELCOME [2, id, {...}]
 *   CALL      [48, req, {}, topic, [args]]   -> RESULT  [50, req, {}, [wert], {}]
 *                                            -> ERROR   [8, 48, req, {}, fehler]
 *
 * WebSocket wird von Hand gesprochen: Handshake, maskierte Client-Frames,
 * unverschluesselte Server-Frames. PHP bringt keinen WS-Client mit.
 */
class RaumfeldWamp
{
    private $socket = null;
    private string $buffer = '';
    private int $requestId = 0;

    public function connect(string $Host, int $Port = 55555, int $Timeout = 5): bool
    {
        $this->socket = @stream_socket_client(
            'tcp://' . $Host . ':' . $Port,
            $errno,
            $errstr,
            $Timeout
        );
        if ($this->socket === false) {
            return false;
        }
        stream_set_timeout($this->socket, $Timeout);

        $key = base64_encode(random_bytes(16));
        $request = 'GET / HTTP/1.1' . "\r\n"
            . 'Host: ' . $Host . ':' . $Port . "\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . 'Sec-WebSocket-Key: ' . $key . "\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "Sec-WebSocket-Protocol: wamp.2.json\r\n"
            . 'Origin: http://' . $Host . ':47365' . "\r\n"
            . "\r\n";
        fwrite($this->socket, $request);

        $response = '';
        $deadline = microtime(true) + $Timeout;
        while (strpos($response, "\r\n\r\n") === false && microtime(true) < $deadline) {
            $chunk = fread($this->socket, 1024);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $response .= $chunk;
        }
        if (strpos($response, ' 101 ') === false) {
            $this->close();
            return false;
        }

        $split = strpos($response, "\r\n\r\n");
        $this->buffer = substr($response, $split + 4);

        $hello = [1, 'raumfeld', [
            'agent' => 'ips-raumfeld',
            'roles' => [
                'subscriber' => new stdClass(),
                'caller' => ['features' => ['call_timeout' => true]],
            ],
        ]];
        if (!$this->send($hello)) {
            $this->close();
            return false;
        }
        $welcome = $this->receive($Timeout);
        if (!is_array($welcome) || ($welcome[0] ?? 0) !== 2) {
            $this->close();
            return false;
        }
        return true;
    }

    public function close(): void
    {
        if ($this->socket !== null) {
            @fclose($this->socket);
            $this->socket = null;
        }
        $this->buffer = '';
    }

    /**
     * Ruft ein Thema auf. Liefert den ersten Ergebniswert oder null.
     */
    public function call(string $Topic, array $Args = ['get'], int $Timeout = 8)
    {
        $this->requestId++;
        $request = $this->requestId;
        if (!$this->send([48, $request, ['timeout' => 1800], $Topic, $Args])) {
            return null;
        }

        $deadline = microtime(true) + $Timeout;
        while (microtime(true) < $deadline) {
            $message = $this->receive($Timeout);
            if ($message === null) {
                continue;
            }
            if (($message[0] ?? 0) === 50 && ($message[1] ?? 0) === $request) {
                return $message[3][0] ?? null;
            }
            if (($message[0] ?? 0) === 8 && ($message[2] ?? 0) === $request) {
                return null;
            }
        }
        return null;
    }

    public function get(string $Topic)
    {
        return $this->call($Topic, ['get']);
    }

    public function set(string $Topic, $Value)
    {
        return $this->call($Topic, ['set', $Value]);
    }

    // ------------------------------------------------------------ Frames

    private function send(array $Payload): bool
    {
        if ($this->socket === null) {
            return false;
        }
        $data = json_encode($Payload);
        if ($data === false) {
            return false;
        }
        $length = strlen($data);

        $frame = chr(0x81);
        if ($length < 126) {
            $frame .= chr(0x80 | $length);
        } elseif ($length < 65536) {
            $frame .= chr(0x80 | 126) . pack('n', $length);
        } else {
            $frame .= chr(0x80 | 127) . pack('J', $length);
        }

        $mask = random_bytes(4);
        $frame .= $mask;
        for ($i = 0; $i < $length; $i++) {
            $frame .= $data[$i] ^ $mask[$i % 4];
        }

        return @fwrite($this->socket, $frame) !== false;
    }

    private function receive(int $Timeout): ?array
    {
        $deadline = microtime(true) + $Timeout;
        while (microtime(true) < $deadline) {
            $frame = $this->readFrame($deadline);
            if ($frame === null) {
                return null;
            }
            $opcode = $frame['opcode'];
            if ($opcode === 0x8) {
                return null;
            }
            if ($opcode === 0x9) {
                $this->sendControl(0xA, $frame['payload']);
                continue;
            }
            if ($opcode === 0x1 || $opcode === 0x2) {
                $decoded = json_decode($frame['payload'], true);
                return is_array($decoded) ? $decoded : null;
            }
        }
        return null;
    }

    private function sendControl(int $Opcode, string $Payload): void
    {
        if ($this->socket === null) {
            return;
        }
        $length = strlen($Payload);
        $frame = chr(0x80 | $Opcode) . chr(0x80 | $length);
        $mask = random_bytes(4);
        $frame .= $mask;
        for ($i = 0; $i < $length; $i++) {
            $frame .= $Payload[$i] ^ $mask[$i % 4];
        }
        @fwrite($this->socket, $frame);
    }

    private function readFrame(float $Deadline): ?array
    {
        while (strlen($this->buffer) < 2) {
            if (!$this->fill($Deadline)) {
                return null;
            }
        }

        $first = ord($this->buffer[0]);
        $second = ord($this->buffer[1]);
        $opcode = $first & 0x0F;
        $masked = ($second & 0x80) !== 0;
        $length = $second & 0x7F;
        $offset = 2;

        if ($length === 126) {
            while (strlen($this->buffer) < 4) {
                if (!$this->fill($Deadline)) {
                    return null;
                }
            }
            $length = unpack('n', substr($this->buffer, 2, 2))[1];
            $offset = 4;
        } elseif ($length === 127) {
            while (strlen($this->buffer) < 10) {
                if (!$this->fill($Deadline)) {
                    return null;
                }
            }
            $length = unpack('J', substr($this->buffer, 2, 8))[1];
            $offset = 10;
        }

        $maskKey = '';
        if ($masked) {
            while (strlen($this->buffer) < $offset + 4) {
                if (!$this->fill($Deadline)) {
                    return null;
                }
            }
            $maskKey = substr($this->buffer, $offset, 4);
            $offset += 4;
        }

        while (strlen($this->buffer) < $offset + $length) {
            if (!$this->fill($Deadline)) {
                return null;
            }
        }

        $payload = substr($this->buffer, $offset, $length);
        $this->buffer = substr($this->buffer, $offset + $length);

        if ($masked) {
            for ($i = 0; $i < $length; $i++) {
                $payload[$i] = $payload[$i] ^ $maskKey[$i % 4];
            }
        }

        return ['opcode' => $opcode, 'payload' => $payload];
    }

    private function fill(float $Deadline): bool
    {
        if ($this->socket === null) {
            return false;
        }
        $remaining = $Deadline - microtime(true);
        if ($remaining <= 0) {
            return false;
        }
        $seconds = (int) floor($remaining);
        $micros = (int) (($remaining - $seconds) * 1000000);
        stream_set_timeout($this->socket, $seconds, $micros);
        $chunk = @fread($this->socket, 8192);
        if ($chunk === false || $chunk === '') {
            $meta = stream_get_meta_data($this->socket);
            if (!empty($meta['timed_out'])) {
                return false;
            }
            return false;
        }
        $this->buffer .= $chunk;
        return true;
    }
}
