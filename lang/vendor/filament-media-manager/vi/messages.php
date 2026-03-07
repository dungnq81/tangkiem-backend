<?php

return [
    'empty' => [
        'title' => 'Không tìm thấy Media hoặc Thư mục',
    ],
    'folders' => [
        'title' => 'Quản lý Media',
        'single' => 'Thư mục',
        'columns' => [
            'name' => 'Tên',
            'collection' => 'Bộ sưu tập',
            'description' => 'Mô tả',
            'is_public' => 'Công khai',
            'has_user_access' => 'Có quyền truy cập',
            'users' => 'Người dùng',
            'icon' => 'Icon',
            'color' => 'Màu sắc',
            'is_protected' => 'Được bảo vệ',
            'password' => 'Mật khẩu',
            'password_confirmation' => 'Xác nhận mật khẩu',
        ],
        'filters' => [
            'all_folders' => 'Tất cả thư mục',
            'protected_only' => 'Chỉ thư mục được bảo vệ',
            'public_only' => 'Chỉ thư mục công khai',
            'created_from' => 'Tạo từ ngày',
            'created_until' => 'Tạo đến ngày',
        ],
        'group' => 'Nội dung',
    ],
    'media' => [
        'title' => 'Media',
        'single' => 'Media',
        'columns' => [
            'image' => 'Hình ảnh',
            'model' => 'Model',
            'collection_name' => 'Tên bộ sưu tập',
            'size' => 'Kích thước',
            'order_column' => 'Thứ tự',
        ],
        'filters' => [
            'size_from' => 'Kích thước từ (KB)',
            'size_to' => 'Kích thước đến (KB)',
            'created_from' => 'Tạo từ ngày',
            'created_until' => 'Tạo đến ngày',
        ],
        'actions' => [
            'sub_folder' => [
                'label' => 'Tạo thư mục con',
            ],
            'create' => [
                'label' => 'Thêm Media',
                'form' => [
                    'file' => 'Tệp tin',
                    'title' => 'Tiêu đề',
                    'description' => 'Mô tả',
                ],
            ],
            'delete' => [
                'label' => 'Xóa thư mục',
            ],
            'edit' => [
                'label' => 'Sửa thư mục',
            ],
        ],
        'notifications' => [
            'create-media' => 'Đã tạo media thành công',
            'delete-folder' => 'Đã xóa thư mục thành công',
            'edit-folder' => 'Đã cập nhật thư mục thành công',
        ],
        'meta' => [
            'model' => 'Model',
            'file-name' => 'Tên tệp',
            'type' => 'Loại',
            'size' => 'Kích thước',
            'disk' => 'Disk',
            'url' => 'URL',
            'edit-media' => 'Sửa Media',
            'delete-media' => 'Xóa Media',
        ],
    ],
    'picker' => [
        'title' => 'Chọn Media',
        'browse' => 'Duyệt Media',
        'remove' => 'Xóa',
        'select' => 'Chọn',
        'cancel' => 'Hủy',
        'back' => 'Quay lại',
        'search' => 'Tìm kiếm thư mục và tệp...',
        'select_folder' => 'Chọn thư mục để duyệt các tệp media',
        'folders' => 'Thư mục',
        'media_files' => 'Tệp Media',
        'empty' => 'Không tìm thấy thư mục hoặc tệp media',
        'no_media_selected' => 'Chưa chọn media',
        'selected' => 'đã chọn',
        'clear_all' => 'Xóa tất cả',
        'confirm_remove' => 'Xóa Media',
        'confirm_remove_message' => 'Bạn có chắc chắn muốn xóa mục media này?',
    ],
];
