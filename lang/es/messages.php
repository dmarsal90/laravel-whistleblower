<?php

return [
    'deadline' => ['acknowledgement' => 'acuse de recibo', 'resolution' => 'resolución'],
    'alert' => [
        'subject_approaching' => 'Canal de denuncias: se acerca el plazo de :deadline',
        'subject_overdue' => 'Canal de denuncias: plazo de :deadline vencido',
        'body_approaching' => 'El plazo de :deadline de la comunicación :id vence el :date.',
        'body_overdue' => 'El plazo de :deadline de la comunicación :id venció el :date.',
    ],
    'not_found' => 'No se ha encontrado ninguna comunicación con ese código.',
];
