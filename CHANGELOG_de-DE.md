# Änderungsprotokoll

## 1.0.2

* Erstattungen im Bestell-Detail mit Datum in der Zeitzone des Shops statt
  der von PayPal gelieferten (US-)Zeit
* Automatische Erstattung in den Rückmeldungen mit Erstattungs-ID und
  „voller Betrag“ statt der ID in der Spalte Grund/Betrag

## 1.0.1

* Bestell-Detail: Versand, Sendungsnummer und Storno als Normalfall über die
  JTL-Wawi (Hinweis oben); Sendungsnummer und Retoure von Hand stehen
  eingeklappt als „Korrektur, falls nötig“ am Ende
* Retoure von Hand bietet nur noch Positionen an, deren Retoure noch nicht
  gemeldet ist
* Beträge als „1.234,50 €“ statt „1234,50 EUR“; Abstände der Formularfelder

## 1.0.0

* Import von everysize-Bestellungen als JTL-Bestellung (Kunde, Adressen,
  Warenkorbpositionen mit ungerundetem Netto, Versandposition,
  Zahlungseingang, Bestand, Verknüpfung — in einer Transaktion)
* Bestellnummern aus dem JTL-Zähler (createOrderNo-Semantik), direkt an
  everysize gemeldet
* Rückmeldungen: Versand mit Sendungsnummer/Versanddienstleister,
  Sendungsnachmeldung, Storno `MERCHANT_DECLINE`, Retoure `RETOUR`,
  automatische volle Erstattung nach Storno vor dem Versand (abschaltbar),
  manuelle Erstattung über den Bestell-Tab (Idempotency-Key)
* Admin-Tabs: Einstellungen mit Verbindungstest, Bestellungen mit Detail
  (Positionen, Rückmeldungen, Sendungsnummer, Retoure, Erstattung) und
  Abgleich-Button, Protokoll mit Filter
* CLI `everysize_checkout:sync` (+ `order:import`/`order:export`),
  JTL-Cronjob mit 5-Minuten-Drossel, Webcron-Endpoint mit Token
* Steuerermittlung je Steuerklasse und Lieferland, Währungs- und
  Versandart-Prüfung vor dem Import, SKU-Matching mit Vaterschutz
