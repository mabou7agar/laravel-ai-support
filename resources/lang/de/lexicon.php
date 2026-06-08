<?php

return [
    'language' => [
        'name' => 'Deutsch',
    ],

    'intent' => [
        'confirm' => ['ja', 'j', 'ok', 'okay', 'bestätigen', 'klar', 'sicher', 'jap', 'jo', 'genau', 'fortfahren', 'weiter', 'mach es', 'los'],
        'reject' => ['nein', 'n', 'abbrechen', 'stopp', 'stop', 'beenden', 'egal', 'vergiss es', 'ablehnen', 'nö', 'nee'],
        'greeting' => ['hi', 'hallo', 'hey', 'grüße', 'guten morgen', 'guten tag', 'guten abend', 'servus', 'moin'],
        'cancel' => ['abbrechen', 'stopp', 'stop', 'beenden', 'verlassen', 'abbruch', 'egal', 'vergiss es'],
        'deny' => ['nein', 'n', 'abbrechen', 'ändern', 'anpassen', 'bearbeiten'],
        'done' => ['fertig', 'erledigt', 'das ist alles', 'keine änderungen mehr', 'sieht jetzt gut aus'],
        'modify' => ['ändern', 'anpassen', 'bearbeiten', 'aktualisieren', 'ersetzen', 'stattdessen'],
        'duplicate_use' => ['verwenden', 'nutzen', 'ja'],
        'duplicate_create' => ['neu', 'erstellen'],
        'possessive' => ['mein', 'meine', 'meiner', 'unser', 'unsere'],
        'query_prefixes' => ['liste ', 'zeige ', 'zeig ', 'hole ', 'finde ', 'suche ', 'anzeigen ', 'ansehen ', 'was sind ', 'wie viele ', 'zähle '],
    ],

    'response' => [
        'affirmative' => ['ja', 'j', 'wahr', 'bestätigen', 'genehmigt'],
        'negative' => ['nein', 'n', 'falsch', 'ablehnen', 'verweigern'],
    ],

    'relation' => [
        'use_existing' => ['vorhandene verwenden', 'diese verwenden', 'sie verwenden', 'vorhandene auswählen', 'bestehende auswählen', 'vorhandene behalten'],
        'create_new' => ['neue erstellen', 'eine neue', 'fehlende erstellen', 'neue hinzufügen', 'neu anlegen'],
    ],

    'entities' => [
        'aliases' => [
            'invoice' => [
                'rechnung',
                'rechnungen',
                'ausgangsrechnung',
                'ausgangsrechnungen',
                'kundenrechnung',
                'kundenrechnungen',
                'فاتورة',
                'فواتير',
                'فاتورة بيع',
                'فواتير بيع',
            ],
            'bill' => [
                'eingangsrechnung',
                'eingangsrechnungen',
                'lieferantenrechnung',
                'lieferantenrechnungen',
                'einkaufsrechnung',
                'einkaufsrechnungen',
                'kreditorenrechnung',
                'rechnung des lieferanten',
                'فاتورة مشتريات',
                'فواتير مشتريات',
                'فاتورة مورد',
                'فواتير مورد',
            ],
        ],
    ],

    'user' => [
        'current_placeholders' => [
            'current_user_id',
            'aktuelle benutzer-id',
            'aktueller benutzer',
            'authentifizierter benutzer',
            'angemeldeter benutzer',
            'meine benutzer-id',
            'ich',
            'mich',
            'selbst',
        ],
    ],
];
