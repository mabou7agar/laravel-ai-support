<?php

return [
    'api' => [
        'request_completed' => 'Requête terminée.',
        'request_failed' => 'Échec de la requête.',
    ],

    'agent' => [
        'no_results_found' => 'Aucun résultat trouvé.',
        'no_more_results' => 'Plus aucun résultat à afficher. Vous avez vu les :count résultats.',
        'reached_end_of_results' => 'Vous êtes arrivé à la fin. Les :count résultats ont tous été affichés.',
        'rag_no_relevant_info' => "Je n'ai trouvé aucune information pertinente. Pourriez-vous reformuler votre question ?",
        'rag_remote_unreachable' => "Je n'ai pas pu joindre le nœud distant pour le moment. Veuillez réessayer dans un instant.",
        'rag_node_not_found' => "Je n'ai pas trouvé de nœud connecté possédant ces données.",
        'rag_model_not_found' => "Je n'ai pas pu associer cette demande à un modèle de données disponible.",
        'rag_lookup_failed' => "Je n'ai pas pu effectuer la recherche de données pour le moment. Veuillez réessayer.",

        'node_no_remote_specified' => 'Aucun nœud distant spécifié.',
        'node_matching_remote_not_found' => "Je n'ai pas trouvé de nœud distant correspondant à ':resource'.",
        'node_unreachable' => "Je n'ai pas pu joindre le nœud distant ':node':location (:summary). Veuillez vérifier que le nœud est en cours d'exécution et réessayer.",

        'selection_unrecognized' => "Je n'ai pas compris à quel élément vous faites référence. Pourriez-vous être plus précis ?",
        'selection_item_not_found' => "Je n'ai pas trouvé l'élément n° :position dans la liste précédente. Veuillez vérifier le numéro et réessayer.",
        'selection_details_unavailable' => "J'ai trouvé :type mais je n'ai pas pu récupérer ses détails. Veuillez réessayer.",
    ],

    'structured_collection' => [
        'awaiting_confirmation' => "Veuillez confirmer les données collectées :\n:summary",
        'fields' => [
            'name' => 'Nom',
            'full_name' => 'Nom complet',
            'first_name' => 'Prénom',
            'last_name' => 'Nom de famille',
            'email' => 'E-mail',
            'phone' => 'Téléphone',
            'topic' => 'Sujet',
            'subject' => 'Sujet',
            'message' => 'Message',
            'company' => 'Entreprise',
            'address' => 'Adresse',
            'city' => 'Ville',
            'country' => 'Pays',
            'date' => 'Date',
            'budget' => 'Budget',
        ],
    ],
];
