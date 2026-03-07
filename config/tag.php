<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Tag Types
    |--------------------------------------------------------------------------
    */
    'types' => [
        'tag' => [
            'label' => 'Thẻ',
            'color' => 'primary',
            'icon' => 'heroicon-o-tag',
            'description' => 'Thẻ phân loại chung',
        ],
        'warning' => [
            'label' => 'Cảnh báo',
            'color' => 'danger',
            'icon' => 'heroicon-o-exclamation-triangle',
            'description' => 'Cảnh báo nội dung (18+, bạo lực, ...)',
        ],
        'attribute' => [
            'label' => 'Thuộc tính',
            'color' => 'info',
            'icon' => 'heroicon-o-sparkles',
            'description' => 'Thuộc tính đặc biệt (harem, xuyên không, ...)',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Values
    |--------------------------------------------------------------------------
    */
    'defaults' => [
        'type' => 'tag',
    ],
];
