<?php

return [
    'language' => [
        'name' => 'Français',
    ],

    'intent' => [
        'confirm' => ['oui', 'o', 'ok', 'okay', 'confirmer', 'd\'accord', 'daccord', 'bien sûr', 'ouais', 'ouaip', 'continuer', 'allez-y', 'vas-y', 'fais-le'],
        'reject' => ['non', 'n', 'annuler', 'arrêter', 'interrompre', 'laisse tomber', 'tant pis', 'rejeter', 'nan'],
        'greeting' => ['salut', 'bonjour', 'coucou', 'salutations', 'bonjour', 'bon après-midi', 'bonsoir'],
        'cancel' => ['annuler', 'arrêter', 'quitter', 'sortir', 'interrompre', 'laisse tomber', 'tant pis'],
        'deny' => ['non', 'n', 'annuler', 'changer', 'modifier', 'éditer'],
        'done' => ['terminé', 'fini', 'c\'est tout', 'aucune autre modification', 'ça me va maintenant'],
        'modify' => ['changer', 'modifier', 'éditer', 'mettre à jour', 'remplacer', 'plutôt'],
        'duplicate_use' => ['utiliser', 'oui'],
        'duplicate_create' => ['nouveau', 'créer'],
        'possessive' => ['mon', 'ma', 'mes', 'mien', 'notre', 'nos', 'nôtre'],
        'query_prefixes' => ['lister ', 'afficher ', 'obtenir ', 'trouver ', 'rechercher ', 'montrer ', 'voir ', 'quels sont ', 'combien de ', 'compter '],
    ],

    'response' => [
        'affirmative' => ['oui', 'o', 'vrai', 'confirmer', 'approuvé'],
        'negative' => ['non', 'n', 'faux', 'rejeter', 'refuser'],
    ],

    'relation' => [
        'use_existing' => ['utiliser l\'existant', 'utiliser celui-ci', 'l\'utiliser', 'choisir l\'existant', 'sélectionner l\'existant', 'conserver l\'existant'],
        'create_new' => ['créer nouveau', 'un nouveau', 'créer le manquant', 'ajouter nouveau', 'faire nouveau'],
    ],

    'entities' => [
        'aliases' => [
            'invoice' => [
                'facture',
                'factures',
                'facture de vente',
                'factures de vente',
                'facture client',
                'factures client',
                'فاتورة',
                'فواتير',
                'فاتورة بيع',
                'فواتير بيع',
            ],
            'bill' => [
                'facture fournisseur',
                'factures fournisseur',
                'facture d\'achat',
                'factures d\'achat',
                'note de frais',
                'facture du fournisseur',
                'facture des achats',
                'facture du vendeur',
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
            'identifiant utilisateur actuel',
            'utilisateur actuel',
            'utilisateur authentifié',
            'utilisateur connecté',
            'mon identifiant utilisateur',
            'moi',
            'moi-même',
            'soi',
        ],
    ],
];
