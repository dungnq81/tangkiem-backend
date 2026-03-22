<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Entity Types
    |--------------------------------------------------------------------------
    |
    | Danh sách các loại dữ liệu hỗ trợ bởi hệ thống scrape.
    | Dùng cho: menu navigation, form select, table column, filter...
    |
    | Mỗi entity type gồm:
    |   - label: nhãn hiển thị trên UI
    |   - icon:  emoji hoặc Heroicon cho badge/column
    |   - color: màu Filament (info, success, primary, warning, danger)
    |   - nav_icon: Heroicon cho menu sidebar (dạng class constant)
    |
    */
    'entity_types' => [
        'category' => [
            'label'    => 'Danh mục',
            'icon'     => '📁',
            'color'    => 'info',
            'nav_icon' => 'heroicon-o-folder',
        ],
        'author' => [
            'label'    => 'Tác giả',
            'icon'     => '✍️',
            'color'    => 'success',
            'nav_icon' => 'heroicon-o-user',
        ],
        'story' => [
            'label'    => 'Truyện',
            'icon'     => '📖',
            'color'    => 'primary',
            'nav_icon' => 'heroicon-o-book-open',
        ],
        'chapter' => [
            'label'    => 'Chương',
            'icon'     => '📄',
            'color'    => 'warning',
            'nav_icon' => 'heroicon-o-document-text',
        ],
        'chapter_detail' => [
            'label'    => 'Chi tiết chương',
            'icon'     => '📝',
            'color'    => 'danger',
            'nav_icon' => 'heroicon-o-document-magnifying-glass',
        ],
    ],
];
