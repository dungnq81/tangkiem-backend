<?php

return [
    /*
    |--------------------------------------------------------------------------
    | API Groups
    |--------------------------------------------------------------------------
    |
    | Define API groups that can be assigned to domains.
    | Format: 'group_key' => ['Label', 'route.name.pattern', ...]
    |
    */
    'groups' => [
        'stories' => [
            'label' => 'Truyện',
            'description' => 'Danh sách và chi tiết truyện',
        ],
        'chapters' => [
            'label' => 'Chương',
            'description' => 'Danh sách và nội dung chương',
        ],
        'rankings' => [
            'label' => 'Bảng xếp hạng',
            'description' => 'Top truyện theo các tiêu chí',
        ],
        'search' => [
            'label' => 'Tìm kiếm',
            'description' => 'Tìm kiếm truyện',
        ],
        'categories' => [
            'label' => 'Thể loại',
            'description' => 'Danh sách thể loại',
        ],
        'authors' => [
            'label' => 'Tác giả',
            'description' => 'Danh sách và chi tiết tác giả',
        ],
        'user' => [
            'label' => 'Người dùng',
            'description' => 'Hồ sơ, yêu thích, lịch sử đọc (yêu cầu đăng nhập)',
        ],
    ],
];
