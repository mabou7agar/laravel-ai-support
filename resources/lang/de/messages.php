<?php

return [
    'api' => [
        'request_completed' => 'Anfrage abgeschlossen.',
        'request_failed' => 'Anfrage fehlgeschlagen.',
    ],

    'agent' => [
        'no_results_found' => 'Keine Ergebnisse gefunden.',
        'no_more_results' => 'Keine weiteren Ergebnisse. Sie haben alle :count Ergebnisse gesehen.',
        'reached_end_of_results' => 'Sie haben das Ende erreicht. Alle :count Ergebnisse wurden angezeigt.',
        'rag_no_relevant_info' => 'Ich konnte keine relevanten Informationen finden. Könnten Sie Ihre Frage bitte umformulieren?',
        'rag_remote_unreachable' => 'Ich konnte den Remote-Knoten gerade nicht erreichen. Bitte versuchen Sie es gleich noch einmal.',
        'rag_node_not_found' => 'Ich konnte keinen verbundenen Knoten finden, der diese Daten besitzt.',
        'rag_model_not_found' => 'Ich konnte diese Anfrage keinem verfügbaren Datenmodell zuordnen.',
        'rag_lookup_failed' => 'Ich konnte die Datenabfrage gerade nicht abschließen. Bitte versuchen Sie es erneut.',

        'node_no_remote_specified' => 'Kein Remote-Knoten angegeben.',
        'node_matching_remote_not_found' => "Ich konnte keinen Remote-Knoten finden, der ':resource' entspricht.",
        'node_unreachable' => "Ich konnte den Remote-Knoten ':node':location (:summary) nicht erreichen. Bitte stellen Sie sicher, dass der Knoten läuft, und versuchen Sie es erneut.",

        'selection_unrecognized' => 'Ich konnte nicht verstehen, welches Element Sie meinen. Könnten Sie genauer sein?',
        'selection_item_not_found' => 'Ich konnte Element Nr. :position in der vorherigen Liste nicht finden. Bitte überprüfen Sie die Nummer und versuchen Sie es erneut.',
        'selection_details_unavailable' => 'Ich habe :type gefunden, konnte aber die Details nicht abrufen. Bitte versuchen Sie es erneut.',
    ],

    'structured_collection' => [
        'awaiting_confirmation' => "Bitte bestätigen Sie die erfassten Daten:\n:summary",
        'fields' => [
            'name' => 'Name',
            'full_name' => 'Vollständiger Name',
            'first_name' => 'Vorname',
            'last_name' => 'Nachname',
            'email' => 'E-Mail',
            'phone' => 'Telefon',
            'topic' => 'Thema',
            'subject' => 'Betreff',
            'message' => 'Nachricht',
            'company' => 'Firma',
            'address' => 'Adresse',
            'city' => 'Stadt',
            'country' => 'Land',
            'date' => 'Datum',
            'budget' => 'Budget',
        ],
    ],
];
