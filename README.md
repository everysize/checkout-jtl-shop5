# everysize Checkout — Plugin für JTL-Shop 5

Bindet einen JTL-Shop 5 an den everysize-Direktkauf an: Bestellungen, die
Kundinnen auf everysize.com aufgeben, landen als Bestellung im Shop;
Bestellnummer, Versand mit Sendungsnummern, Storno, Retoure und Erstattungen
gehen zurück an everysize.

Plugin-ID `everysize_checkout`, Version 1.0.2, MIT-Lizenz.

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
| Shop → everysize | Versand mit Sendungsnummer und Versanddienstleister | Versanddatum gesetzt oder Sendungsnummer im Bestell-Tab |
| Shop → everysize | Sendungsnummer nachgetragen oder korrigiert | im Bestell-Tab geändert → nächster Lauf |
| Shop → everysize | Erstattung (ganz oder teilweise) | Button im Bestell-Tab |
| Shop → everysize | volle Erstattung | automatisch nach einem Storno **vor** dem Versand (abschaltbar) |
| Shop → everysize | Storno `MERCHANT_DECLINE` | Bestellung storniert (Wawi oder Backend), **bevor** der Versand gemeldet war |
| Shop → everysize | Retoure `RETOUR` | Bestellung storniert, **nachdem** der Versand gemeldet war |

* **Zuordnung:** Bestellpositionen über `sku` = JTL-**Artikelnummer**
  (`tartikel.cArtNr`) der Variante. Exakter Treffer vor Groß-/Kleinschreibung,
  Vaterartikel sind nie matchbar; zwei gleich gute Treffer sind ein Fehler.
* **Preise:** Bestellung mit den bei everysize bezahlten Bruttopreisen.
  Netto und Mehrwertsteuer rechnet das Plugin mit dem Steuersatz der
  Steuerklasse des Artikels für das Lieferland zurück (tsteuersatz/
  tsteuerzoneland). Die Positionen werden ungerundet gespeichert, genau wie
  JTL es beim Checkout tut — die Wawi summiert sie korrekt.
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
* **Erstattung:** Bestell-Tab → Betrag (Restbetrag vorbelegt) → „Erstatten“.
  Jedes Formular trägt einen eigenen Idempotency-Key, ein doppelt
  abgeschicktes Formular erstattet einmal. Erstattungen laufen **nur** über
  den everysize-Reiter — Gutschriften in der JTL-Wawi bewegen kein Geld
  (JTL-Shop kennt keinen Refund-Hook für Zahlungsart-Plugins).
* **Automatische Erstattung:** Nach einem gemeldeten Storno **vor** dem
  Versand erstattet das Plugin im nächsten Lauf den vollen Betrag, sobald
  keine Position der Bestellung mehr aktiv ist — es wurde nichts versendet,
  der Kundin steht alles zu. Retouren nicht (der Händler erstattet nach
  Wareneingang, ggf. mit Abzug). Erstattet werden nur Stornos ab dem
  Einschalten; beim Abschalten bleibt der Zeitpunkt erhalten.

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
Die Sendungsnummer kommt aus dem Bestell-Tab („Sendungsnummer melden“) oder
aus dem Wawi-Lieferschein (`tversand.cIdentCode`) — die manuelle Eingabe hat
Vorrang. Wird im Bestell-Tab später eine andere Nummer eingetragen, meldet
das Plugin sie als Nachmeldung; die Kundin bekommt keine Mail. Der
Versanddienstleister aus der Wawi (`cLogistik`) wird über die
Einstellung „Versanddienstleister“/Carryer-Mapping auf die everysize-Schreibweise
abgebildet.

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

## Protokoll

Backend → everysize Checkout → **Protokoll**: jeder Import- und
Meldungs-Lauf mit Zeitstempel, Ergebnis und Fehlermeldung, filterbar.
Jeder Lauf meldet, wie viele Bestellungen importiert und wie viele
Rückmeldungen (Versand, Sendungscodes, Storni, Retouren, Erstattungen)
rausgingen.

## Lizenz

MIT — siehe `LICENSE`.
