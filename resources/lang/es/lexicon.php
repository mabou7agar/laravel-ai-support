<?php

return [
    'language' => [
        'name' => 'Español',
    ],

    'intent' => [
        'confirm' => ['sí', 'si', 's', 'ok', 'vale', 'confirmar', 'confirmo', 'claro', 'de acuerdo', 'adelante', 'hazlo', 'perfecto', 'correcto'],
        'reject' => ['no', 'n', 'cancelar', 'parar', 'detener', 'abortar', 'olvídalo', 'olvidalo', 'rechazar', 'nada'],
        'greeting' => ['hola', 'buenas', 'qué tal', 'que tal', 'saludos', 'buenos días', 'buenas tardes', 'buenas noches'],
        'cancel' => ['cancelar', 'parar', 'salir', 'detener', 'abortar', 'olvídalo', 'olvidalo'],
        'deny' => ['no', 'n', 'cancelar', 'cambiar', 'modificar', 'editar'],
        'done' => ['listo', 'terminado', 'eso es todo', 'no hay más cambios', 'ahora se ve bien'],
        'modify' => ['cambiar', 'modificar', 'editar', 'actualizar', 'reemplazar', 'en su lugar'],
        'duplicate_use' => ['usar', 'sí', 'si'],
        'duplicate_create' => ['nuevo', 'crear'],
        'possessive' => ['mi', 'mis', 'mío', 'mía', 'nuestro', 'nuestra', 'nuestros'],
        'query_prefixes' => ['listar ', 'mostrar ', 'obtener ', 'buscar ', 'encontrar ', 'ver ', 'cuáles son ', 'cuántos ', 'contar '],
    ],

    'response' => [
        'affirmative' => ['sí', 'si', 's', 'verdadero', 'confirmar', 'aprobado'],
        'negative' => ['no', 'n', 'falso', 'rechazar', 'rechazado'],
    ],

    'relation' => [
        'use_existing' => ['usar existente', 'usar este', 'usarlo', 'elegir existente', 'seleccionar existente', 'mantener existente'],
        'create_new' => ['crear nuevo', 'uno nuevo', 'crear faltante', 'añadir nuevo', 'hacer nuevo'],
    ],

    'entities' => [
        'aliases' => [
            'invoice' => [
                'factura',
                'facturas',
                'factura de venta',
                'facturas de venta',
                'factura de cliente',
                'facturas de cliente',
                'فاتورة',
                'فواتير',
                'فاتورة بيع',
                'فواتير بيع',
            ],
            'bill' => [
                'factura de compra',
                'facturas de compra',
                'factura de proveedor',
                'facturas de proveedor',
                'factura de gasto',
                'facturas de gasto',
                'recibo de proveedor',
                'factura del proveedor',
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
            'id de usuario actual',
            'usuario actual',
            'usuario autenticado',
            'usuario auth',
            'mi id de usuario',
            'yo',
            'a mí mismo',
            'mí mismo',
        ],
    ],
];
