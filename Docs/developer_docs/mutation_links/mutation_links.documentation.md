# Mutation Links im UXON-Editor

## Zweck und Änderungsumfang

Commit `8a9f532c6c14d8a366a2dfb0072acd85ccb6727e` vom 16.10.2026 (`NEW display mutation links inside uxon editor`) ergänzt visuelle Hinweise auf konfigurierte Mutationen im UXON-Editor. Die Implementierung verteilt sich auf zwei Dateien:

| Datei | Änderung |
| --- | --- |
| [`Actions/UxonAutosuggest.php`](../../../Actions/UxonAutosuggest.php) | Neuer Request-Typ `mutation_hints`; Ermittlung aktiver Objektaktions-Mutationen und Auflösung ihrer JSONPaths in konkrete Editor-Pfade. |
| [`Facades/AbstractAjaxFacade/Elements/JsonEditorTrait.php`](../../../Facades/AbstractAjaxFacade/Elements/JsonEditorTrait.php) | Erkennung unterstützter Editoren, asynchrones Laden, Rendern der Icons, Hover-Markierung und Aktualisierung bei Editor-Ereignissen. |

Die Hinweise machen sichtbar, welche Bereiche der gerade bearbeiteten Aktionskonfiguration von Mutationen adressiert werden. Sie verändern weder den Editor-Inhalt noch gespeicherte Modelldaten. Es handelt sich nicht um eine Vorschau des Ergebnisses einer Mutation.

Trotz des Namens „Mutation Links“ sind die neuen Elemente keine anklickbaren Navigationslinks: Die Implementierung erzeugt Icons mit Hover-Verhalten, aber keinen Klick-Handler und keinen Sprung zum Mutationseditor.

## Sichtbares Verhalten

An einer betroffenen Baumzeile erscheint rechts ein Font-Awesome-Icon `fa-code-fork`. Links erhält die Zeile einen gestrichelten, farbigen Rand. Mehrere Hinweise für dieselbe Zeile werden als mehrere Icons nebeneinander dargestellt.

Beim Überfahren eines Icons mit der Maus:

- erscheint der Mutationsname als dunkles Label; fehlt er, wird `Mutation` angezeigt;
- enthält das native `title` zusätzlich Operation und ursprünglichen JSONPath, beispielsweise `Spalten ausblenden (change: $.columns[*].hidden)`;
- werden die Zielzeile und ihre im Editor vorhandenen Nachfolger rekursiv hellgelb markiert.

Beim Verlassen des Icons wird die gelbe Markierung entfernt. Der gestrichelte Rand und die Icons bleiben bestehen.

Die sechs Farben `#d9534f`, `#337ab7`, `#5cb85c`, `#f0ad4e`, `#8e44ad` und `#008b8b` werden zyklisch **pro Hint-Eintrag** vergeben. Eine Farbe identifiziert daher weder dauerhaft eine bestimmte Mutation noch einen Operationstyp. Treffen mehrere Hinweise dieselbe Zeile, bestimmt der zuletzt gerenderte Hinweis deren Randfarbe; die Icons behalten ihre jeweilige Farbe.

## Voraussetzungen und unterstützter Umfang

`JsonEditorTrait::hasMutationHints()` aktiviert die Funktion ausschließlich, wenn alle folgenden Bedingungen erfüllt sind:

| Bedingung | Prüfung |
| --- | --- |
| UXON-Editor | Das Widget ist ein `InputUxon`. Ein gewöhnliches `InputJson` genügt nicht. |
| Attributbindung | Das Widget ist an ein Attribut gebunden. |
| Objekt | Das Metaobjekt ist exakt `exface.Core.OBJECT_ACTION` (`isExactly()`). |
| Attribut | Der Attributalias ist `CONFIG_UXON`; Groß-/Kleinschreibung wird ignoriert. |
| Ziel-UID-Widget | Im ID-Space des Editors lässt sich das Widget `action_uid_field` auflösen. |

`getMutationHintsTargetWidget()` nutzt dafür `WidgetLinkFactory::createFromWidget()` und speichert das Auflösungsergebnis innerhalb der Elementinstanz. Es durchsucht nicht die ganze Seite. Ein fehlendes Widget führt über den abgefangenen `WidgetNotFoundError` dazu, dass keine Mutation-Hint-Funktionen generiert werden.

Der vorhandene Standardeditor von `exface.Core.OBJECT_ACTION` enthält bereits ein `InputHidden` mit `attribute_alias: "UID"` und `id: "action_uid_field"`. Angepasste Aktionsdialoge müssen diesen Bezug ebenfalls bereitstellen. Die UID wird beim Laden der Hinweise aus dem aktuellen Widgetwert gelesen, nicht fest in den generierten JavaScript-Code geschrieben.

Weitere Einschränkungen:

- Icons und Markierungen werden nur im Modus `tree` dargestellt, nicht in `code` oder `view`.
- `autosuggest: false` schaltet die Mutation-Hints nicht ab: `buildJsAutosuggestFunction()` liefert deren Funktionen auch ohne die regulären Autosuggest-Funktionen.
- Ohne aktuellen Wert in `action_uid_field` werden die lokal gespeicherten Hinweise geleert; es erfolgt kein Request.
- Serverseitig werden ausschließlich Mutationen des `ObjectActionMutationPoint` berücksichtigt, die nicht deaktiviert und Instanzen von `GenericUxonMutation` sind, einschließlich entsprechender Unterklassen.
- Ist der Mutation Point deaktiviert, liefert der Server keine Hinweise. Der bestehende globale Schalter `MUTATIONS.ENABLED: false` wird über `isDisabled()` berücksichtigt.

Das Feature führt keine neue UXON-Eigenschaft und keinen eigenen Konfigurationsschalter ein.

## Datenfluss und Request-Vertrag

```text
InputUxon für OBJECT_ACTION.CONFIG_UXON
  -> _scheduleMutationHints(editor), 300 ms Verzögerung
  -> POST an die Ajax-URL der Facade
  -> UxonAutosuggest, input=mutation_hints
  -> ObjectActionMutationPoint.getMutations(target)
  -> JSONPaths je Operation auswerten
  -> Hint-Liste mit konkreten Pfaden
  -> _renderMutationHints(editor)
  -> Icons und Hover-Markierungen im Baum
```

### Request

Der Client sendet einen formularcodierten POST (`Content-Type: application/x-www-form-urlencoded`) mit diesen Feldern:

| Feld | Inhalt |
| --- | --- |
| `action` | `exface.Core.UxonAutosuggest` |
| `input` | `mutation_hints` |
| `target_uid` | Aktuelle UID der Objektaktion aus `action_uid_field`. |
| `uxon` | `JSON.stringify(oEditor.get())`: der aktuelle Editor-Inhalt, einschließlich noch nicht gespeicherter Änderungen. |

Der Request wird mit `URLSearchParams` aufgebaut und per `fetch()` mit `mode: 'cors'` und `cache: 'no-cache'` verschickt.

`UxonAutosuggest::perform()` prüft `TYPE_MUTATION_HINTS` vor der regulären Schema- und Autosuggest-Verarbeitung. Dieser Zweig benötigt deshalb keine Request-Parameter wie `schema`, `prototype`, `object`, `text` oder den aktuellen Editor-Knotenpfad.

### Response

Die Antwort ist ein direktes JSON-Array, **kein** Objekt mit `values` oder `templates`. Jeder Eintrag enthält:

| Feld | Typ | Bedeutung |
| --- | --- | --- |
| `name` | String oder `null` | Name der Mutation. |
| `operation` | String | `append`, `insert`, `change`, `replace`, `remove` oder `move`. |
| `json_path` | String | Ursprünglicher JSONPath aus der Mutationskonfiguration. |
| `path` | Array | Konkreter Pfad ab der UXON-Wurzel mit Property-Namen und Array-Indizes für JSONEditor. `[]` bezeichnet die Wurzel. |

Ein JSONPath mit mehreren Treffern kann mehrere Hint-Einträge erzeugen. Die Einträge werden nicht nach Mutation oder Zielknoten zusammengefasst.

## Serverseitige Auflösung

### Auswahl der Mutationen

`suggestMutationHints()` trimmt `target_uid` und liefert bei leerem Ergebnis `[]`. Anschließend holt es den `ObjectActionMutationPoint` über den Mutator und erstellt ein Ziel:

```php
new MetaObjectUidMutationTarget('exface.Core.OBJECT_ACTION', $targetUid)
```

Über `getMutations($target)` wird die bestehende Mutationsauswahl des Model Loaders verwendet. Die neue Action-Abfrage liest nicht selbst direkt aus Mutationstabellen.

Für jede aktive `GenericUxonMutation` wird `exportUxonObject()->toArray()` ausgewertet:

| Operation | Ausgewertete Pfade | Bedeutung des markierten Bereichs |
| --- | --- | --- |
| `append` | Schlüssel der Operationskonfiguration | Ziel, an das angehängt werden soll; nicht die neu hinzuzufügenden Elemente. |
| `insert` | Schlüssel | Referenzposition, vor der eingefügt werden soll. |
| `change` | Schlüssel | Zu setzende Property beziehungsweise deren auflösbarer Editor-Kontext. |
| `replace` | Schlüssel | Zu ersetzender Bereich. |
| `remove` | Werte der Pfadliste | Zu entfernender Bereich. |
| `move` | Sowohl Quellpfad als auch Zielpfad | Beide Seiten der Verschiebung. Beide erhalten `operation: "move"`; die Response enthält kein zusätzliches Quell-/Zielkennzeichen. |

### Vom JSONPath zum Editor-Pfad

`createMutationHints()` übersetzt einen JSONPath nicht durch String-Zerlegung, sondern über einen temporären Marker:

1. Für jeden einzelnen Pfad wird ein neues `JsonObject` aus dem aktuellen UXON-Array erstellt.
2. `JsonObject::set($jsonPath, $marker)` setzt einen eindeutigen Marker `['__exf_mutation_target__' => uniqid('', true)]` an den durch den Pfad adressierten Stellen.
3. `findMutationMarkerPaths()` durchläuft das resultierende Array rekursiv und sucht per strengem Vergleich nach dem Marker.
4. Aus jeder Fundstelle entstehen `name`, `operation`, `json_path` und der aufgesammelte `path`.

Damit übernimmt die vorhandene JSONPath-Bibliothek die Auswertung von Filtern und Mehrfachtreffern. Property-Namen und numerische Array-Indizes bleiben im Pfadarray erhalten.

Die temporäre Kopie wird nach der Auflösung verworfen. Weder wird `GenericUxonMutation::apply()` aufgerufen noch werden die konfigurierten neuen Werte angewendet. Alle Operationspfade werden unabhängig gegen denselben übermittelten Editor-Inhalt ausgewertet, nicht nacheinander gegen ein bereits mutiertes Zwischenergebnis. Ein Hint belegt deshalb eine auflösbare Zieladresse, nicht die erfolgreiche Anwendung oder eine tatsächliche Wertänderung zur Laufzeit.

Die tatsächliche Mutation einer Objektaktion erfolgt weiterhin separat über `ObjectActionMutationPoint::onActionLoadedApplyMutations()` beim Laden der Aktion.

## Clientseitiger Lebenszyklus

Die JavaScript-Funktionsnamen erhalten das vorhandene elementspezifische Präfix aus `buildJsFunctionPrefix()`. Dadurch bleiben mehrere Editorinstanzen voneinander getrennt.

| Einstiegspunkt | Verhalten |
| --- | --- |
| Initialisierung durch `buildJsJsonEditor()` | Plant das erste Laden nach der Editorinitialisierung ein. |
| `buildJsValueSetter()` | Setzt den Text, klappt den Baum auf und plant das Laden erneut ein, etwa nach einem Prefill. |
| `onChangeJSON` | Plant eine neue serverseitige Auflösung gegen den geänderten Inhalt ein. |
| `onExpand` | Rendert die bereits gespeicherten Hinweise erneut, ohne einen eigenen Request auszulösen. |
| `onModeChange` nach `tree` | Rendert die vorhandenen Hinweise nach 10 ms erneut. Der Handler selbst fordert keine neuen Hinweise an. |
| `onModeChange` in einen anderen Modus | Entfernt Icons und Markierungsklassen aus dem DOM. |

### Laden und lokaler Zustand

`_scheduleMutationHints()` verwendet einen 300-ms-Timer. Erneutes Einplanen löscht den vorherigen Timer und bündelt dadurch kurz aufeinanderfolgende Änderungen. Nach Ablauf des Timers wird nur im Modus `tree` weitergearbeitet.

Die Editorinstanz trägt folgenden Zustand:

| Property | Zweck |
| --- | --- |
| `_exfMutationHintsTimer` | Timer für das verzögerte Laden. |
| `_exfMutationHintsRequest` | Aufsteigende Nummer der Ladeversuche; Antworten älterer Requests werden verworfen, wenn inzwischen eine neuere Nummer gesetzt wurde. |
| `_exfMutationHints` | Zuletzt übernommene Hint-Liste für erneutes Rendern. |

Laufende Requests werden nicht abgebrochen. Der Nummernvergleich verhindert die Übernahme älterer Antworten nach einem neueren nummerierten Ladeversuch, ist aber kein vollständiger Abgleich mit jeder zwischenzeitlichen Änderung von UID oder Editor-Inhalt.

### Rendern und Hover

`_renderMutationHints()` entfernt zuerst vorhandene Icons, die Klassen `uxoneditor-mutation-target` und `uxoneditor-mutation-hover` sowie die Farbvariable von zuvor markierten Zeilen.

Anschließend wird jeder konkrete Pfad über `oEditor.node.findNodeByPath()` gesucht. Ist er nicht auflösbar, wird der Pfad schrittweise verkürzt, bis ein Elternknoten oder die Wurzel gefunden wird. Ein gefundener Knoten ohne `dom.tr` wird übersprungen; der Renderer erzwingt kein Aufklappen.

Am Zielknoten werden die Randklasse und `--uxon-mutation-color` gesetzt. In der letzten Tabellenzelle wird ein absolut rechts positionierter Container `uxoneditor-mutation-icons` angelegt und das Icon eingefügt. Der Mutationsname wird über `dataset.label` und das CSS-Pseudoelement `::after` angezeigt.

Die beiden Mausereignisse rufen `_toggleMutationHighlight()` auf. Diese Funktion schaltet die Hover-Klasse rekursiv am Knoten und seinen `childs` um. Sie hebt damit einen Teilbaum hervor, nicht einen Textbereich im Codeeditor. Beim Auf- oder Zuklappen wird die Darstellung aus dem Hint-Cache neu aufgebaut.

## Beispiel

Aktueller Editor-Inhalt:

```json
{
    "columns": [
        {"attribute_alias": "NAME", "hidden": false},
        {"attribute_alias": "APP", "hidden": false}
    ]
}
```

Operationskonfiguration einer aktiven Mutation mit dem Namen `Spalten ausblenden`:

```json
{
    "change": {
        "$.columns[*].hidden": true
    }
}
```

Die Hint-Abfrage liefert für diese Konfiguration:

```json
[
    {
        "name": "Spalten ausblenden",
        "operation": "change",
        "json_path": "$.columns[*].hidden",
        "path": ["columns", 0, "hidden"]
    },
    {
        "name": "Spalten ausblenden",
        "operation": "change",
        "json_path": "$.columns[*].hidden",
        "path": ["columns", 1, "hidden"]
    }
]
```

Beide `hidden`-Zeilen erhalten ein Icon. Ihre Werte bleiben im Editor `false`. Zeigt der Mutationspfad stattdessen auf ein ganzes Objekt oder Array, umfasst die Hover-Markierung auch dessen vorhandene Kindknoten.

## Fehlerbehandlung und Grenzen

Ein `InvalidJsonPathException` beim Setzen des Markers wird über den Workbench-Logger protokolliert. Für diesen Pfad entsteht kein Hinweis; die Verarbeitung der übrigen Pfade kann fortgesetzt werden. Andere serverseitige Ausnahmen werden von dieser Methode nicht abgefangen.

Der Client erwartet HTTP-Status `200` und eine erfolgreiche JSON-Decodierung. Netzwerkfehler, unerwartete HTTP-Antworten und Decodierungsfehler werden mit `console.warn('Failed to load UXON mutation hints.', oError)` gemeldet. Es gibt keinen zusätzlichen Fehlerdialog. Bei solchen Fehlern werden vorhandene Hinweise nicht ausdrücklich geleert. Eine erfolgreich decodierte Response, die kein Array ist, wird dagegen als leere Hint-Liste übernommen.

Kann `oEditor.get()` den Inhalt nicht lesen, beendet der Loader diesen Versuch ohne Request und ohne zusätzliche Meldung.

Für die Interpretation der Anzeige sind folgende Grenzen wichtig:

- Kein Icon bedeutet nicht automatisch „keine Mutation“: Nicht unterstützte Mutationstypen, deaktivierte Mutationen, nicht auflösbare Pfade oder fehlende DOM-Zeilen können ebenfalls ohne Anzeige bleiben.
- Nicht vorhandene Ziel-Properties werden gemäß der `set()`-Semantik der JSONPath-Bibliothek aufgelöst. Existiert der resultierende Pfad noch nicht im Editor, kann die Anzeige auf einen vorhandenen Elternknoten zurückfallen. Die Anzeige garantiert keine exakte Visualisierung jeder möglichen Einfügeposition.
- Es gibt keine Vorher-/Nachher-Differenz, Konflikterkennung oder Simulation aufeinander aufbauender Mutationen.
- Änderungen an Mutationsdefinitionen oder allein am UID-Widget lösen in diesem Trait keinen eigenen Refresh aus. Die erneute Abfrage benötigt einen der beschriebenen Ladeauslöser.
- Der Moduswechsel nach `tree` und das Aufklappen verwenden zunächst den lokalen Cache. Die Anzeige kann bis zur nächsten Abfrage veraltet sein.
- Die Funktionen greifen auf JSONEditor-Interna wie `node`, `findNodeByPath()`, `childs` und `dom.tr` zu. Änderungen oder Updates der Bibliothek müssen diese Anbindung berücksichtigen.

## Manuelle Prüfszenarien

Diese Szenarien beschreiben die erwartete Funktion und sind kein Protokoll bereits ausgeführter UI-Tests.

| Szenario | Erwartung |
| --- | --- |
| Gespeicherte Objektaktion mit aktiver `GenericUxonMutation` öffnen | Nach dem verzögerten Laden erscheinen Icons an den auflösbaren Zielen. |
| Icon an einem Objekt oder Array überfahren und wieder verlassen | Zielzeile und vorhandene Nachfolger werden gelb und anschließend wieder normal dargestellt. |
| JSONPath mit mehreren Treffern verwenden | Für jeden konkreten Treffer wird ein Hint erzeugt. |
| Mehrere Operationen auf dieselbe Zeile richten | Mehrere Icons erscheinen in derselben Zeile. |
| `move` konfigurieren | Sowohl Quelle als auch Ziel werden bei auflösbaren Pfaden markiert. |
| UXON ändern oder per Value-Setter neu laden | Eine neue Abfrage verwendet den aktuellen Inhalt. |
| Baum auf-/zuklappen oder zwischen `tree` und `code` wechseln | Hinweise werden aus dem Cache neu gerendert beziehungsweise entfernt. |
| `autosuggest: false` setzen | Mutation-Hints bleiben im unterstützten Editor verfügbar. |
| UID leeren, `action_uid_field` weglassen oder Mutation deaktivieren | Es entstehen unter den beschriebenen Voraussetzungen keine entsprechenden Hinweise. |
| Ungültigen JSONPath oder fehlgeschlagenen Request provozieren | Server-Log beziehungsweise Browser-Konsole enthält die beschriebene Meldung. |