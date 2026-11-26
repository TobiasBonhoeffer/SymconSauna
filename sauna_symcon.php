<?php
/*
 * Sauna – Anbindung an IP-Symcon über MQTT (JSON)
 *
 * Voraussetzung: zwei MQTT-Geräte-Instanzen (je nach Aufbau "MQTT Server Device"
 * mit dem Symcon-eigenen Broker oder "MQTT Client Device" mit einem externen Broker):
 *   1. Topic  sauna/state  (Variable: String)  – empfängt den Status der Sauna
 *   2. Topic  sauna/set    (Variable: String)  – zum Senden von Befehlen
 *
 * Einrichtung:
 *   - Dieses Skript in eine eigene Kategorie "Sauna" legen.
 *   - Unten die beiden Variablen-IDs eintragen.
 *   - Skript EINMAL ausführen: legt alle Variablen, Profile und das Ereignis an.
 * Danach läuft alles automatisch: Statusänderungen werden verteilt, Schalten und
 * Werte-Ändern in der Visualisierung werden als JSON an die Sauna geschickt.
 */

$STATE_VAR = 0;   // <- ID der String-Variable des MQTT-Geräts "sauna/state"
$SET_VAR   = 0;   // <- ID der String-Variable des MQTT-Geräts "sauna/set"

$PARENT = IPS_GetParent($_IPS['SELF']);   // Variablen landen neben diesem Skript

// ident => [Name, Typ (0 Bool, 1 Integer, 2 Float, 3 String), Profil, JSON-Schlüssel zum Schalten oder null, Position]
$FELDER = [
    'status'           => ['Status',            3, '',              null,     1],
    'ist'              => ['Ist-Temperatur',    1, 'Sauna.Grad',    null,     2],
    'soll'             => ['Solltemperatur',    1, 'Sauna.Soll',    'soll',   3],
    'dauer'            => ['Dauer',             1, 'Sauna.Dauer',   'dauer',  4],
    'restzeit'         => ['Restzeit',          1, 'Sauna.Minuten', null,     5],
    'laufzeit'         => ['Laufzeit',          1, 'Sauna.Minuten', null,     6],
    'licht'            => ['Licht',             0, '~Switch',       'licht',  7],
    'aktion'           => ['Aktion',            1, 'Sauna.Aktion',  'aktion', 8],
    'master'           => ['Master-Betrieb',    0, '~Switch',       'master', 9],
    'phase'            => ['Phase',             3, '',              null,    10],
    'ofen'             => ['Ofen',              3, '',              null,    11],
    'verdampfer'       => ['Verdampfer',        3, '',              null,    12],
    'verdampfer_heizt' => ['Verdampfer heizt',  0, '~Switch',       null,    13],
    'feuchtestufe'     => ['Feuchtestufe',      1, '',              null,    14],
    'kurzzeit'         => ['Kurzzeit Restzeit', 1, 'Sauna.Minuten', null,    15],
    'start_erlaubt'    => ['Start erlaubt',     0, '~Switch',       null,    16],
    'betriebsstunden'  => ['Betriebsstunden',   1, 'Sauna.Stunden', null,    17],
    'sitzungen'        => ['Sitzungen gesamt',  1, '',              null,    18],
    'fehler'           => ['Busfehler',         1, '',              null,    19],
];

// Werte der Variable "Aktion" (Start ist per MQTT bewusst nicht vorgesehen)
$AKTIONEN = [1 => 'standby', 2 => 'bereit', 3 => 'beenden'];

switch ($_IPS['SENDER']) {

    // ---------- Einmal von Hand ausführen: alles anlegen ----------
    case 'Execute':
        if ($STATE_VAR == 0 || $SET_VAR == 0) {
            echo "Bitte zuerst STATE_VAR und SET_VAR oben im Skript eintragen.";
            return;
        }
        profile();
        foreach ($FELDER as $ident => $f) {
            $id = @IPS_GetObjectIDByIdent($ident, $PARENT);
            if ($id === false) {
                $id = IPS_CreateVariable($f[1]);
                IPS_SetParent($id, $PARENT);
                IPS_SetIdent($id, $ident);
            }
            IPS_SetName($id, $f[0]);
            IPS_SetPosition($id, $f[4]);
            IPS_SetVariableCustomProfile($id, $f[2]);
            IPS_SetVariableCustomAction($id, $f[3] !== null ? $_IPS['SELF'] : 0);
        }
        // Ereignis: bei jeder Änderung von sauna/state dieses Skript ausführen
        $eid = @IPS_GetObjectIDByIdent('sauna_state_trigger', $_IPS['SELF']);
        if ($eid === false) {
            $eid = IPS_CreateEvent(0);
            IPS_SetParent($eid, $_IPS['SELF']);
            IPS_SetIdent($eid, 'sauna_state_trigger');
            IPS_SetName($eid, 'Bei neuem Sauna-Status');
        }
        IPS_SetEventTrigger($eid, 1, $STATE_VAR);   // 1 = bei Änderung
        IPS_SetEventActive($eid, true);
        verteilen($STATE_VAR, $FELDER, $PARENT);
        echo "Sauna-Variablen angelegt bzw. aktualisiert.";
        break;

    // ---------- Neuer Status von der Sauna ----------
    case 'Variable':
        if ($_IPS['VARIABLE'] == $STATE_VAR) {
            verteilen($STATE_VAR, $FELDER, $PARENT);
        }
        break;

    // ---------- Bedienung in der Visualisierung ----------
    case 'Action':
    case 'WebFront':
    case 'VoiceControl':
        $ident = IPS_GetObject($_IPS['VARIABLE'])['ObjectIdent'];
        if (!isset($FELDER[$ident]) || $FELDER[$ident][3] === null) break;
        $schluessel = $FELDER[$ident][3];
        $wert = $_IPS['VALUE'];
        if ($ident == 'aktion') {
            if (!isset($AKTIONEN[$wert])) break;
            $wert = $AKTIONEN[$wert];
        } elseif ($FELDER[$ident][1] == 0) {
            $wert = (bool) $wert;
        } else {
            $wert = (int) $wert;
        }
        RequestAction($SET_VAR, json_encode([$schluessel => $wert]));
        // Die Variable selbst wird erst durch die Rückmeldung der Sauna gesetzt
        // (sauna/state), so zeigt Symcon immer den echten Zustand.
        break;
}

// JSON aus sauna/state lesen und auf die Variablen verteilen
function verteilen($stateVar, $felder, $parent)
{
    $d = json_decode(GetValueString($stateVar), true);
    if (!is_array($d)) return;
    foreach ($felder as $ident => $f) {
        if (!array_key_exists($ident, $d) || $d[$ident] === null) continue;
        $id = @IPS_GetObjectIDByIdent($ident, $parent);
        if ($id === false) continue;
        switch ($f[1]) {
            case 0: $v = (bool) $d[$ident]; break;
            case 1: $v = (int) $d[$ident]; break;
            case 2: $v = (float) $d[$ident]; break;
            default: $v = (string) $d[$ident];
        }
        if (GetValue($id) !== $v) SetValue($id, $v);
    }
}

// Variablenprofile anlegen
function profile()
{
    $p = [
        // Name => [Typ, Min, Max, Schritt, Suffix, Icon]
        'Sauna.Grad'    => [1, 0, 120, 1, ' °C', 'Temperature'],
        'Sauna.Soll'    => [1, 40, 100, 1, ' °C', 'Temperature'],
        'Sauna.Dauer'   => [1, 1, 15, 1, ' min', 'Clock'],
        'Sauna.Minuten' => [1, 0, 600, 1, ' min', 'Clock'],
        'Sauna.Stunden' => [1, 0, 100000, 1, ' h', 'Clock'],
    ];
    foreach ($p as $name => $c) {
        if (!IPS_VariableProfileExists($name)) IPS_CreateVariableProfile($name, $c[0]);
        IPS_SetVariableProfileValues($name, $c[1], $c[2], $c[3]);
        IPS_SetVariableProfileText($name, '', $c[4]);
        IPS_SetVariableProfileIcon($name, $c[5]);
    }
    if (!IPS_VariableProfileExists('Sauna.Aktion')) IPS_CreateVariableProfile('Sauna.Aktion', 1);
    IPS_SetVariableProfileAssociation('Sauna.Aktion', 0, '–', '', -1);
    IPS_SetVariableProfileAssociation('Sauna.Aktion', 1, 'Standby', '', -1);
    IPS_SetVariableProfileAssociation('Sauna.Aktion', 2, 'Bereit', '', -1);
    IPS_SetVariableProfileAssociation('Sauna.Aktion', 3, 'Beenden', '', 0xE0703A);
}
