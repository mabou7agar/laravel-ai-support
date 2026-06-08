<?php

return [
    'language' => [
        'name' => 'Português',
    ],

    'intent' => [
        'confirm' => ['sim', 's', 'ok', 'está bem', 'confirmar', 'claro', 'certo', 'isso', 'pois', 'continuar', 'avançar', 'faça', 'pode ser'],
        'reject' => ['não', 'n', 'cancelar', 'parar', 'abortar', 'deixa', 'deixa estar', 'rejeitar', 'nada'],
        'greeting' => ['oi', 'olá', 'ei', 'saudações', 'bom dia', 'boa tarde', 'boa noite'],
        'cancel' => ['cancelar', 'parar', 'sair', 'fechar', 'abortar', 'deixa', 'deixa estar'],
        'deny' => ['não', 'n', 'cancelar', 'mudar', 'modificar', 'editar'],
        'done' => ['concluído', 'terminado', 'é tudo', 'sem mais alterações', 'está bom assim'],
        'modify' => ['mudar', 'modificar', 'editar', 'atualizar', 'substituir', 'em vez'],
        'duplicate_use' => ['utilizar', 'sim'],
        'duplicate_create' => ['novo', 'criar'],
        'possessive' => ['meu', 'minha', 'nosso', 'nossa'],
        'query_prefixes' => ['listar ', 'mostrar ', 'obter ', 'encontrar ', 'pesquisar ', 'exibir ', 'ver ', 'quais são ', 'quantos ', 'contar '],
    ],

    'response' => [
        'affirmative' => ['sim', 's', 'verdadeiro', 'confirmar', 'aprovado'],
        'negative' => ['não', 'n', 'falso', 'rejeitar', 'recusar'],
    ],

    'relation' => [
        'use_existing' => ['utilizar existente', 'utilizar este', 'utilizar', 'escolher existente', 'selecionar existente', 'manter existente'],
        'create_new' => ['criar novo', 'novo', 'criar em falta', 'adicionar novo', 'fazer novo'],
    ],

    'entities' => [
        'aliases' => [
            'invoice' => [
                'fatura',
                'faturas',
                'fatura de venda',
                'faturas de venda',
                'fatura de cliente',
                'faturas de cliente',
                'فاتورة',
                'فواتير',
                'فاتورة بيع',
                'فواتير بيع',
            ],
            'bill' => [
                'fatura de compra',
                'faturas de compra',
                'fatura de fornecedor',
                'faturas de fornecedor',
                'conta a pagar',
                'contas a pagar',
                'documento de fornecedor',
                'fatura do fornecedor',
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
            'id do utilizador atual',
            'utilizador atual',
            'utilizador autenticado',
            'utilizador da sessão',
            'o meu id de utilizador',
            'eu',
            'eu próprio',
            'mim',
        ],
    ],
];
