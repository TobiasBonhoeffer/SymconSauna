<?php

declare(strict_types=1);

/*
 * Sauna – Symcon-Gerät für die ESPHome-Saunasteuerung (WEKA-Bus)
 *
 * Empfängt  <Präfix>/state  (JSON mit allen Werten) und verteilt es auf Variablen.
 * Schaltbare Variablen senden Änderungen als JSON an  <Präfix>/set.
 */
class Sauna extends IPSModule
{
    private const DATA_TO_PARENT = '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}';

    // ident => [Name, Typ (0 Bool, 1 Integer, 3 String), Profil, schaltbar, Position]
    private const FELDER = [
        'status'           => ['Status',            3, '',              false,  1],
        'ist'              => ['Ist-Temperatur',    1, 'Sauna.Grad',    false,  2],
        'soll'             => ['Solltemperatur',    1, 'Sauna.Soll',    true,   3],
        'dauer'            => ['Dauer',             1, 'Sauna.Dauer',   true,   4],
        'restzeit'         => ['Restzeit',          1, 'Sauna.Minuten', false,  5],
        'laufzeit'         => ['Laufzeit',          1, 'Sauna.Minuten', false,  6],
        'licht'            => ['Licht',             0, '~Switch',       true,   7],
        'aktion'           => ['Aktion',            1, 'Sauna.Aktion',  true,   8],
        'master'           => ['Master-Betrieb',    0, '~Switch',       true,   9],
        'phase'            => ['Phase',             3, '',              false, 10],
        'ofen'             => ['Ofen',              3, '',              false, 11],
        'verdampfer'       => ['Verdampfer',        3, '',              false, 12],
        'verdampfer_heizt' => ['Verdampfer heizt',  0, '~Switch',       false, 13],
        'feuchtestufe'     => ['Feuchtestufe',      1, '',              false, 14],
        'kurzzeit'         => ['Kurzzeit Restzeit', 1, 'Sauna.Minuten', false, 15],
        'start_erlaubt'    => ['Start erlaubt',     0, '~Switch',       false, 16],
        'betriebsstunden'  => ['Betriebsstunden',   1, 'Sauna.Stunden', false, 17],
        'sitzungen'        => ['Sitzungen gesamt',  1, '',              false, 18],
        'fehler'           => ['Busfehler',         1, '',              false, 19],
        'modus'            => ['Modus',             1, 'Sauna.Modus',   true,  20],
        'feuchte_soll'     => ['Feuchte (Soll)',    1, 'Sauna.Feuchte', true,  21],
        'kurzzeit_soll'    => ['Kurzzeitbetrieb',   0, '~Switch',       true,  22],
        'kommunikation'    => ['Kommunikation Ofenmodul', 0, '~Switch', true,  23],
        'pause_s'          => ['Kommunikation angehalten seit', 1, 'Sauna.Sekunden', false, 24],
    ];

    private const MODI = [0 => 'finnisch', 1 => 'bio', 2 => 'dampf'];

    // Werte der Variable "Aktion" (ein Sitzungsstart ist per MQTT bewusst nicht vorgesehen)
    private const AKTIONEN = [1 => 'standby', 2 => 'bereit', 3 => 'beenden'];

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('Topic', 'sauna');
        $this->RegisterAttributeString('Favoriten', '[{"soll":90,"dauer":15},{"soll":70,"dauer":10}]');
        // eigene Kachel in der Kachel-Visualisierung (HTML-SDK)
        $this->SetVisualizationType(1);
    }

    // ---------- Kachel ----------
    public function GetVisualizationTile()
    {
        $html = file_get_contents(__DIR__ . '/module.html');
        return $html . '<script>handleMessage(' . json_encode($this->vollstaendig()) . ');</script>';
    }

    private function vollstaendig(): string
    {
        $d = [];
        foreach (self::FELDER as $ident => $f) {
            $id = @$this->GetIDForIdent($ident);
            if ($id) {
                $d[$ident] = GetValue($id);
            }
        }
        $d['favoriten'] = json_decode($this->ReadAttributeString('Favoriten'), true) ?: [];
        return json_encode($d);
    }

    private function kachelAktualisieren()
    {
        $this->UpdateVisualizationValue($this->vollstaendig());
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->profile();

        foreach (self::FELDER as $ident => $f) {
            switch ($f[1]) {
                case 0: $this->RegisterVariableBoolean($ident, $f[0], $f[2], $f[4]); break;
                case 1: $this->RegisterVariableInteger($ident, $f[0], $f[2], $f[4]); break;
                default: $this->RegisterVariableString($ident, $f[0], $f[2], $f[4]);
            }
            if ($f[3]) {
                $this->EnableAction($ident);
            } else {
                $this->DisableAction($ident);
            }
        }

        // nur Nachrichten zu unserem Präfix empfangen (Schrägstrich ggf. als \/ maskiert)
        $prefix = preg_quote(trim($this->ReadPropertyString('Topic'), '/'));
        $this->SetReceiveDataFilter('.*"Topic":"' . $prefix . '(\\\\)?\/state".*');
    }

    // ---------- Status von der Sauna ----------
    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data) || !isset($data['Topic'], $data['Payload'])) {
            return '';
        }
        if ($data['Topic'] !== $this->topic('state')) {
            return '';
        }

        $payload = (string) $data['Payload'];
        $werte = json_decode($payload, true);

        // manche Symcon-Versionen liefern die Nutzdaten hex-kodiert
        if (!is_array($werte) && ctype_xdigit($payload) && strlen($payload) % 2 == 0) {
            $werte = json_decode((string) hex2bin($payload), true);
            if (is_array($werte)) {
                $this->SetBuffer('hex', '1');
            }
        }
        // ... oder mit falscher Zeichenkodierung
        if (!is_array($werte)) {
            $werte = json_decode(mb_convert_encoding($payload, 'UTF-8', 'ISO-8859-1'), true);
        }
        if (!is_array($werte)) {
            $this->SendDebug('Empfang', 'Kein gültiges JSON: ' . $payload, 0);
            return '';
        }
        $this->SendDebug('Empfang', json_encode($werte), 0);

        foreach (self::FELDER as $ident => $f) {
            if ($ident == 'aktion' || !array_key_exists($ident, $werte) || $werte[$ident] === null) {
                continue;
            }
            if ($ident == 'modus') {
                $m = array_search((string) $werte[$ident], self::MODI, true);
                if ($m === false) {
                    continue;
                }
                $v = (int) $m;
            } else {
                switch ($f[1]) {
                    case 0: $v = (bool) $werte[$ident]; break;
                    case 1: $v = (int) $werte[$ident]; break;
                    default: $v = (string) $werte[$ident];
                }
            }
            if ($this->GetValue($ident) !== $v) {
                $this->SetValue($ident, $v);
            }
        }
        $this->kachelAktualisieren();
        return '';
    }

    // ---------- Bedienung in Symcon ----------
    public function RequestAction($Ident, $Value)
    {
        // Favoriten (nur von der Kachel)
        if ($Ident == 'fav_apply' || $Ident == 'fav_add' || $Ident == 'fav_del') {
            $fav = json_decode($this->ReadAttributeString('Favoriten'), true) ?: [];
            if ($Ident == 'fav_apply' && isset($fav[(int) $Value])) {
                $f = $fav[(int) $Value];
                $cmd = ['soll' => (int) $f['soll'], 'dauer' => (int) $f['dauer']];
                $m = (int) ($f['modus'] ?? 0);
                $cmd['modus'] = self::MODI[$m] ?? 'finnisch';
                if ($m > 0 && isset($f['feuchte'])) {
                    $cmd['feuchte_soll'] = (int) $f['feuchte'];
                }
                $this->senden($cmd);
                return;
            }
            if ($Ident == 'fav_add') {
                $neu = json_decode((string) $Value, true);
                if (is_array($neu) && isset($neu['soll'], $neu['dauer']) && count($fav) < 8) {
                    $fav[] = ['soll' => (int) $neu['soll'], 'dauer' => (int) $neu['dauer'],
                              'modus' => (int) ($neu['modus'] ?? 0), 'feuchte' => (int) ($neu['feuchte'] ?? 0)];
                }
            }
            if ($Ident == 'fav_del' && isset($fav[(int) $Value])) {
                array_splice($fav, (int) $Value, 1);
            }
            $this->WriteAttributeString('Favoriten', json_encode(array_values($fav)));
            $this->kachelAktualisieren();
            return;
        }
        if (!isset(self::FELDER[$Ident]) || !self::FELDER[$Ident][3]) {
            throw new Exception('Variable ist nicht schaltbar: ' . $Ident);
        }
        switch ($Ident) {
            case 'aktion':
                if (!isset(self::AKTIONEN[$Value])) {
                    return;
                }
                $this->senden(['aktion' => self::AKTIONEN[$Value]]);
                $this->SetValue('aktion', 0);   // Aktion ist ein Taster
                return;
            case 'modus':
                if (!isset(self::MODI[(int) $Value])) {
                    return;
                }
                $this->senden(['modus' => self::MODI[(int) $Value]]);
                break;
            case 'licht':
            case 'master':
            case 'kurzzeit_soll':
            case 'kommunikation':
                $this->senden([$Ident => (bool) $Value]);
                break;
            default:
                $this->senden([$Ident => (int) $Value]);
        }
        // Die Variable selbst wird erst durch die Rückmeldung der Sauna gesetzt,
        // so zeigt Symcon immer den tatsächlichen Zustand.
    }

    // Öffentliche Funktionen für Skripte, z. B. SAUNA_Licht(12345, true);
    public function Licht(bool $An)
    {
        $this->senden(['licht' => $An]);
    }

    public function Solltemperatur(int $Grad)
    {
        $this->senden(['soll' => $Grad]);
    }

    public function Beenden()
    {
        $this->senden(['aktion' => 'beenden']);
    }

    // ---------- intern ----------
    private function topic(string $suffix): string
    {
        return trim($this->ReadPropertyString('Topic'), '/') . '/' . $suffix;
    }

    private function senden(array $befehl)
    {
        $payload = json_encode($befehl);
        $this->SendDebug('Senden', $payload, 0);
        if ($this->GetBuffer('hex') == '1') {
            $payload = bin2hex($payload);
        }
        $data = [
            'DataID'           => self::DATA_TO_PARENT,
            'PacketType'       => 3,
            'QualityOfService' => 0,
            'Retain'           => false,
            'Topic'            => $this->topic('set'),
            'Payload'          => $payload,
        ];
        $this->SendDataToParent(json_encode($data, JSON_UNESCAPED_SLASHES));
    }

    private function profile()
    {
        $p = [
            // Name => [Min, Max, Schritt, Suffix, Icon]
            'Sauna.Grad'    => [0, 120, 1, ' °C', 'Temperature'],
            'Sauna.Soll'    => [40, 110, 1, ' °C', 'Temperature'],
            'Sauna.Dauer'   => [5, 240, 5, ' min', 'Clock'],
            'Sauna.Minuten' => [0, 600, 1, ' min', 'Clock'],
            'Sauna.Stunden' => [0, 100000, 1, ' h', 'Clock'],
        ];
        foreach ($p as $name => $c) {
            if (!IPS_VariableProfileExists($name)) {
                IPS_CreateVariableProfile($name, 1);
            }
            IPS_SetVariableProfileValues($name, $c[0], $c[1], $c[2]);
            IPS_SetVariableProfileText($name, '', $c[3]);
            IPS_SetVariableProfileIcon($name, $c[4]);
        }
        foreach (['Sauna.Feuchte' => [1, 10, 1, '', 'Drops'], 'Sauna.Sekunden' => [0, 100000, 1, ' s', 'Clock']] as $name => $c) {
            if (!IPS_VariableProfileExists($name)) {
                IPS_CreateVariableProfile($name, 1);
            }
            IPS_SetVariableProfileValues($name, $c[0], $c[1], $c[2]);
            IPS_SetVariableProfileText($name, '', $c[3]);
            IPS_SetVariableProfileIcon($name, $c[4]);
        }
        if (!IPS_VariableProfileExists('Sauna.Modus')) {
            IPS_CreateVariableProfile('Sauna.Modus', 1);
        }
        IPS_SetVariableProfileAssociation('Sauna.Modus', 0, 'Finnisch', '', -1);
        IPS_SetVariableProfileAssociation('Sauna.Modus', 1, 'Bio', '', -1);
        IPS_SetVariableProfileAssociation('Sauna.Modus', 2, 'Dampf', '', -1);
        if (!IPS_VariableProfileExists('Sauna.Aktion')) {
            IPS_CreateVariableProfile('Sauna.Aktion', 1);
        }
        IPS_SetVariableProfileAssociation('Sauna.Aktion', 0, '–', '', -1);
        IPS_SetVariableProfileAssociation('Sauna.Aktion', 1, 'Standby', '', -1);
        IPS_SetVariableProfileAssociation('Sauna.Aktion', 2, 'Bereit', '', -1);
        IPS_SetVariableProfileAssociation('Sauna.Aktion', 3, 'Beenden', '', 0xE0703A);
    }
}
