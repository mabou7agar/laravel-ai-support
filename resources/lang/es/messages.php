<?php

return [
    'api' => [
        'request_completed' => 'Solicitud completada.',
        'request_failed' => 'Error en la solicitud.',
    ],

    'agent' => [
        'no_results_found' => 'No se encontraron resultados.',
        'no_more_results' => 'No hay más resultados que mostrar. Ya has visto los :count resultados.',
        'reached_end_of_results' => 'Has llegado al final. Se han mostrado los :count resultados.',
        'rag_no_relevant_info' => 'No pude encontrar información relevante. ¿Podrías reformular tu pregunta?',
        'rag_remote_unreachable' => 'No pude conectar con el nodo remoto en este momento. Inténtalo de nuevo en un momento.',
        'rag_node_not_found' => 'No pude encontrar un nodo conectado que posea estos datos.',
        'rag_model_not_found' => 'No pude relacionar esta solicitud con un modelo de datos disponible.',
        'rag_lookup_failed' => 'No pude completar la búsqueda de datos en este momento. Inténtalo de nuevo.',

        'node_no_remote_specified' => 'No se especificó ningún nodo remoto.',
        'node_matching_remote_not_found' => "No pude encontrar un nodo remoto que coincida con ':resource'.",
        'node_unreachable' => "No pude conectar con el nodo remoto ':node':location (:summary). Verifica que el nodo esté en ejecución e inténtalo de nuevo.",

        'selection_unrecognized' => 'No pude entender a qué elemento te refieres. ¿Podrías ser más específico?',
        'selection_item_not_found' => 'No pude encontrar el elemento n.º :position de la lista anterior. Comprueba el número e inténtalo de nuevo.',
        'selection_details_unavailable' => 'Encontré :type pero no pude recuperar sus detalles. Inténtalo de nuevo.',
    ],

    'structured_collection' => [
        'awaiting_confirmation' => "Por favor, confirme los datos recopilados:\n:summary",
        'fields' => [
            'name' => 'Nombre',
            'full_name' => 'Nombre completo',
            'first_name' => 'Nombre',
            'last_name' => 'Apellido',
            'email' => 'Correo electrónico',
            'phone' => 'Teléfono',
            'topic' => 'Tema',
            'subject' => 'Asunto',
            'message' => 'Mensaje',
            'company' => 'Empresa',
            'address' => 'Dirección',
            'city' => 'Ciudad',
            'country' => 'País',
            'date' => 'Fecha',
            'budget' => 'Presupuesto',
        ],
    ],
];
