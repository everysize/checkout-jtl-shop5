# everysize Checkout — Plugin für JTL-Shop 5

Bindet einen JTL-Shop 5 an den everysize-Direktkauf an: Bestellungen, die
Kundinnen auf everysize.com aufgeben, landen als Bestellung im Shop;
Bestellnummer, Versand mit Sendungsnummern, Storno, Retoure und Erstattungen
gehen zurück an everysize.

Plugin-ID `everysize_checkout`, Version 1.2.0, MIT-Lizenz.

## Voraussetzungen

* JTL-Shop 5.2 bis 5.x (Bootstrap-Plugin), PHP ≥ 8.1 mit `curl`
* ein Cron-Trigger (JTL-Shop hat keinen eingebauten Scheduler — siehe
  „Zeitsteuerung“)
* die Zugangsdaten der External API (clientId + clientSecret), die der
  everysize-Checkout für den Shop ausstellt

Kein PayPal-Modul nötig: bezahlt wird bei everysize (PayPal). Erstattet
wird über everysize — das Plugin löst die Erstattung aus, everysize erstattet
die PayPal-Zahlung im Namen des Händlers.

## Installation

1. ZIP herunterladen (oder `bin/build-zip.sh` bauen).
2. Backend: **Plugins → Plugin-Manager → Upload** — die Datei auswählen.
   Der Ordner im ZIP heißt `everysize_checkout/`, wie JTL es erwartet.
3. Plugin **aktivieren**. Dabei entstehen die Plugin-Tabellen
   (`xplugin_everysize_checkout_*`), die Zahlungsart „everysize Checkout
   (PayPal)“ (im Shop nie wählbar) und der Fallback-Cronjob.

## Einrichtung

Backend → Plugins → everysize Checkout → **Einstellungen**:

| Einstellung | Bedeutung | Standard |
|---|---|---|
| Client-ID / Client-Secret | Zugangsdaten der External API (write-only, stehen nie im Browser) | – |
| API-Umgebung | `https://api.everysize.com` (Produktion) | Produktion |
| Versanddienstleister | wird mit dem Versand an everysize gemeldet | `DHL` |
| Versandart für Import-Bestellungen | leer = die erste Versandart, die das Lieferland beliefert | – |
| Status importierter Bestellungen | Startstatus in der Bestellverwaltung | In Bearbeitung |
| Storno vor dem Versand automatisch erstatten | volle Erstattung nach `MERCHANT_DECLINE` | an |
| Bestellbestätigung senden | JTL-Bestellbestätigungsmail nach dem Import | aus |
| Abgleich aktiviert | Import/Export über Cron und CLI | an |

Mit „Verbindung testen“ prüft man die Zugangsdaten; angezeigt wird die
everysize-Shop-ID.

Die Zugangsdaten liegen in der Shop-Datenbank
(`xplugin_everysize_checkout_settings`) und nicht in JTLs
Plugin-Einstellungen — JTL zeigt jeden Plugin-Einstellungswert im Backend,
exportiert ihn und reicht ihn an Smarty durch; hier ist das Secret
write-only.

## Ablauf

| Richtung | Was | Wann |
|---|---|---|
| everysize → Shop | neue Bestellungen (`PROCESSING`, auch bereits stornierte `REVOKED`) ohne Händler-Bestellnummer | jeder Lauf |
| Shop → everysize | JTL-Bestellnummer | direkt nach dem Import |
| Shop → everysize | Import-Meldung: aktueller Shop-Preis und Bestand je Position (Information, ab 1.1.0) | einmal direkt nach dem Import, nicht bei stornierten Bestellungen |
| everysize → Shop | Hinweis, wenn eine importierte Bestellung bei everysize storniert wurde (ab 1.2.0, siehe „Storno durch everysize“) | nach Import und Export, jeder Lauf |
| Shop → everysize | Versand mit Sendungsnummer und Versanddienstleister | Versand in der Wawi gebucht (Status/Lieferschein), ersatzweise Sendungsnummer von Hand im Bestell-Tab (gilt als Versand: Storno danach ist `RETOUR`, keine automatische Erstattung) |
| Shop → everysize | Sendungsnummer nachgetragen oder korrigiert | in der Wawi oder von Hand im Bestell-Tab geändert → nächster Lauf |
| Shop → everysize | Erstattung (ganz oder teilweise) | Button im Bestell-Tab |
| Shop → everysize | volle Erstattung | automatisch nach einem Storno **vor** dem Versand (abschaltbar) |
| Shop → everysize | Storno `MERCHANT_DECLINE` | Bestellung storniert (Wawi oder Backend), **bevor** der Versand gemeldet war |
| Shop → everysize | Retoure `RETOUR` | Bestellung storniert, **nachdem** der Versand gemeldet war |
| Shop → everysize | Retoure `RETOUR` einzelner Positionen | von Hand im Bestell-Tab („Retoure von Hand“), wenn die Rücksendung in der Wawi nicht als Storno gebucht wird |

* **Zuordnung:** Bestellpositionen über `sku` = JTL-**Artikelnummer**
  (`tartikel.cArtNr`) der Variante. Exakter Treffer vor Groß-/Kleinschreibung,
  Vaterartikel sind nie matchbar; zwei gleich gute Treffer sind ein Fehler.
* **Preise:** Bestellung mit den bei everysize bezahlten Bruttopreisen.
  Netto und Mehrwertsteuer rechnet das Plugin mit dem Steuersatz der
  Steuerklasse des Artikels für das Lieferland zurück (tsteuersatz/
  tsteuerzoneland). Die Positionen werden ungerundet gespeichert, genau wie
  JTL es beim Checkout tut — die Wawi summiert sie korrekt.
* **Import-Meldung:** Vor dem Schreiben liest das Plugin je Position den
  Brutto-Shop-Preis und den Bestand (nur wenn der Shop den Verkauf über den
  Bestand begrenzt). Weicht der Preis um mehr als 0,009 ab oder liegt der
  Bestand unter der Bestellmenge, steht eine Warnung im Protokoll; der Lauf
  bleibt erfolgreich. Die Meldung an everysize ist reine Information: sie
  ändert weder Bestellung noch Preis, ein Fehler dabei stoppt den Import
  nicht und wird nicht wiederholt.
* **Bestand:** wird beim Import reduziert (nur bei
  `cLagerBeachten = 'Y'`, Packeinheiten und der Vaterartikel mitgeführt,
  Bestand klemmt bei 0 wie in JTL), ein Storno in JTL bucht ihn wie gewohnt
  zurück. Ein Bestand unter der bestellten Menge ist eine Warnung im
  Protokoll, kein Fehler.
* **Kunde:** `customer.email` ist eine Weiterleitungsadresse von everysize,
  stabil je Kundin; ein Wiederkauf landet am selben (Gast-)Konto. Mails des
  Shops gehen dorthin und laufen über everysize. Die Original-Adresse
  (`customer.originalEmail`) steht nur zur Information im Bestell-Tab.
* **Versandart/Lieferland:** importiert wird nur, wenn eine Versandart das
  Lieferland beliefert (die eingestellte, sonst die erste passende) und die
  Währung der Shop-Standardwährung entspricht — Abweichungen sind Fehler
  vor dem ersten Schreibvorgang, nicht stille Konvertierungen.
* **Atomar:** Kunde, Adressen, Warenkorb, Bestellung, Positionen,
  Zahlungseingang, Bestand und Verknüpfung entstehen in einer Transaktion;
  ein Fehler hinterlässt nichts, die Bestellung bleibt bei everysize und
  wird im nächsten Lauf erneut versucht.
* **Zahlungseingang:** jede importierte Bestellung bekommt einen
  tzahlungseingang-Eintrag (Betrag, Währung, Zahlender, Capture-/Transaktions-ID
  in „Hinweis“) — die JTL-Wawi liest Zahlungen nur aus dieser Tabelle.
* **Genau einmal:** jede Meldung steht mit eindeutigem Schlüssel (Bestellung,
  Art, SKU) in `xplugin_everysize_checkout_report`; ein zweiter Lauf meldet
  nichts doppelt.
* **Erstattung:** Bestell-Tab → Betrag (Restbetrag vorbelegt) → „Erstatten“;
  die Rückfrage im Browser nennt den Betrag. Jedes Formular trägt einen
  eigenen Idempotency-Key, ein doppelt abgeschicktes Formular erstattet
  einmal. Vorab liest das Plugin, was bereits erstattet ist (auch direkt in
  PayPal), und sendet keinen höheren Betrag. Erstattungen laufen **nur** über
  den everysize-Reiter — Gutschriften in der JTL-Wawi bewegen kein Geld
  (JTL-Shop kennt keinen Refund-Hook für Zahlungsart-Plugins).
* **Ergebnis unklar:** Der Aufruf wartet bis zu 90 Sekunden auf PayPal. Der Händler-Webserver
  kann früher abbrechen, deshalb wird der Vorgang (Schlüssel, Betrag, Zeit) **vor dem Senden**
  gespeichert, höchstens einer je Bestellung. Nur eine **nachweisliche Ablehnung** (422 mit
  `PAYPAL_REFUND_FAILED` oder `REFUND_AMOUNT_INVALID`, 400 mit
  `ORDER_NOT_PAID_USING_EVERYSIZE_CHECKOUT_PAYMENTS`) beendet ihn: kein Geld geflossen, der
  nächste Versuch bekommt einen neuen Schlüssel. **Alles andere** (keine Antwort, Zeitüberschreitung,
  3xx, 401, 403, 404, 408, 409, 425, 429, 5xx, unbekannte Antworten) lässt den Vorgang offen: Das
  Geld kann geflossen sein, bitte in PayPal prüfen. Ein gescheiterter Token-Abruf vor dem Senden
  ist keine Ablehnung, es ist nichts passiert. Solange ein Vorgang offen ist, geht nichts anderes
  raus, weder von Hand noch automatisch; ein anderer Betrag wird abgelehnt. Das Wiederholen ist
  eine eigene Aktion, erst nach 150 Sekunden, und liest vorher die PayPal-Liste: Steht dort eine
  Erstattung mit gleichem Betrag, die der Shop noch nicht kennt, wird der Vorgang damit
  abgeschlossen und nichts gesendet; sonst wird der Betrag mit demselben Schlüssel gesendet – ging
  die erste Anfrage nicht durch, wird er jetzt ausgezahlt. Wer nichts mehr oder etwas anderes
  erstatten will, wählt „Als geklärt markieren“ (ab 150 Sekunden, sendet nichts, die automatische
  Erstattung bleibt für die Bestellung aus). Das Formular sendet die beim Laden bekannte
  Erstattungssumme mit: Hat sie sich geändert, oder ist die Liste nicht lesbar, wird nicht gesendet.
  Der Vorgang wird zuerst **reserviert**, dann wird die Liste gelesen und ihr Stand (die `refundId`s) im
  Vorgang gespeichert; ist die Liste nicht lesbar oder keine echte Liste, wird der neu angelegte Vorgang
  wieder gelöscht und nichts gesendet. Als Treffer beim Wiederholen zählt nur eine Erstattung, die **nicht**
  im gespeicherten Stand und nicht in den Berichten des Shops steht – eine alte fremde Erstattung wird nie
  als die eigene übernommen. Eine 2xx-Antwort ohne `refundId` gilt als unklar, nicht als Erfolg. Lehnt der
  Checkout eine **Wiederholung** ab, bleibt der Vorgang offen (bitte in PayPal prüfen, als geklärt markieren);
  nur die Ablehnung des ersten Versuchs löscht ihn. Der offene Vorgang wird auch angezeigt, wenn die Liste
  nicht lesbar ist, mit „Als geklärt markieren“ (bedingtes Löschen, mit Protokolleintrag).
* **Automatische Erstattung:** Nach einem gemeldeten Storno **vor** dem
  Versand erstattet das Plugin im nächsten Lauf den vollen Betrag, sobald
  keine Position der Bestellung mehr aktiv ist — es wurde nichts versendet,
  der Kundin steht alles zu. Retouren nicht (der Händler erstattet nach
  Wareneingang, ggf. mit Abzug). Erstattet werden nur Stornos ab dem
  Einschalten; beim Abschalten bleibt der Zeitpunkt erhalten. Sie läuft nur,
  wenn **nachweislich nichts versendet** wurde (kein Status „Versandt“, kein
  Versanddatum, kein Lieferschein, keine Sendungsnummer — auch keine von Hand
  eingetragene, kein gemeldeter Versand) und bei everysize **keine andere
  Erstattung** steht (`COMPLETED` oder `PENDING`, auch nicht eine direkt in
  PayPal gemachte). Lässt sich die Erstattungsliste nicht lesen, überspringt
  das Plugin den Lauf und prüft im nächsten erneut. Greift eine Sperre, wird
  nicht erstattet und das Protokoll vermerkt es (`REFUNDS_EXIST` bzw. der
  Hinweis „please check on the order page“). Der Lauf nutzt denselben offenen
  Vorgang wie das Formular (vor dem Senden gespeichert, Schlüssel
  `auto-decline-<Bestellung>`): Nur eine nachweisliche Ablehnung ist endgültig, alles
  andere bleibt offen und wird im nächsten Lauf nach 150 Sekunden geklärt, nachdem die
  Liste gelesen wurde. Offene Vorgänge, die älter als 15 Minuten sind, stehen in jedem
  Lauf als Fehler im Protokoll. Ein Versand, dessen Meldung an everysize scheiterte,
  wird gemerkt, gilt als Versand (Retoure, keine Erstattung) und wird nachgeholt.
  **Der Lauf sendet höchstens einmal:** Ein offener Vorgang des Laufs wird danach nur noch mit der Liste
  abgeglichen (Treffer: abgeschlossen, sonst offen, ab 15 Minuten nach dem Anlegen ein Fehler im Protokoll);
  erneut gesendet wird nur durch die ausdrückliche Aktion eines Menschen. Direkt vor dem einen Senden werden
  Einstellung und Bedingungen für genau diese Bestellung noch einmal geprüft. Schließt die Abfrage ein Storno
  aus (versendet, Sendungsnummer, Lieferschein, frühere Erstattung), steht einmal ein Hinweis im Protokoll.
  Schaltet man die Automatik aus und wieder ein, beginnt der Stichtag neu.
  **Grenze:** Gutschriften in der JTL-Wawi kennt das Plugin nicht (sie liegen nicht im
  Shop); wer in der Wawi eine Gutschrift bucht und trotzdem storniert, muss die
  automatische Erstattung abschalten oder die Bestellung vorher in PayPal prüfen.
  Der Stichtag der Automatik gilt für den Zeitpunkt der Storno-Meldung, nicht des
  Stornos selbst (JTL speichert ihn nicht).

## Zeitsteuerung

Der Abgleich läuft bei jedem Aufruf und nimmt sich selbst einen
Datenbank-Lock, ein zweiter paralleler Lauf überspringt sich.

* **Webcron (empfohlen):** `GET https://<shop>/everysize-checkout/cron`
  mit dem Header `X-Everysize-Token: <Token aus den Einstellungen>`
  (der Token im Query-Parameter `?token=…` funktioniert auch, landet
  aber in den Access-Logs). Ein 200 bedeutet „Abgleich gelaufen“, ein
  500 mit Meldung listet die Fehler.
* **System-Cron:** `php /pfad/zu/jtl/cli everysize_checkout:sync`
  (alle 5 Minuten ist ein guter Takt). `everysize_checkout:order:import`
  und `everysize_checkout:order:export` rufen nur eine Richtung auf.
* **JTL-Cron (Fallback):** der Plugin-Cronjob `everysize_checkout_sync`
  läuft bei jedem JTL-Cron-Tick, drosselt sich aber selbst auf einen
  Lauf je 5 Minuten — er fängt auf, wenn Webcron und System-Cron längere
  Zeit nicht liefen.

## Versand und Sendungsnummern

Versandt ist eine Bestellung, wenn ihr Status auf „Versandt“/„Teilversandt“
steht oder die Wawi einen Lieferschein mit Sendung geschrieben hat (dbeS).
Versand und Sendungsnummer bucht der Händler wie gewohnt in der JTL-Wawi;
die Nummer kommt aus dem Wawi-Lieferschein (`tversand.cIdentCode`). Nur wenn
die Wawi keine oder eine falsche Nummer liefert, trägt man sie im Bestell-Tab
unter „Korrektur, falls nötig“ → „Sendungsnummer von Hand“ ein — diese
Eingabe hat Vorrang und gilt als Versand (Versandmeldung, Storno als `RETOUR`,
keine automatische Erstattung). Ändert sich die Nummer später (in der Wawi oder von
Hand), meldet das Plugin sie als Nachmeldung; die Kundin bekommt keine Mail.
Der Versanddienstleister aus der Wawi (`cLogistik`) wird über die
Einstellung „Versanddienstleister“/Carrier-Mapping auf die
everysize-Schreibweise abgebildet; unbekannte Namen (z. B. „DHL Paket“)
fallen auf den eingestellten Versanddienstleister zurück.

## Storno, Retoure und Erstattung

Ein Storno (Status −1, per Wawi oder Backend) wird als `MERCHANT_DECLINE`
gemeldet, wenn der Versand noch nicht gemeldet war, sonst als `RETOUR`.
Vor jeder Meldung fragt das Plugin den Stand bei everysize ab: Ist die
Bestellung dort schon storniert (PayPal-Erstattung durch everysize),
meldet es nichts — ein Versand würde sie auf `COMPLETED` zurücksetzen, ein
Storno den Grund von everysize überschreiben; im Protokoll steht das als
„skipped“. Erstattungen laufen über den Bestell-Tab; die vollautomatische
Erstattung nach Storno vor dem Versand ist abschaltbar und wird mit Grund
im Protokoll vermerkt, wenn sie nicht möglich ist (z. B. Zahlung nicht
über everysize gelaufen).

## Storno durch everysize

Wird eine bereits importierte Bestellung danach bei everysize storniert (z. B. von der Kundin
widerrufen und erstattet), zeigt das Plugin einen **Hinweis nur für den Händler**: ein Kennzeichen
„bei everysize storniert“ in der Liste und einen roten Hinweis über der Bestellung im Tab
„Bestellungen“ sowie eine Warnung im Protokoll: „Bestellung <Nummer> wurde bei everysize
storniert. Bitte nicht versenden und im Shop stornieren.“ Steht die Bestellung hier schon als
versendet (Status, Versanddatum, Sendungsnummer, Lieferschein), lautet der Hinweis: „… ist hier aber
schon als versendet markiert. Bitte prüfen.“ Ist sie im Shop schon storniert (oder über everysize
vollständig erstattet; eine Teilerstattung, z. B. aus Kulanz, zählt nicht), gibt es keinen Hinweis; wird sie danach
storniert oder vollständig erstattet, verschwindet der Hinweis wieder. Der Hinweis nennt nur die Bestellnummer, es geht keine E-Mail
raus. **Das Plugin ändert dabei nichts:** keine Daten der Bestellung oder der Wawi, kein
Statuswechsel, keine Bestandsbuchung, keine Erstattung, keine Meldung an everysize – das
entscheidet der Händler. Die Abfrage läuft nach Import und Export, betrachtet Bestellungen der
letzten 30 Tage (Anlegedatum), liest höchstens 500 Zeilen je Lauf (bei mehr die jüngsten); ein Fehler dabei (keine Antwort,
401, 404, 5xx, ungültige Antwort) steht höchstens einmal je Tag und Fehlerart als Warnung im Protokoll (weitere gehen ins Shop-Log), Import und Export laufen
unabhängig davon. Jede Bestellung bekommt den Hinweis genau einmal.
**Grenzen:** Eine Erstattung, die der Händler direkt in PayPal macht, ohne Storno bei everysize,
kennt das Plugin nicht. Stornos bei everysize für Bestellungen, die älter als 30 Tage sind, werden
nicht mehr abgeglichen.

## Protokoll

Backend → everysize Checkout → **Protokoll**: jeder Import- und
Meldungs-Lauf mit Zeitstempel, Ergebnis und Fehlermeldung, filterbar.
Jeder Lauf meldet, wie viele Bestellungen importiert und wie viele
Rückmeldungen (Versand, Sendungscodes, Storni, Retouren, Erstattungen)
rausgingen.

## Lizenz

MIT — siehe `LICENSE`.
