<?php

return [
    'api' => [
        'request_completed' => 'Pedido concluído.',
        'request_failed' => 'O pedido falhou.',
    ],

    'agent' => [
        'no_results_found' => 'Nenhum resultado encontrado.',
        'no_more_results' => 'Não há mais resultados para mostrar. Já viu todos os :count resultados.',
        'reached_end_of_results' => 'Chegou ao fim. Todos os :count resultados foram mostrados.',
        'rag_no_relevant_info' => 'Não consegui encontrar informações relevantes. Poderia reformular a sua pergunta?',
        'rag_remote_unreachable' => 'Não consegui contactar o nó remoto neste momento. Tente novamente dentro de instantes.',
        'rag_node_not_found' => 'Não consegui encontrar um nó ligado que detenha estes dados.',
        'rag_model_not_found' => 'Não consegui associar este pedido a um modelo de dados disponível.',
        'rag_lookup_failed' => 'Não consegui concluir a consulta de dados neste momento. Tente novamente.',

        'node_no_remote_specified' => 'Nenhum nó remoto especificado.',
        'node_matching_remote_not_found' => "Não consegui encontrar um nó remoto correspondente a ':resource'.",
        'node_unreachable' => "Não consegui contactar o nó remoto ':node':location (:summary). Verifique se o nó está em execução e tente novamente.",

        'selection_unrecognized' => 'Não consegui perceber a que item se refere. Poderia ser mais específico?',
        'selection_item_not_found' => 'Não consegui encontrar o item n.º :position na lista anterior. Verifique o número e tente novamente.',
        'selection_details_unavailable' => 'Encontrei :type mas não consegui obter os detalhes. Tente novamente.',
    ],

    'structured_collection' => [
        'awaiting_confirmation' => "Confirme os dados recolhidos:\n:summary",
        'fields' => [
            'name' => 'Nome',
            'full_name' => 'Nome completo',
            'first_name' => 'Nome',
            'last_name' => 'Sobrenome',
            'email' => 'E-mail',
            'phone' => 'Telefone',
            'topic' => 'Assunto',
            'subject' => 'Assunto',
            'message' => 'Mensagem',
            'company' => 'Empresa',
            'address' => 'Endereço',
            'city' => 'Cidade',
            'country' => 'País',
            'date' => 'Data',
            'budget' => 'Orçamento',
        ],
    ],
];
