# Sauna (WEKA / ESPHome) – Symcon-Modul

Ein Symcon-Gerät "Sauna" für die ESPHome-Saunasteuerung. Es hängt unter einer
MQTT-Server- oder MQTT-Client-Instanz und enthält alle Sauna-Variablen.

- Lesen: <Präfix>/state (JSON) -> Variablen
- Schreiben: Solltemperatur, Dauer, Licht, Master-Betrieb, Aktion -> <Präfix>/set (JSON)

Installation: Ordner "SymconSauna" in das Modul-Verzeichnis von Symcon kopieren
(oder als Git-Repository über die Module Control einbinden), Symcon neu laden,
dann Instanz "Sauna" unter der MQTT-Instanz hinzufügen.
