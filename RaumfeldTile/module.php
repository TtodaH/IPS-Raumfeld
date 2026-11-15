<?php

declare(strict_types=1);

if (!defined('VM_UPDATE')) {
    define('VM_UPDATE', 10603);
}

/**
 * Raumfeld Tile
 *
 * Eigene Kachel fuer die Kachel-Visualisierung (HTML-SDK, IP-Symcon ab 7.1).
 * Zeigt Ein/Aus, Lautstaerke, Eingangsquelle, Stummschaltung und
 * Wiedergabetasten in einer Kachel und steuert eine Renderer-Instanz.
 *
 * Warum eine eigene Kachel: Die Media-Player-Darstellung zeigt Lautstaerke und
 * Stumm nur mit dem USAGE-Marker, den diese IP-Symcon-Version nicht akzeptiert.
 * Variable und Dimmer fallen auf unpassende Darstellungen zurueck.
 */
class RaumfeldTile extends IPSModule
{
    private const RENDERER_MODULE = '{A7B3C1D2-4E5F-4A6B-8C9D-0E1F2A3B4C63}';

    public function Create(): void
    {
        parent::Create();

        // HTML-SDK der Kachelvisualisierung aktivieren (1 = HTML)
        $this->SetVisualizationType(1);

        $this->RegisterPropertyInteger('RendererInstance', 0);
        $this->RegisterPropertyString('TileTitle', 'Raumfeld');
        $this->RegisterPropertyString('TileSubtitle', '');
        $this->RegisterPropertyInteger('AccentColor', 0x00CDAB);
        $this->RegisterPropertyBoolean('ShowVolume', true);
        $this->RegisterPropertyBoolean('ShowSource', true);
        $this->RegisterPropertyBoolean('ShowMute', true);
        $this->RegisterPropertyBoolean('ShowPlayback', true);
    }

    public function Destroy(): void
    {
        parent::Destroy();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->SetVisualizationType(1);

        foreach ($this->GetMessageList() as $senderID => $messageIDs) {
            foreach ($messageIDs as $messageID) {
                $this->UnregisterMessage($senderID, $messageID);
            }
        }
        foreach ($this->GetReferenceList() as $refID) {
            $this->UnregisterReference($refID);
        }

        $rendererID = $this->ReadPropertyInteger('RendererInstance');
        if ($rendererID <= 0 || !@IPS_InstanceExists($rendererID)) {
            $this->SetStatus(104);
            return;
        }

        $info = IPS_GetInstance($rendererID);
        if (($info['ModuleInfo']['ModuleID'] ?? '') !== self::RENDERER_MODULE) {
            $this->SetStatus(201);
            return;
        }

        $this->RegisterReference($rendererID);
        foreach ($this->RFSourceVariables($rendererID) as $variableID) {
            $this->RegisterMessage($variableID, VM_UPDATE);
        }

        $this->SetStatus(102);
        $this->SendTileUpdate();
    }

    public function GetVisualizationTile(): string
    {
        $htmlFile = __DIR__ . '/module.html';
        if (!file_exists($htmlFile)) {
            return '<div style="color:red;padding:10px;">module.html fehlt</div>';
        }

        $html = file_get_contents($htmlFile);
        $data = json_encode($this->GetVisualizationData(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $bootstrap = '<script>window.__INITIAL_DATA__ = ' . $data . ';'
            . ' if (typeof handleMessage === "function") { handleMessage(window.__INITIAL_DATA__); }</script>';

        return str_replace('</body>', $bootstrap . '</body>', $html);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data): void
    {
        if ($Message === VM_UPDATE) {
            $this->SendTileUpdate();
        }
    }

    public function RequestAction($Ident, $Value): void
    {
        $rendererID = $this->ReadPropertyInteger('RendererInstance');
        if ($rendererID <= 0 || !@IPS_InstanceExists($rendererID)) {
            return;
        }

        switch ($Ident) {
            case 'power':
                IPS_RequestAction($rendererID, 'Power', (bool) $Value);
                break;
            case 'volume':
                IPS_RequestAction($rendererID, 'Volume', (int) $Value);
                break;
            case 'source':
                IPS_RequestAction($rendererID, 'InputSource', (int) $Value);
                break;
            case 'mute':
                IPS_RequestAction($rendererID, 'Mute', (bool) $Value);
                break;
            case 'playback':
                IPS_RequestAction($rendererID, 'Playback', (int) $Value);
                break;
            case 'refresh':
                break;
            default:
                $this->SendDebug('RequestAction', 'Unbekannter Ident: ' . $Ident, 0);
                break;
        }

        // Kurz warten, damit das Geraet den Zustand uebernehmen konnte.
        usleep(400000);
        $this->SendTileUpdate();
    }

    public function RefreshTile(): void
    {
        $this->SendTileUpdate();
    }

    private function SendTileUpdate(): void
    {
        $data = json_encode($this->GetVisualizationData(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->UpdateVisualizationValue($data);
    }

    /**
     * Variablen der Renderer-Instanz, deren Aenderung die Kachel aktualisiert.
     */
    private function RFSourceVariables(int $rendererID): array
    {
        $idents = ['Power', 'Playback', 'Volume', 'Mute', 'TransportState', 'PowerState', 'InputSource'];
        $result = [];
        foreach ($idents as $ident) {
            $variableID = @IPS_GetObjectIDByIdent($ident, $rendererID);
            if ($variableID > 0) {
                $result[] = $variableID;
            }
        }
        return $result;
    }

    private function RFValue(int $rendererID, string $ident, $default = null)
    {
        $variableID = @IPS_GetObjectIDByIdent($ident, $rendererID);
        if ($variableID <= 0) {
            return $default;
        }
        return GetValue($variableID);
    }

    private function GetVisualizationData(): array
    {
        $rendererID = $this->ReadPropertyInteger('RendererInstance');
        $accent = sprintf('#%06X', $this->ReadPropertyInteger('AccentColor'));

        $data = [
            'title' => $this->ReadPropertyString('TileTitle'),
            'subtitle' => $this->ReadPropertyString('TileSubtitle'),
            'accentColor' => $accent,
            'showVolume' => $this->ReadPropertyBoolean('ShowVolume'),
            'showSource' => $this->ReadPropertyBoolean('ShowSource'),
            'showMute' => $this->ReadPropertyBoolean('ShowMute'),
            'showPlayback' => $this->ReadPropertyBoolean('ShowPlayback'),
            'available' => false,
            'status' => 'Keine Renderer-Instanz',
        ];

        if ($rendererID <= 0 || !@IPS_InstanceExists($rendererID)) {
            return $data;
        }

        $powerState = (string) $this->RFValue($rendererID, 'PowerState', '');
        $transport = (string) $this->RFValue($rendererID, 'TransportState', '');
        $source = (int) $this->RFValue($rendererID, 'InputSource', 0);

        $sourceNames = [0 => 'Teufel Streaming', 1 => 'Analog', 2 => 'Optisch', 3 => 'TV'];

        $data['available'] = true;
        $data['power'] = $powerState === 'ACTIVE';
        $data['powerState'] = $powerState;
        $data['volume'] = (int) $this->RFValue($rendererID, 'Volume', 0);
        $data['mute'] = (bool) $this->RFValue($rendererID, 'Mute', false);
        $data['source'] = $source;
        $data['sourceName'] = $sourceNames[$source] ?? ('Unbekannt (' . $source . ')');
        $data['sources'] = $sourceNames;
        $data['playback'] = (int) $this->RFValue($rendererID, 'Playback', 1);
        $data['transport'] = $transport;
        $data['status'] = $powerState === 'ACTIVE'
            ? ($transport === 'NO_MEDIA_PRESENT' ? 'Bereit' : $transport)
            : 'Standby';

        if ($data['subtitle'] === '') {
            $config = json_decode(IPS_GetConfiguration($rendererID) ?: '{}', true);
            if (is_array($config) && ($config['RoomName'] ?? '') !== '') {
                $data['subtitle'] = $config['RoomName'];
            }
        }

        return $data;
    }
}
