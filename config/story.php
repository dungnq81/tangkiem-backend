<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Story Statuses
    |--------------------------------------------------------------------------
    */
    'statuses' => [
        'ongoing' => [
            'label' => 'Đang tiến hành',
            'color' => 'success',
            'icon' => 'heroicon-o-arrow-path',
        ],
        'completed' => [
            'label' => 'Hoàn thành',
            'color' => 'primary',
            'icon' => 'heroicon-o-check-circle',
        ],
        'hiatus' => [
            'label' => 'Tạm ngưng',
            'color' => 'warning',
            'icon' => 'heroicon-o-pause-circle',
        ],
        'dropped' => [
            'label' => 'Ngưng viết',
            'color' => 'danger',
            'icon' => 'heroicon-o-x-circle',
        ],
    ],



    /*
    |--------------------------------------------------------------------------
    | Story Origins (Nguồn gốc/Quốc gia)
    |--------------------------------------------------------------------------
    */
    'origins' => [
        'vietnam' => [
            'label' => 'Việt Nam',
            'flag' => '🇻🇳',
            'language' => 'vi',
        ],
        'china' => [
            'label' => 'Trung Quốc',
            'flag' => '🇨🇳',
            'language' => 'zh',
        ],
        'korea' => [
            'label' => 'Hàn Quốc',
            'flag' => '🇰🇷',
            'language' => 'ko',
        ],
        'japan' => [
            'label' => 'Nhật Bản',
            'flag' => '🇯🇵',
            'language' => 'ja',
        ],
        'western' => [
            'label' => 'Phương Tây',
            'flag' => '🌍',
            'language' => 'en',
        ],
        'other' => [
            'label' => 'Khác',
            'flag' => '🌐',
            'language' => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Values
    |--------------------------------------------------------------------------
    */
    'defaults' => [
        'status' => 'ongoing',
        'origin' => 'vietnam',
    ],
];
