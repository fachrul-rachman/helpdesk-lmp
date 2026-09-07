<?php

$brand = strtoupper((string) env('HELPDESK_BRAND', 'LMP'));

return [
    'brand' => $brand,

    'brands' => [
        'LMP' => [
            'assistant_name' => 'Lestari',
            'company_name' => 'Lestari Memorial Park',
        ],
        'AMG' => [
            'assistant_name' => 'Zahra',
            'company_name' => 'Al Azhar Memorial Garden',
        ],
    ],
];
