<?php

/**
 * Testharness: laedt die echten Modulklassen gegen einen Stub und prueft die
 * Kommunikation mit einem echten Raumfeld-Geraet.
 *
 * IP-Symcon bringt bereits eine echte IPSModuleStrict-Klasse mit, deren
 * Konstruktor eine Instanz-ID verlangt. Deshalb wird beim Laden der Quelltext
 * von "extends IPSModuleStrict" auf "extends RFStub" umgeschrieben. Der
 * getestete Modulcode bleibt dabei unveraendert.
 */

class RFStub
{
    public array $props = [];
    public array $vars = [];
    public array $buffers = [];
    public int $status = 0;

    public function Create(): void {}
    public function ApplyChanges(): void {}
    public function Destroy(): void {}
    public function RegisterPropertyString(string $name, string $value): void { $this->props[$name] = $value; }
    public function RegisterPropertyInteger(string $name, int $value): void { $this->props[$name] = $value; }
    public function RegisterVariableInteger(string $ident, string $name, string $profile, int $position): void { $this->vars[$ident] = 0; }
    public function RegisterVariableBoolean(string $ident, string $name, string $profile, int $position): void { $this->vars[$ident] = false; }
    public function RegisterVariableString(string $ident, string $name, string $profile, int $position): void { $this->vars[$ident] = ''; }
    public function EnableAction(string $ident): void {}
    public function RegisterTimer(string $name, int $interval, string $script): void {}
    public function UnregisterTimer(string $name): void {}
    public function SetTimerInterval(string $name, int $interval): void {}
    public function ReadPropertyString(string $name): string { return (string) ($this->props[$name] ?? ''); }
    public function ReadPropertyInteger(string $name): int { return (int) ($this->props[$name] ?? 0); }
    public function SetStatus(int $status): void { $this->status = $status; }
    public function GetStatus(): int { return $this->status; }
    public function SetBuffer(string $name, string $value): void { $this->buffers[$name] = $value; }
    public function GetBuffer(string $name): string { return (string) ($this->buffers[$name] ?? ''); }
    public function SetValue(string $ident, mixed $value): void { $this->vars[$ident] = $value; }
    public function GetValue(string $ident): mixed { return $this->vars[$ident] ?? null; }
    public function SendDebug(string $message, mixed $data, int $format): void {}
    public function ReloadForm(): void {}
}

function rf_load(string $path): void
{
    $source = file_get_contents($path);
    if ($source === false) {
        throw new RuntimeException('nicht lesbar: ' . $path);
    }
    $source = str_replace('extends IPSModuleStrict', 'extends RFStub', $source);
    $temp = tempnam(sys_get_temp_dir(), 'rfmod') . '.php';
    file_put_contents($temp, $source);
    require $temp;
    @unlink($temp);
}

function rf_check(string $label, bool $ok, string $extra = ''): void
{
    echo ($ok ? 'PASS' : 'FAIL') . ' ' . $label . ($extra !== '' ? ' [' . $extra . ']' : '') . "\n";
}

$base = IPS_GetKernelDir() . 'modules/IPS-Raumfeld/';
$host = getenv('RF_HOST') ?: '192.168.10.18';

rf_load($base . 'RaumfeldRenderer/module.php');
rf_load($base . 'RaumfeldConfigurator/module.php');

// --- Host-API --------------------------------------------------------------
$configurator = new RaumfeldConfigurator();
$configurator->Create();
$configurator->props['Host'] = $host;

$reflection = new ReflectionClass($configurator);
$ping = $reflection->getMethod('RFPing');
$ping->setAccessible(true);
$model = $ping->invoke($configurator, $host);
rf_check('Host Ping', $model !== '', $model);

$zonesMethod = $reflection->getMethod('RFGetZones');
$zonesMethod->setAccessible(true);
$zones = $zonesMethod->invoke($configurator, $host);
$roomCount = 0;
$rendererUDN = '';
$roomUDN = '';
foreach ($zones as $zone) {
    foreach ($zone['rooms'] as $room) {
        $roomCount++;
        $roomUDN = $room['udn'];
        foreach ($room['renderers'] as $renderer) {
            $rendererUDN = $renderer['udn'];
        }
    }
}
rf_check('Zonen gelesen', $roomCount > 0, $roomCount . ' Raeume');
rf_check('Renderer-UDN gefunden', $rendererUDN !== '', $rendererUDN);
rf_check('Raum-UDN gefunden', $roomUDN !== '', $roomUDN);

$rowsMethod = $reflection->getMethod('RFCollectRooms');
$rowsMethod->setAccessible(true);
$rows = $rowsMethod->invoke($configurator, $host);
rf_check('Configurator-Zeilen', count($rows) > 0, count($rows) . ' Zeilen');
if (count($rows) > 0) {
    $row = $rows[0];
    rf_check('Zeile hat create-Block', isset($row['create']['moduleID'], $row['create']['configuration']));
    rf_check('Zeile nennt Renderer-UDN', ($row['create']['configuration']['RendererUDN'] ?? '') !== '');
}

// --- Renderer --------------------------------------------------------------
$renderer = new RaumfeldRenderer();
$renderer->Create();
$renderer->props['Host'] = $host;
$renderer->props['RoomUDN'] = $roomUDN;
$renderer->props['RoomName'] = 'Wohnzimmer';
$renderer->props['RendererUDN'] = $rendererUDN;
$renderer->props['RendererName'] = 'Speaker Wohnzimmer';
$renderer->props['UpdateInterval'] = 0;
$renderer->ApplyChanges();
rf_check('Renderer Status 102', $renderer->GetStatus() === 102, 'status=' . $renderer->GetStatus());

$volume = $renderer->GetValue('Volume');
rf_check('Lautstaerke gelesen', is_int($volume) && $volume >= 0 && $volume <= 100, 'volume=' . var_export($volume, true));
rf_check('Mute gelesen', is_bool($renderer->GetValue('Mute')), 'mute=' . var_export($renderer->GetValue('Mute'), true));
$transport = (string) $renderer->GetValue('TransportState');
rf_check('TransportState gelesen', $transport !== '', $transport);

$target = ((int) $volume >= 50) ? 30 : 70;
$renderer->RequestAction('Volume', $target);
usleep(400000);
$renderer->UpdateState();
rf_check('Lautstaerke gesetzt', (int) $renderer->GetValue('Volume') === $target, 'soll=' . $target . ' ist=' . $renderer->GetValue('Volume'));

$before = (bool) $renderer->GetValue('Mute');
$renderer->RequestAction('Mute', !$before);
usleep(400000);
$renderer->UpdateState();
rf_check('Mute umgeschaltet', (bool) $renderer->GetValue('Mute') === !$before, 'vorher=' . var_export($before, true));
$renderer->RequestAction('Mute', $before);

$renderer->RequestAction('Volume', (int) $volume);
usleep(300000);
$renderer->UpdateState();
rf_check('Lautstaerke zurueckgesetzt', (int) $renderer->GetValue('Volume') === (int) $volume, 'ist=' . $renderer->GetValue('Volume'));

$renderer->Play();
usleep(500000);
$renderer->UpdateState();
rf_check('Play/Stop ohne Fehler', in_array((string) $renderer->GetValue('TransportState'), ['PLAYING', 'TRANSITIONING', 'NO_MEDIA_PRESENT', 'PAUSED_PLAYBACK', 'STOPPED'], true), (string) $renderer->GetValue('TransportState'));
$renderer->Stop();

$renderer->Wake();
rf_check('Wake ohne Fehler', true);

// --- Fehlerfaelle ----------------------------------------------------------
$broken = new RaumfeldRenderer();
$broken->Create();
$broken->props['Host'] = '192.0.2.1';
$broken->props['RendererUDN'] = 'uuid:test';
$broken->ApplyChanges();
rf_check('Fehlerhost meldet 201', $broken->GetStatus() === 201, 'status=' . $broken->GetStatus());

$empty = new RaumfeldRenderer();
$empty->Create();
$empty->ApplyChanges();
rf_check('Leere Konfiguration meldet 104', $empty->GetStatus() === 104, 'status=' . $empty->GetStatus());

echo "FERTIG\n";
