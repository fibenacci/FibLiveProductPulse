# FibLiveProductPulse

`FibLiveProductPulse` erweitert die Shopware-PDP um zwei optionale Live-Funktionen:

- Live-Verfuegbarkeit / Reservierungsstatus
- Live-Betrachterzaehler (Viewer)

Der Name `Pulse` bezieht sich auf die technische Arbeitsweise:
- regelmäßige Polling-Impulse (`smart polling`)
- Heartbeats für aktive Viewer und aktive Warenkoerbe
- Backoff + Jitter zur Lastverteilung bei Fehlern / Hochlast

Damit entsteht kein permanenter WebSocket-Kanal, sondern ein kontrollierter, robust konfigurierbarer "Puls" zwischen Browser und Server.

## Features

### 1. Live Stock / Reservierung auf der PDP
- aktualisiert die Anzeige auf der Produktdetailseite live
- unterscheidet zwischen:
  - `verfügbar`
  - `reserviert` (durch anderen aktiven Warenkorb)
  - `vergriffen`
- "wer zuerst kommt, mahlt zuerst":
  - Reservierungen behalten eine stabile Reihenfolge
  - der Erstreservierer wird nicht später durch nachfolgende Nutzer blockiert

### 2. Optionales Sperren reservierter Artikel
Wenn aktiviert:
- wird das PDP-Kaufformular fuer andere Nutzer bei `reserviert` ausgeblendet
- wird der Checkout backendseitig blockiert (Cart Validator)

Wenn deaktiviert:
- wird nur der Hinweis angezeigt
- kein erzwungenes Sperrverhalten im Checkout

### 3. Live Viewer Counter
- zeigt aktive Betrachter auf der PDP
- der aktuelle Nutzer zählt sich selbst nicht mit
- Viewer werden per Heartbeat gepflegt und beim Verlassen per Beacon entfernt

## Warum "Pulse" technisch Sinn ergibt

Das Plugin arbeitet bewusst mit `smart polling` statt "dauerhaft offenem Kanal":

- Polling mit Intervallen fuer planbare Last
- `ETag` + `304 Not Modified` fuer den Stock-Status (smart polling)
- Jitter gegen synchronisierte Request-Spitzen
- exponentieller Backoff bei Fehlern
- Hintergrund-Tab-Intervall reduziert Requests
- Heartbeat/Leave-Mechanik fuer Viewer und Warenkorbpräsenz

Kurz: Nicht "dauerhaft streamen", sondern in kontrollierten Impulsen synchronisieren.

## PDP-Entkopplung

Stock- und Viewer-Funktion sind auf der PDP getrennt konfigurierbar und auch frontendseitig in zwei separaten Vanilla-JS-Plugins umgesetzt:

- `FibLiveProductPulseStock`
- `FibLiveProductPulseViewer`

Gemeinsame technische Basis:
- `SafePollingHelper` (wiederverwendbarer Polling-/Backoff-/Jitter-Helper)

## Konfiguration (Plugin-Config)

### PDP-Anzeige
- `showStockFeatureOnPdp`
  - Live-Bestands-/Reservierungsstatus auf der PDP ein/aus
- `showViewerFeatureOnPdp`
  - Live-Betrachterzaehler auf der PDP ein/aus

### Reservierungslogik
- `lockReservedProducts`
  - sperrt reservierte Artikel für andere Nutzer (UI + Backend-Checkout)

### Polling / Lastverhalten
- `pollIntervalMs`
  - Basisintervall aktiver Tabs
- `backgroundPollIntervalMs`
  - Intervall in Hintergrund-Tabs
- `requestTimeoutMs`
  - Timeout pro Polling-Request
- `maxBackoffMs`
  - Obergrenze für Fehler-Backoff
- `jitterPercent`
  - Zufallsanteil zur Lastverteilung

### TTL / Heartbeat
- `reservationTtlSeconds`
  - Lebensdauer von Warenkorb-Reservierungszeilen
- `cartPresenceTtlSeconds`
  - wie lange ein Warenkorb ohne Heartbeat als aktiv gilt
- `viewerTtlSeconds`
  - wie lange ein Viewer ohne Heartbeat als aktiv gilt

## Technischer Ablauf (vereinfacht)

### Stock / Reservierung
1. Cart-Events synchronisieren Reservierungen in die Plugin-Tabelle.
2. PDP sendet Cart-Presence-Heartbeat.
3. Stock-Poller fragt `stock-state` ab (mit `ETag`).
4. Server berechnet:
   - realen Bestand
   - aktive Fremd-Reservierungen
   - Status (`available`, `reserved`, `soldout`, ...)
5. PDP aktualisiert Anzeige und optional den Buy-Button.

### Viewer
1. PDP sendet Viewer-Heartbeat.
2. Server aktualisiert Presence und liefert Anzahl anderer aktiver Viewer.
3. Beim Verlassen sendet der Browser `sendBeacon` (Fallback `fetch keepalive`).

## Installation / Update (Kurz)

```bash
bin/console plugin:refresh
bin/console plugin:update FibLiveProductPulse --activate
bin/console cache:clear
```

Danach Storefront neu bauen (projektübliches Build-Kommando).

## Hinweise fuer Tests

- Bei Verhaltenstests mit mehreren Browsern/Sessions können alte Testdaten stören.
- Bei Bedarf Pulse-Tabellen leeren (Testsystem):

```sql
TRUNCATE TABLE fib_live_product_pulse_cart_reservation;
TRUNCATE TABLE fib_live_product_pulse_cart_presence;
TRUNCATE TABLE fib_live_product_pulse_viewer_presence;
```
