<?php

return [
    'deadline' => ['acknowledgement' => 'acknowledgement of receipt', 'resolution' => 'resolution'],
    'alert' => [
        'subject_approaching' => 'Whistleblowing channel: :deadline deadline approaching',
        'subject_overdue' => 'Whistleblowing channel: :deadline deadline missed',
        'body_approaching' => 'The :deadline deadline of report :id is due on :date.',
        'body_overdue' => 'The :deadline deadline of report :id expired on :date.',
    ],
    'not_found' => 'No report found for that code.',
];
