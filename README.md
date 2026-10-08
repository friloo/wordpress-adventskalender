# Adventskalender (WordPress-Plugin)

Ein eleganter, barrierefreier Adventskalender für WordPress: 24 (oder bis zu 31) Türchen,
zufällig angeordnet, die sich mit einer 3D-Animation öffnen, eine kleine Vorschau zeigen
und den Inhalt in einer weißen Lightbox präsentieren – Videos, Bilder, Galerien, Text
oder Text mit Bildern.

## Highlights

* **Türchen öffnen sich wie echte Türchen** – 3D-Klappe mit Scharnier, dahinter eine kleine Vorschau.
* **Weiße Lightbox** mit Titel, Medium, Text, optionalem Button und Blättern zum nächsten Türchen.
* **Drei Layouts** für die geschlossenen Türchen:
  * `classic` – elegante Türchen mit großer Zahl, ohne jedes Bild sofort schön
  * `mosaic` – **ein** großes Bild, jedes Türchen zeigt seinen Ausschnitt
  * `individual` – jedes Türchen mit eigenem Motiv
* **Fünf Farbwelten**: Nordisch, Elegant, Warm, Modern, Candy.
* **Testmodus** – öffnet alle Türchen unabhängig vom Datum, mit deutlichem Hinweis.
* **Redaktionsvorschau** – angemeldete Redakteure sehen alles, Besucher nur das Freigeschaltete.
* **Sicher:** gesperrte Inhalte liegen nie im Quellcode der Seite. Sie werden erst nach
  serverseitiger Datumsprüfung über eine eigene REST-Route ausgeliefert.
* **Barrierefrei:** echte Buttons, aussagekräftige Labels, Fokus-Falle in der Lightbox,
  Escape und Pfeiltasten, `prefers-reduced-motion`.
* **Cache-fest:** Der Zustand wird beim Laden über eine nicht gecachte REST-Route
  nachgeglichen – auch hinter aggressivem Page-Cache zeigt der Kalender den richtigen Tag.

## Installation

1. Ordner `adventskalender` nach `wp-content/plugins/` kopieren (oder als ZIP hochladen).
2. Plugin im Backend aktivieren.
3. Unter **Adventskalender → Einstellungen** Jahr, Layout und Farbwelt wählen.
4. Unter **Adventskalender → Übersicht** die fehlenden Türchen anlegen und füllen.
5. Shortcode `[adventskalender]` auf einer Seite einfügen – oder den Block
   **Adventskalender** verwenden.

Anforderungen: WordPress 6.2+, PHP 7.4+.

## Einbindung

### Shortcode

```
[adventskalender]
[adventskalender year="2026" layout="mosaic" theme="elegant" columns="6" shuffle="1" snow="1"]
```

| Attribut  | Werte                                   | Standard               |
|-----------|-----------------------------------------|------------------------|
| `year`    | 2000–2100                               | Einstellung            |
| `layout`  | `classic`, `mosaic`, `individual`       | Einstellung            |
| `theme`   | `nordic`, `elegant`, `warm`, `modern`, `candy` | Einstellung     |
| `columns` | 2–8                                     | Einstellung            |
| `shuffle` | `1`/`0`                                 | Einstellung            |
| `snow`    | `1`/`0`                                 | Einstellung            |
| `heading` | Freitext                                | Einstellung            |
| `intro`   | Freitext                                | Einstellung            |

### Block

Im Block-Editor nach **Adventskalender** suchen. Alle Optionen stehen in der
Seitenleiste; leer gelassene Felder übernehmen die globalen Einstellungen.

## Türchen pflegen

Jedes Türchen ist ein eigener Inhaltstyp mit:

* **Titel** – Überschrift in der Lightbox
* **Editor-Inhalt** – Text, auch mit eingefügten Bildern
* **Zeitfenster** – Tag im Dezember + Kalenderjahr (bestimmt die Freischaltung)
* **Medium** – kein Medium, Bild, Galerie oder Video (YouTube/Vimeo/Mediathek/MP4)
* **Anordnung** – Medium oben, Medium links, nur Medium, nur Text
* **Vorschau** – Bild und Teaser, die direkt hinter dem Türchen erscheinen
* **Türchen-Motiv** – Bild des geschlossenen Türchens (Layout „Einzelbilder")
* **Button** – optionaler Link mit eigenem Text

Nur **veröffentlichte** Türchen erscheinen im Kalender. Die Übersichtsseite zeigt
jederzeit, welche Tage fehlen, noch Entwurf oder leer sind.

## Test & Vorschau

| Variante               | Wirkung                                                        |
|------------------------|----------------------------------------------------------------|
| **Testmodus**          | Alle Besucher können jedes Türchen öffnen (mit Hinweisbanner).  |
| **Redaktionsvorschau** | Nur angemeldete Benutzer mit `edit_posts` sehen alle Türchen.   |

Den Testmodus kann man direkt auf der Übersichtsseite ein- und ausschalten.

## Sicherheit

* Keine Inhaltsdaten gesperrter Türchen im HTML – Auslieferung nur über
  `/wp-json/adventskalender/v1/door/<tag>` nach serverseitiger Prüfung.
* Alle Formulare mit Nonce und Capability-Prüfung (`edit_post`, `manage_options`).
* Alle Eingaben werden validiert (Allowlists für Auswahlfelder, `absint` für IDs,
  `wp_http_validate_url` für URLs, `wp_kses_post` für HTML).
* Alle Ausgaben werden escaped; Medien-IDs werden gegen die Mediathek geprüft.
* Keine direkten SQL-Queries außer einer `$wpdb->prepare`-Abfrage für die Jahresliste.
* `localStorage` statt Cookies für geöffnete Türchen – keine personenbezogenen Daten.

## Filter für Entwickler

| Filter                                | Zweck                                      |
|---------------------------------------|--------------------------------------------|
| `adventskalender_test_mode`           | Testmodus programmatisch steuern           |
| `adventskalender_preview_capability`  | Capability der Redaktionsvorschau          |
| `adventskalender_is_unlocked`         | Freischaltung eines Türchens überschreiben |
| `adventskalender_arrangement`         | Anordnung der Türchen bestimmen            |
| `adventskalender_date_format`         | Datumsformat der Hinweise                  |
| `adventskalender_door_payload`        | Daten eines Türchens anpassen              |
| `adventskalender_door_html`           | Lightbox-Markup anpassen                   |
| `adventskalender_manage_capability`   | Capability der Türchen-Verwaltung          |

## Lizenz

GPL-2.0-or-later

## Tests

Die Logik lässt sich ohne WordPress-Installation prüfen – gegen schlanke
Attrappen der benötigten WordPress-Funktionen:

```bash
php tests/run.php
```

Abgedeckt sind unter anderem:

* Datumsgrenzen inklusive Mitternacht, Zeitzonen und Jahreswechsel
* Testmodus und Redaktionsvorschau (auch abgeschaltet)
* Validierung der Einstellungen und Shortcode-Attribute (Allowlists, Medien-IDs)
* Stabilität und Streuung der zufälligen Anordnung
* **Zusicherung, dass kein Inhalt eines gesperrten Türchens im HTML oder in
  einer REST-Antwort auftaucht** – inklusive Umgehungsversuch über den
  `year`-Parameter
