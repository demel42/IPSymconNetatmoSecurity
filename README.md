# IPSymconNetatmoSecurity

[![IPS-Version](https://img.shields.io/badge/Symcon_Version-5.0+-red.svg)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
![Module-Version](https://img.shields.io/badge/Modul_Version-0.9-blue.svg)
![Code](https://img.shields.io/badge/Code-PHP-blue.svg)
[![License](https://img.shields.io/badge/License-CC%20BY--NC--SA%204.0-green.svg)](https://creativecommons.org/licenses/by-nc-sa/4.0/)
[![StyleCI](https://github.styleci.io/repos/192195342/shield?branch=master)](https://github.styleci.io/repos/192195342)

## Dokumentation

**Inhaltsverzeichnis**

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Funktionsreferenz](#4-funktionsreferenz)
5. [Konfiguration](#5-konfiguration)
6. [Anhang](#6-anhang)
7. [Versions-Historie](#7-versions-historie)

## 1. Funktionsumfang

## 2. Voraussetzungen

 - IP-Symcon ab Version 5<br>
 - Netatmo Security-Modul und ein entsprechenden Account bei Netatmo.

   Es wird sowohl der "normalen" Benutzer-Account, der bei der Anmeldung der Geräte bei Netatmo erzeugt wird (https://my.netatmo.com) als auch einen Account sowie eine "App" bei Netatmo Connect benötigt, um die Werte abrufen zu können (https://dev.netatmo.com).

## 3. Installation

### a. Laden des Moduls

Die Konsole von IP-Symcon öffnen. Im Objektbaum unter Kerninstanzen die Instanz __*Modules*__ durch einen doppelten Mausklick öffnen.

In der _Modules_ Instanz rechts oben auf den Button __*Hinzufügen*__ drücken.

In dem sich öffnenden Fenster folgende URL hinzufügen:

`https://github.com/demel42/IPSymconNetatmoSecurity.git`

und mit _OK_ bestätigen. Ggfs. auf anderen Branch wechseln (Modul-Eintrag editieren, _Zweig_ auswählen).

Anschließend erscheint ein Eintrag für das Modul in der Liste der Instanz _Modules_

### b. Einrichtung in IPS

In IP-Symcon nun unterhalb von _I/O Instanzen_ die Funktion _Instanz hinzufügen_ (_CTRL+1_) auswählen, als Hersteller _Netatmo_ und als Gerät _NetatmoSecurity I/O_ auswählen.

In dem Konfigurationsdialog die Netatmo-Zugangsdaten eintragen.

Dann unter _Konfigurator Instanzen_ analog den Konfigurator _NetatmoSecurity Konfigurator_ hinzufügen.

Hier werden alle Security-Produkte, die mit dem, in der I/O-Instanz angegebenen, Netatmo-Konto verknüpft sind, angeboten; aus denen wählt man eine aus.

Mit Betätigen der Schaltfläche _Importieren der Station_ werden für jedes Modul, das zu dieser Station im Netatmo registriert ist, eine Geräte-Instanzen unterhalb von _IP-Symcon_ angelegt.
Der Namen der Instanzen ist der der Netatmo-Module, in derm Feld _Beschreibung_ der Instanzen ist der Modultyp sowie der Namen der Station und des Moduls eingetragen.

Der Aufruf des Konfigurators kann jederzeit wiederholt werden, es werden dann fehlende Module angelegt.

Die Module werden aufgrund der internen _ID_ der Module identifiziert, d.h. eine Änderung des Modulnamens muss in IPS nachgeführt werden.
Ein Ersatz eines Moduls wird beim Aufruf des Konfigurators dazuführen, das eine weitere Instanz angelegt wird.

Die im Netatmo eingetragenen Höhe der Station sowie die geographische Position wird als Property zu dem virtuellen Modul _Station_ eingetragen.

Zu den Geräte-Instanzen werden im Rahmen der Konfiguration Modultyp-abhängig Variablen angelegt. Zusätzlich kann man in den Modultyp-spezifischen Konfigurationsdialog weitere Variablen aktivieren.

Die Instanzen können dann in gewohnter Weise im Objektbaum frei positioniert werden.

## 4. Funktionsreferenz

## 5. Konfiguration

### I/O-Modul

#### Variablen

| Eigenschaft               | Typ      | Standardwert | Beschreibung |
| :------------------------ | :------  | :----------- | :----------- |
| Netatmo-Zugangsdaten      | string   |              | Benutzername und Passwort von https://my.netatmo.com sowie Client-ID und -Secret von https://dev.netatmo.com |
|                           |          |              | |
| Ignoriere HTTP-Fehler     | integer  | 0            | Da Netatmo häufiger HTTP-Fehler meldet, wird erst ab dem X. Fehler in Folge reagiert |
|                           |          |              | |
| Aktualisiere Daten ...    | integer  | 5            | Aktualisierungsintervall, Angabe in Minuten |

#### Schaltflächen

| Bezeichnung                  | Beschreibung |
| :--------------------------- | :----------- |
| Aktualisiere Daten           | führt eine sofortige Aktualisierung durch |

### Konfigurator

#### Auswahl

Es werden alle Stationen zu dem konfigurierten Account zur Auswahl angeboten. Es muss allerdings nur eine Auswahl getroffen werden, wenn es mehr als eine Station gibt.

#### Schaltflächen

| Bezeichnung                  | Beschreibung |
| :--------------------------- | :----------- |
| Import xxxxxxxxxxx           | richtet die Geräte-Instanzen ein |

### Geräte

#### Properties

werden vom Konfigurator beim Anlegen der Instanz gesetzt.

| Eigenschaft            | Typ     | Standardwert | Beschreibung |
| :--------------------- | :------ | :----------- | :----------- |
| home_id                | string  |              | ID des "Hauses" |
| module_id              | string  |              | ID des Moduls |

#### Variablen

stehen je nach Typ des Moduls zur Verfügung

| Eigenschaft               | Typ     | Standardwert | Beschreibung                               |
| :------------------------ | :------ | :----------- | :----------------------------------------- |

### Statusvariablen

folgende Variable werden angelegt, zum Teil optional

| Name                    | Typ            | Beschreibung                                    | Option                 | Module    |
| :---------------------- | :------------- | :---------------------------------------------- | :--------------------- | :-------- |


_Module_: O=Outdoor, I=Indoor, R=Rauchmelder

### Variablenprofile

Es werden folgende Variableprofile angelegt:
* Boolean<br>

* Integer<br>

* Float<br>

* String<br>

## 6. Anhang

GUIDs
- Modul: `{99D79F62-B7C8-4A59-9A67-65456C6EE9BB}`
- Instanzen:
  - NetatmoSecurityIO: `{DB1D3629-EF42-4E5E-92E3-696F3AAB0740}`
  - NetatmoSecurityConfig: `{C4834515-843B-4B91-A998-6EA29FD9E7A8}`
  - NetatmoSecurityCamera: `{06D589CF-7789-44B1-A0EC-6F51428352E6}`
- Nachrichten:
  - `{2EEA0F59-D05C-4C50-B228-4B9AE8FC23D5}`: an NetatmoSecurityIO
  - `{5F947426-53FB-4DD9-A725-F95590CBD97C}`: an NetatmoSecurityConfig, NetatmoSecurityCamera

## 7. Versions-Historie

- 1.0 @ xx.xx.xxxx xx:xx<br>
  Initiale Version
