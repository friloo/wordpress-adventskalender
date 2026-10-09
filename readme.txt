=== Adventskalender ===
Contributors: friloo
Tags: adventskalender, advent calendar, christmas, lightbox, countdown
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Ein eleganter, barrierefreier Adventskalender: zufällig angeordnete Türchen, 3D-Öffnungsanimation und weiße Lightbox für Videos, Bilder und Texte.

== Description ==

24 Türchen (oder bis zu 31), zufällig – aber über das Jahr stabil – angeordnet. Jedes Türchen öffnet sich mit einer 3D-Animation, zeigt dahinter eine kleine Vorschau und präsentiert den Inhalt in einer weißen Lightbox.

Inhalte pro Türchen: Text, Bild, Bildergalerie, Video (YouTube, Vimeo, Mediathek, MP4) oder ein kleiner Bericht – Titelbild randlos oben, darunter der Text, mit weiteren Bildern im Fließtext, links oder rechts umflossen und mit Bildunterschrift. Auf schmalen Bildschirmen steht jedes Bild automatisch allein.

Farbwelt „Markenfarbe": Trage den Hex-Wert deiner Firmenfarbe ein – Türchen, Fläche, Zahlen, Akzente und Innenraum werden daraus abgeleitet. Alle Kontraste werden nach WCAG 2.1 berechnet und notfalls korrigiert, die Tagesnummern kippen automatisch zwischen hell und dunkel. Die Einstellungsseite zeigt eine Live-Vorschau mit den gemessenen Kontrastwerten.

Drei Layouts für die geschlossenen Türchen:

* Klassisch – elegante Türchen mit großer Zahl, ohne eigenes Bildmaterial
* Mosaik – ein großes Bild, über alle Türchen verteilt
* Einzelbilder – jedes Türchen mit eigenem Motiv

Weitere Funktionen:

* Testmodus: öffnet alle Türchen unabhängig vom Datum
* Redaktionsvorschau nur für angemeldete Redakteure
* Sechs Farbwelten inklusive frei wählbarer Markenfarbe, wählbare Schrift für die Zahlen (nur Systemschriften), optionaler Schneefall, optionales Hintergrundbild
* Videos bekommen automatisch ein Vorschaubild: beim Speichern wird es vom Anbieter geholt und in der Mediathek abgelegt - beim Seitenaufruf entsteht keine Verbindung zu YouTube oder Vimeo
* Shortcode `[adventskalender]` und Block „Adventskalender"
* Barrierefrei: Tastaturbedienung, Fokusführung, reduzierte Bewegung
* Verlinkbare Türchen: ein geöffnetes Türchen steht in der Adresszeile, die Zurück-Taste schließt die Lightbox

Sicherheit: Inhalte gesperrter Türchen werden nicht an den Browser ausgeliefert. Erst nach serverseitiger Datumsprüfung liefert eine eigene REST-Route den Inhalt.

== Installation ==

1. Plugin installieren und aktivieren.
2. Adventskalender → Einstellungen: Jahr, Layout und Farbwelt wählen.
3. Adventskalender → Übersicht: fehlende Türchen anlegen und füllen.
4. Shortcode `[adventskalender]` oder den Block auf einer Seite einfügen.

== Frequently Asked Questions ==

= Wie teste ich den Kalender vor dem 1. Dezember? =

Entweder über die Redaktionsvorschau (du bist angemeldet und siehst alles) oder über den Testmodus, der alle Türchen für alle Besucher öffnet. Der Testmodus zeigt im Frontend einen Hinweis und lässt sich auf der Übersichtsseite mit einem Klick umschalten.

= Funktioniert das Plugin mit einem Page-Cache? =

Ja. Der Kalender gleicht den Freischaltzustand beim Laden über eine nicht gecachte REST-Route ab.

= Kann ich die Türchen mit dem Block-Editor bearbeiten? =

Ja, das ist die Voreinstellung. Unter Einstellungen → Verhalten & Daten lässt sich auch der klassische Editor wählen. Für den Block-Editor meldet WordPress die Türchen an der REST-API an; das Plugin verriegelt diese Routen doppelt, sodass nur angemeldete Redakteure sie lesen können. Gesperrte Inhalte bleiben geschützt.

= Kann ich die Farben an unser Corporate Design anpassen? =

Ja. Wähle die Farbwelt „Markenfarbe" und trage den Hex-Wert eurer Primärfarbe ein. Die gesamte Palette wird daraus abgeleitet, inklusive Kontrastprüfung nach WCAG 2.1. Per Shortcode geht es auch direkt: `[adventskalender color="#0057B8"]`.

= Bleibt die zufällige Anordnung gleich? =

Ja, sie ist pro Jahr stabil, damit Besucher ihre Türchen wiederfinden. In den Einstellungen lässt sich bewusst neu mischen.

== Changelog ==

= 1.0.0 =
* Erste Veröffentlichung.
