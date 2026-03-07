# Tàng Kiếm API Documentation

> **Version:** v1  
> **Base URL:** `https://api.tangkiem.xyz/api/v1`  
> **Last Updated:** 2026-03-07

---

## Mục lục

1. [Tổng quan](#1-tổng-quan)
2. [Xác thực (Authentication)](#2-xác-thực-authentication)
3. [Đăng ký Domain](#3-đăng-ký-domain)
4. [Rate Limiting](#4-rate-limiting)
5. [Cấu trúc Response](#5-cấu-trúc-response)
6. [API Groups & Quyền truy cập](#6-api-groups--quyền-truy-cập)
7. [Endpoints — Public](#7-endpoints--public)
    - [Stories](#71-stories)
    - [Chapters](#72-chapters)
    - [Categories](#73-categories)
    - [Rankings](#74-rankings)
    - [Search](#75-search)
    - [Reviews](#76-reviews)
8. [Endpoints — Authenticated (User)](#8-endpoints--authenticated-user)
    - [Profile](#81-profile)
    - [Bookmarks](#82-bookmarks)
    - [Reading History](#83-reading-history)
    - [Ratings](#84-ratings)
9. [Error Handling](#9-error-handling)
10. [Response Schemas](#10-response-schemas)

---

## 1. Tổng quan

Tàng Kiếm API là RESTful API phục vụ frontend (website đọc truyện) với các tính năng:

- Danh sách truyện, chương, thể loại
- Bảng xếp hạng (daily/weekly/monthly/all-time)
- Tìm kiếm & gợi ý
- Hệ thống bookmark, lịch sử đọc, đánh giá (yêu cầu đăng nhập)
- Bảo mật bằng API key + domain validation

### Công nghệ

- **Backend:** Laravel 12 (PHP 8.4+)
- **Auth:** Laravel Sanctum (Bearer Token)
- **Format:** JSON
- **Encoding:** UTF-8

---

## 2. Xác thực (Authentication)

API sử dụng **hệ thống 2 key** để xác thực client:

### 2.1. Public Key (Browser-side)

Dành cho frontend JavaScript chạy trực tiếp trong browser.

```
X-Public-Key: <your-public-key>
```

**Yêu cầu bổ sung:**

- Request **phải có** header `Origin` hoặc `Referer` (browser tự gửi)
- Origin phải **khớp** với domain đã đăng ký
- Nếu domain không khớp → `403 Forbidden`

**Khi nào dùng:** Website frontend gọi API qua `fetch()` hoặc Axios trong browser.

### 2.2. Secret Key (Server-side)

Dành cho backend-to-backend communication (SSR, mobile app backend, v.v.).

```
X-Secret-Key: <your-secret-key>
```

**Đặc điểm:**

- **Không** cần kiểm tra Origin/Referer
- Bảo mật cao hơn — **không bao giờ** expose ra browser
- Phù hợp cho SSR (Next.js, Nuxt), mobile backend, cron jobs

**Khi nào dùng:** Server-side rendering, API proxy, hoặc bất kỳ server nào gọi API.

### 2.3. Bearer Token (Authenticated User)

Các endpoint thuộc nhóm **User** (`/v1/user/*`) yêu cầu thêm Sanctum Bearer Token:

```
Authorization: Bearer <user-token>
```

Token được cấp khi user đăng nhập qua hệ thống auth của frontend.

### 2.4. Môi trường Local Dev

Trong môi trường `local`, nếu **không gửi** key nào → middleware sẽ bypass xác thực.  
Nếu gửi key → vẫn validate bình thường (để test full flow).

---

## 3. Đăng ký Domain

### 3.1. Tạo Domain mới

Domain được quản lý qua **Admin Panel** (Filament):  
`Admin → Quản lý API domain → Tạo mới`

Khi tạo domain, hệ thống tự động sinh:

- **Public Key** (64 ký tự random)
- **Secret Key** (64 ký tự random)

### 3.2. Thông tin cấu hình

| Trường           | Mô tả                                                       |
| :--------------- | :---------------------------------------------------------- |
| `name`           | Tên mô tả (ví dụ: "Frontend Production")                    |
| `domain`         | Domain được phép (ví dụ: `tangkiem.xyz`). Tự động bỏ `www.` |
| `allowed_groups` | Nhóm API được phép truy cập. `["*"]` = tất cả               |
| `valid_from`     | Ngày bắt đầu hiệu lực (nullable = ngay lập tức)             |
| `valid_until`    | Ngày hết hạn (nullable = vĩnh viễn)                         |
| `is_active`      | Bật/tắt access                                              |

### 3.3. Khởi tạo kết nối

**Bước 1:** Admin tạo domain trong panel → nhận `public_key` và `secret_key`.

**Bước 2:** Cấu hình ở frontend:

```javascript
// Frontend (browser) — dùng Public Key
const API_BASE = 'https://api.tangkiem.xyz/api/v1';
const PUBLIC_KEY = 'abcdef1234567890...'; // 64 chars

const response = await fetch(`${API_BASE}/stories`, {
	headers: {
		'X-Public-Key': PUBLIC_KEY,
		Accept: 'application/json',
	},
});
```

```javascript
// Backend (SSR / Next.js) — dùng Secret Key
const response = await fetch(`${API_BASE}/stories`, {
	headers: {
		'X-Secret-Key': process.env.API_SECRET_KEY,
		Accept: 'application/json',
	},
});
```

**Bước 3:** Kiểm tra kết nối:

```bash
# Sử dụng cURL (server-side)
curl -H "X-Secret-Key: YOUR_SECRET_KEY" \
     -H "Accept: application/json" \
     https://api.tangkiem.xyz/api/v1/stories
```

---

## 4. Rate Limiting

| Nhóm              | Giới hạn          | Áp dụng cho            |
| :---------------- | :---------------- | :--------------------- |
| **Public**        | 60 requests/phút  | Tất cả public routes   |
| **Authenticated** | 120 requests/phút | Routes có Bearer Token |
| **Search**        | 30 requests/phút  | `/v1/search/*`         |

Khi vượt giới hạn, API trả về `429 Too Many Requests`.

**Headers rate limit** (trong response):

```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 58
Retry-After: 42
```

---

## 5. Cấu trúc Response

### 5.1. Response thành công

```json
{
  "success": true,
  "data": { ... },       // Object hoặc Array
  "meta": {              // Chỉ có khi paginate
    "total": 150,
    "per_page": 25,
    "current_page": 1,
    "last_page": 6
  }
}
```

### 5.2. Response lỗi

```json
{
	"success": false,
	"error": "Unauthorized",
	"message": "Invalid public key"
}
```

### 5.3. Pagination

Tất cả danh sách đều hỗ trợ phân trang:

| Param      | Default | Max | Mô tả             |
| :--------- | :------ | :-- | :---------------- |
| `page`     | 1       | —   | Trang hiện tại    |
| `per_page` | 25      | 100 | Số item mỗi trang |

Response pagination (Laravel style):

```json
{
  "data": [...],
  "links": {
    "first": "...?page=1",
    "last": "...?page=6",
    "prev": null,
    "next": "...?page=2"
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 6,
    "per_page": 25,
    "to": 25,
    "total": 150
  }
}
```

---

## 6. API Groups & Quyền truy cập

Mỗi endpoint thuộc một **group**. Domain phải được cấp quyền truy cập group tương ứng.

| Group        | Endpoints                                                                  |
| :----------- | :------------------------------------------------------------------------- |
| `stories`    | `/v1/stories`, `/v1/stories/{slug}`, `/v1/stories/{id}/reviews`            |
| `chapters`   | `/v1/stories/{slug}/chapters`, `/v1/stories/{slug}/chapters/{chapterSlug}` |
| `categories` | `/v1/categories`, `/v1/categories/{slug}`                                  |
| `rankings`   | `/v1/rankings/daily`, `weekly`, `monthly`, `all-time`                      |
| `search`     | `/v1/search`, `/v1/search/suggest`                                         |
| `user`       | `/v1/user`, `/v1/user/bookmarks`, `/v1/user/history`, rating endpoints     |

**Cấp quyền:**

- `["*"]` — truy cập tất cả groups
- `["stories", "chapters"]` — chỉ truy cập stories và chapters
- `[]` — không truy cập group nào (chỉ bypass local dev)

---

## 7. Endpoints — Public

### 7.1. Stories

#### Danh sách truyện

```
GET /v1/stories
```

**Query Parameters:**

| Param      | Type   | Mô tả                                                                    |
| :--------- | :----- | :----------------------------------------------------------------------- |
| `category` | string | Lọc theo slug thể loại (`tien-hiep`, `kiem-hiep`)                        |
| `author`   | string | Lọc theo slug tác giả                                                    |
| `status`   | string | Lọc trạng thái: `ongoing`, `completed`, `hiatus`, `dropped`              |
| `origin`   | string | Quốc gia: `china`, `korea`, `japan`, `vietnam`, `western`, `other`       |
| `sort`     | string | Sắp xếp: `updated_at`, `created_at`, `title`, `view_count`, `rating_avg` |
| `order`    | string | Thứ tự: `asc`, `desc` (default: `desc`)                                  |
| `per_page` | int    | Số truyện/trang (default: 24, max: 100)                                  |

**Response:**

```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "title": "Đấu Phá Thương Khung",
      "slug": "dau-pha-thuong-khung",
      "alternative_titles": "Battle Through the Heavens",
      "description": "Tóm tắt truyện...",
      "status": "completed",
      "status_label": "Hoàn thành",
      "origin": "china",
      "origin_label": "Trung Quốc",
      "origin_flag": "🇨🇳",
      "is_featured": true,
      "is_hot": false,
      "is_vip": false,
      "view_count": 125000,
      "chapter_count": 1648,
      "rating_avg": 4.5,
      "rating_count": 320,
      "author": {
        "id": 1,
        "name": "Thiên Tàm Thổ Đậu",
        "slug": "thien-tam-tho-dau",
        "story_count": 5
      },
      "primary_category": {
        "id": 2,
        "name": "Tiên Hiệp",
        "slug": "tien-hiep",
        "story_count": 450
      },
      "cover_image": {
        "url": "https://api.tangkiem.xyz/storage/covers/dptk.webp",
        "alt": "Đấu Phá Thương Khung"
      },
      "published_at": "2025-01-15T08:00:00+07:00",
      "last_chapter_at": "2025-12-01T10:30:00+07:00",
      "updated_at": "2025-12-01T10:30:00+07:00"
    }
  ],
  "links": { ... },
  "meta": { ... }
}
```

---

#### Chi tiết truyện

```
GET /v1/stories/{slug}
```

**Response bổ sung so với danh sách:**

- `content` — nội dung giới thiệu đầy đủ (HTML)
- `categories` — danh sách tất cả thể loại
- `tags` — danh sách tags
- `chapters` — 20 chương mới nhất
- `meta` — thông tin SEO (`title`, `description`, `keywords`, `canonical_url`)

```json
{
	"data": {
		"id": 1,
		"title": "Đấu Phá Thương Khung",
		"content": "<p>Nội dung giới thiệu chi tiết...</p>",
		"categories": [
			{ "id": 2, "name": "Tiên Hiệp", "slug": "tien-hiep" },
			{ "id": 5, "name": "Huyền Huyễn", "slug": "huyen-huyen" }
		],
		"tags": [{ "id": 1, "name": "Nóng Bỏng", "slug": "nong-bong" }],
		"chapters": [
			{
				"id": 100,
				"chapter_number": 1,
				"title": "Khởi đầu",
				"slug": "chuong-1-khoi-dau",
				"formatted_number": "Chương 1",
				"full_title": "Chương 1: Khởi đầu",
				"word_count": 3200,
				"is_vip": false,
				"is_free": true,
				"published_at": "2025-01-15T08:00:00+07:00"
			}
		],
		"meta": {
			"title": "Đấu Phá Thương Khung - Đọc truyện Tiên Hiệp",
			"description": "Đấu Phá Thương Khung - Truyện tiên hiệp hấp dẫn...",
			"keywords": "đấu phá thương khung, tiên hiệp, thiên tàm thổ đậu",
			"canonical_url": "https://tangkiem.xyz/truyen/dau-pha-thuong-khung"
		}
	}
}
```

---

### 7.2. Chapters

#### Danh sách chương

```
GET /v1/stories/{storySlug}/chapters
```

**Query Parameters:**

| Param      | Type | Mô tả                                   |
| :--------- | :--- | :-------------------------------------- |
| `volume`   | int  | Lọc theo volume                         |
| `per_page` | int  | Số chương/trang (default: 25, max: 100) |

**Response:**

```json
{
  "data": [
    {
      "id": 100,
      "chapter_number": 1,
      "sub_chapter": null,
      "volume_number": 1,
      "title": "Khởi đầu",
      "slug": "chuong-1-khoi-dau",
      "formatted_number": "Chương 1",
      "full_title": "Chương 1: Khởi đầu",
      "word_count": 3200,
      "view_count": 5300,
      "is_vip": false,
      "is_free": true,
      "published_at": "2025-01-15T08:00:00+07:00"
    }
  ],
  "links": { ... },
  "meta": { ... }
}
```

---

#### Đọc nội dung chương

```
GET /v1/stories/{storySlug}/chapters/{chapterSlug}
```

> ⚡ **Lưu ý:** Gọi endpoint này sẽ **tự động tăng view count** cho chương và truyện (đếm bất đồng
> bộ qua Redis buffer).

**Response:**

```json
{
	"data": {
		"id": 100,
		"chapter_number": 1,
		"sub_chapter": null,
		"volume_number": 1,
		"title": "Khởi đầu",
		"slug": "chuong-1-khoi-dau",
		"formatted_number": "Chương 1",
		"full_title": "Chương 1: Khởi đầu",
		"content": "<p>Nội dung chương...</p>",
		"word_count": 3200,
		"view_count": 5301,
		"is_vip": false,
		"is_free": true,
		"prev_chapter": {
			"id": 99,
			"slug": "quyen-thu-loi-noi-dau",
			"formatted_number": "Quyển thư - Lời nói đầu"
		},
		"next_chapter": {
			"id": 101,
			"slug": "chuong-2-dau-khi",
			"formatted_number": "Chương 2"
		},
		"story": {
			"id": 1,
			"title": "Đấu Phá Thương Khung",
			"slug": "dau-pha-thuong-khung"
		},
		"meta": {
			"title": "Chương 1: Khởi đầu - Đấu Phá Thương Khung",
			"description": "Đọc Chương 1: Khởi đầu..."
		},
		"published_at": "2025-01-15T08:00:00+07:00"
	}
}
```

---

### 7.3. Categories

#### Danh sách thể loại

```
GET /v1/categories
```

**Query Parameters:**

| Param      | Type | Mô tả                               |
| :--------- | :--- | :---------------------------------- |
| `tree`     | bool | Trả về dạng cây (parent → children) |
| `featured` | bool | Chỉ thể loại nổi bật                |
| `menu`     | bool | Chỉ thể loại hiển thị trong menu    |

**Response:**

```json
{
	"data": [
		{
			"id": 1,
			"name": "Tiên Hiệp",
			"slug": "tien-hiep",
			"story_count": 450
		}
	]
}
```

---

#### Chi tiết thể loại (+ danh sách truyện)

```
GET /v1/categories/{slug}
```

**Response:**

```json
{
  "data": {
    "id": 1,
    "name": "Tiên Hiệp",
    "slug": "tien-hiep",
    "description": "Truyện tiên hiệp là...",
    "story_count": 450
  },
  "stories": {
    "data": [ ... ],
    "links": { ... },
    "meta": { ... }
  }
}
```

---

### 7.4. Rankings

#### Bảng xếp hạng

```
GET /v1/rankings/daily     # Top hôm nay
GET /v1/rankings/weekly    # Top tuần này
GET /v1/rankings/monthly   # Top tháng này
GET /v1/rankings/all-time  # Top mọi thời đại
```

**Query Parameters:**

| Param   | Type | Default | Max | Mô tả            |
| :------ | :--- | :------ | :-- | :--------------- |
| `limit` | int  | 20      | 50  | Số truyện trả về |

**Response:**

```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "title": "Đấu Phá Thương Khung",
      "slug": "dau-pha-thuong-khung",
      "view_count": 125000,
      "rating_avg": 4.5,
      ...
    }
  ],
  "meta": {
    "period": "daily",
    "limit": 20
  }
}
```

---

### 7.5. Search

#### Tìm kiếm truyện

```
GET /v1/search?q=keyword
```

> 🔒 **Rate Limit:** 30 requests/phút

**Query Parameters:**

| Param      | Type   | Mô tả                                    |
| :--------- | :----- | :--------------------------------------- |
| `q`        | string | **Bắt buộc.** Từ khóa (2-100 ký tự)      |
| `per_page` | int    | Số kết quả/trang (default: 25, max: 100) |

Tìm kiếm theo: **tiêu đề**, **tên khác**, **tác giả**.

**Response:** Giống response danh sách `StoryCollection`.

---

#### Gợi ý tìm kiếm (Autocomplete)

```
GET /v1/search/suggest?q=keyword
```

> Nhẹ, nhanh, trả về tối đa **10 kết quả** với ít trường.

**Response:**

```json
{
	"success": true,
	"data": [
		{
			"id": 1,
			"title": "Đấu Phá Thương Khung",
			"slug": "dau-pha-thuong-khung",
			"category": "Tiên Hiệp"
		}
	]
}
```

---

### 7.6. Reviews

#### Đánh giá của truyện (Public)

```
GET /v1/stories/{id}/reviews
```

> **Lưu ý:** Dùng `id` (số), không phải `slug`.

**Query Parameters:**

| Param      | Type | Default | Max |
| :--------- | :--- | :------ | :-- |
| `per_page` | int  | 25      | 100 |

**Response:**

```json
{
	"success": true,
	"data": [
		{
			"id": 15,
			"rating": 5,
			"review": "Truyện hay lắm!",
			"is_featured": false,
			"user": {
				"id": 42,
				"name": "Nguyễn Văn A"
			},
			"created_at": "2025-11-20T14:30:00+07:00",
			"updated_at": "2025-11-20T14:30:00+07:00"
		}
	],
	"meta": {
		"total": 320,
		"per_page": 25,
		"current_page": 1,
		"last_page": 13,
		"average_rating": 4.5,
		"rating_count": 320
	}
}
```

---

## 8. Endpoints — Authenticated (User)

> Tất cả endpoint trong nhóm này yêu cầu **cả 2**:
>
> - API Key (`X-Public-Key` hoặc `X-Secret-Key`)
> - Bearer Token (`Authorization: Bearer <token>`)

### 8.1. Profile

#### Lấy thông tin user

```
GET /v1/user
```

**Response:**

```json
{
	"data": {
		"id": 42,
		"name": "Nguyễn Văn A",
		"email": "user@example.com",
		"avatar_url": "https://api.tangkiem.xyz/storage/avatars/user42.webp",
		"is_vip": false,
		"is_author": false,
		"bookmark_count": 15,
		"history_count": 48,
		"email_verified_at": "2025-06-01T10:00:00+07:00",
		"last_active_at": "2026-02-25T14:00:00+07:00",
		"created_at": "2025-06-01T09:00:00+07:00"
	}
}
```

---

### 8.2. Bookmarks

#### Danh sách bookmark

```
GET /v1/user/bookmarks
```

**Response:**

```json
{
  "success": true,
  "data": [
    {
      "id": 10,
      "story": { ... },    // StoryResource
      "created_at": "2025-12-01T10:00:00+07:00"
    }
  ],
  "meta": { "total": 15, ... }
}
```

---

#### Thêm bookmark

```
POST /v1/stories/{id}/bookmark
```

> Nếu đã bookmark → trả `200` với message "đã có trong danh sách". Nếu mới → trả `201`.

**Response (201):**

```json
{
	"success": true,
	"message": "Đã thêm vào danh sách yêu thích",
	"data": {
		"bookmarked": true,
		"story_id": 1
	}
}
```

---

#### Xóa bookmark

```
DELETE /v1/stories/{id}/bookmark
```

**Response (200):**

```json
{
	"success": true,
	"message": "Đã xóa khỏi danh sách yêu thích",
	"data": {
		"bookmarked": false,
		"story_id": 1
	}
}
```

---

### 8.3. Reading History

#### Danh sách lịch sử đọc

```
GET /v1/user/history
```

**Response:**

```json
{
  "success": true,
  "data": [
    {
      "id": 200,
      "story": { ... },
      "chapter": {
        "id": 500,
        "chapter_number": 42,
        "title": "Chương 42: ...",
        "slug": "chuong-42-..."
      },
      "progress": 75,
      "read_at": "2026-02-25T10:30:00+07:00"
    }
  ],
  "meta": { ... }
}
```

---

#### Cập nhật tiến độ đọc

```
POST /v1/user/history
```

**Request Body:**

```json
{
	"story_id": 1,
	"chapter_id": 500,
	"progress": 75 // Optional, 0-100 (%)
}
```

> **Upsert:** Mỗi truyện chỉ có 1 entry lịch sử per user. Gọi lại sẽ update chương và tiến độ.

**Response:**

```json
{
	"success": true,
	"message": "Đã cập nhật tiến độ đọc",
	"data": {
		"story_id": 1,
		"chapter_id": 500,
		"chapter_number": 42,
		"progress": 75,
		"read_at": "2026-02-25T14:30:00+07:00"
	}
}
```

---

#### Xóa một mục lịch sử

```
DELETE /v1/user/history/{id}
```

---

#### Xóa toàn bộ lịch sử

```
DELETE /v1/user/history
```

**Response:**

```json
{
	"success": true,
	"message": "Đã xóa 48 mục lịch sử",
	"data": {
		"deleted_count": 48
	}
}
```

---

### 8.4. Ratings

#### Đánh giá truyện

```
POST /v1/stories/{id}/rate
```

**Request Body:**

```json
{
	"rating": 5, // Bắt buộc, 1-5
	"review": "Truyện hay!" // Optional, max 2000 ký tự
}
```

> **Upsert:** Gọi lại sẽ cập nhật đánh giá trước đó. Trả `201` khi tạo mới, `200` khi cập nhật.

**Response:**

```json
{
	"success": true,
	"message": "Đã đánh giá truyện",
	"data": {
		"id": 15,
		"rating": 5,
		"review": "Truyện hay!",
		"is_featured": false,
		"created_at": "2026-02-25T14:30:00+07:00",
		"updated_at": "2026-02-25T14:30:00+07:00"
	}
}
```

---

#### Xóa đánh giá

```
DELETE /v1/stories/{id}/rate
```

**Response:**

```json
{
	"success": true,
	"message": "Đã xóa đánh giá",
	"data": {
		"rated": false,
		"story_id": 1
	}
}
```

---

## 9. Error Handling

### 9.1. HTTP Status Codes

| Code  | Ý nghĩa                                            |
| :---- | :------------------------------------------------- |
| `200` | OK                                                 |
| `201` | Created (tạo mới bookmark, rating)                 |
| `401` | Unauthorized — thiếu hoặc sai API key              |
| `403` | Forbidden — domain mismatch, hết hạn, group denied |
| `404` | Not Found — truyện/chương không tồn tại            |
| `422` | Validation Error — dữ liệu gửi lên không hợp lệ    |
| `429` | Too Many Requests — vượt rate limit                |
| `500` | Server Error — lỗi hệ thống                        |

### 9.2. Error Response Format

```json
{
	"success": false,
	"error": "Unauthorized",
	"message": "Missing API key. Provide X-Public-Key or X-Secret-Key header.",
	"details": {
		"reason": "group_denied"
	}
}
```

### 9.3. Authentication Errors

| Lỗi                    | Status | Message                                                         |
| :--------------------- | :----- | :-------------------------------------------------------------- |
| Thiếu key              | 401    | `Missing API key. Provide X-Public-Key or X-Secret-Key header.` |
| Key sai                | 401    | `Invalid public key` hoặc `Invalid secret key`                  |
| Domain không khớp      | 403    | `Domain mismatch. Origin does not match registered domain.`     |
| Domain bị tắt          | 403    | `Domain access has been deactivated.`                           |
| Chưa tới ngày hiệu lực | 403    | `Domain access is not yet active.`                              |
| Hết hạn                | 403    | `Domain access has expired.`                                    |
| Không có quyền group   | 403    | `Access to this API group is not allowed.`                      |

### 9.4. Validation Errors (422)

```json
{
	"message": "The rating field is required.",
	"errors": {
		"rating": ["The rating field is required."],
		"review": ["The review field must not be greater than 2000 characters."]
	}
}
```

---

## 10. Response Schemas

### StoryResource

| Field                | Type               | Hiển thị khi                                                        |
| :------------------- | :----------------- | :------------------------------------------------------------------ |
| `id`                 | integer            | Luôn                                                                |
| `title`              | string             | Luôn                                                                |
| `slug`               | string             | Luôn                                                                |
| `alternative_titles` | string?            | Luôn                                                                |
| `description`        | string?            | Luôn                                                                |
| `content`            | string?            | Chỉ detail (show)                                                   |
| `status`             | string             | `ongoing`, `completed`, `hiatus`, `dropped`                         |
| `status_label`       | string             | Label tiếng Việt                                                    |
| `origin`             | string             | `china`, `korea`, `japan`, `vietnam`, `western`, `other`            |
| `origin_label`       | string             | Label tiếng Việt                                                    |
| `origin_flag`        | string             | Emoji cờ quốc gia                                                   |
| `is_featured`        | boolean            | Luôn                                                                |
| `is_hot`             | boolean            | Luôn                                                                |
| `is_vip`             | boolean            | Luôn                                                                |
| `view_count`         | integer            | Luôn                                                                |
| `chapter_count`      | integer            | Luôn                                                                |
| `rating_avg`         | float              | Luôn                                                                |
| `rating_count`       | integer            | Luôn                                                                |
| `author`             | AuthorResource     | Khi loaded                                                          |
| `primary_category`   | CategoryResource   | Khi loaded                                                          |
| `categories`         | CategoryResource[] | Chỉ detail                                                          |
| `tags`               | TagResource[]      | Chỉ detail                                                          |
| `chapters`           | ChapterResource[]  | Chỉ detail (20 chương)                                              |
| `cover_image`        | object?            | `{ url, alt }`                                                      |
| `meta`               | object?            | Chỉ detail — SEO: `{ title, description, keywords, canonical_url }` |
| `published_at`       | ISO8601            | Luôn                                                                |
| `last_chapter_at`    | ISO8601            | Luôn                                                                |
| `updated_at`         | ISO8601            | Luôn                                                                |

### ChapterResource (danh sách)

| Field              | Type     |
| :----------------- | :------- |
| `id`               | integer  |
| `chapter_number`   | integer  |
| `sub_chapter`      | string?  |
| `volume_number`    | integer? |
| `title`            | string   |
| `slug`             | string   |
| `formatted_number` | string   |
| `full_title`       | string   |
| `word_count`       | integer  |
| `view_count`       | integer  |
| `is_vip`           | boolean  |
| `is_free`          | boolean  |
| `published_at`     | ISO8601  |

### ChapterContentResource (đọc chương)

Bao gồm tất cả fields của `ChapterResource` + thêm:

| Field          | Type    | Mô tả                            |
| :------------- | :------ | :------------------------------- |
| `content`      | string  | HTML nội dung chương             |
| `prev_chapter` | object? | `{ id, slug, formatted_number }` |
| `next_chapter` | object? | `{ id, slug, formatted_number }` |
| `story`        | object  | `{ id, title, slug }`            |
| `meta`         | object  | `{ title, description }`         |

### CategoryResource

| Field         | Type    | Mô tả          |
| :------------ | :------ | :------------- |
| `id`          | integer |                |
| `name`        | string  |                |
| `slug`        | string  |                |
| `description` | string? | Chỉ khi detail |
| `story_count` | integer |                |

### AuthorResource

| Field         | Type    |
| :------------ | :------ |
| `id`          | integer |
| `name`        | string  |
| `slug`        | string  |
| `story_count` | integer |

### TagResource

| Field  | Type    |
| :----- | :------ |
| `id`   | integer |
| `name` | string  |
| `slug` | string  |

### RatingResource

| Field         | Type    | Mô tả                       |
| :------------ | :------ | :-------------------------- |
| `id`          | integer |                             |
| `rating`      | integer | 1-5                         |
| `review`      | string? |                             |
| `is_featured` | boolean |                             |
| `story`       | object? | StoryResource (khi loaded)  |
| `user`        | object? | `{ id, name }` (khi loaded) |
| `created_at`  | ISO8601 |                             |
| `updated_at`  | ISO8601 |                             |

### BookmarkResource

| Field        | Type    |
| :----------- | :------ | ------------- |
| `id`         | integer |
| `story`      | object  | StoryResource |
| `created_at` | ISO8601 |

### ReadingHistoryResource

| Field      | Type    |
| :--------- | :------ | --------------- |
| `id`       | integer |
| `story`    | object  | StoryResource   |
| `chapter`  | object  | ChapterResource |
| `progress` | integer | 0-100 (%)       |
| `read_at`  | ISO8601 |

### UserResource

| Field               | Type     |
| :------------------ | :------- |
| `id`                | integer  |
| `name`              | string   |
| `email`             | string   |
| `avatar_url`        | string?  |
| `is_vip`            | boolean  |
| `is_author`         | boolean  |
| `bookmark_count`    | integer  |
| `history_count`     | integer  |
| `email_verified_at` | ISO8601? |
| `last_active_at`    | ISO8601? |
| `created_at`        | ISO8601  |

---

## Phụ lục: Ví dụ tích hợp nhanh

### JavaScript (Browser)

```javascript
class TangKiemApi {
	constructor(publicKey) {
		this.baseUrl = 'https://api.tangkiem.xyz/api/v1';
		this.headers = {
			'X-Public-Key': publicKey,
			Accept: 'application/json',
			'Content-Type': 'application/json',
		};
	}

	async get(path, params = {}) {
		const url = new URL(`${this.baseUrl}${path}`);
		Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v));

		const res = await fetch(url, { headers: this.headers });
		if (!res.ok) throw new Error(`API Error: ${res.status}`);
		return res.json();
	}

	async post(path, body = {}) {
		const res = await fetch(`${this.baseUrl}${path}`, {
			method: 'POST',
			headers: this.headers,
			body: JSON.stringify(body),
		});
		if (!res.ok) throw new Error(`API Error: ${res.status}`);
		return res.json();
	}

	// Thêm Bearer Token cho authenticated requests
	withAuth(token) {
		this.headers['Authorization'] = `Bearer ${token}`;
		return this;
	}
}

// Sử dụng
const api = new TangKiemApi('YOUR_PUBLIC_KEY');

// Public
const stories = await api.get('/stories', { category: 'tien-hiep', per_page: 10 });
const story = await api.get('/stories/dau-pha-thuong-khung');
const chapters = await api.get('/stories/dau-pha-thuong-khung/chapters');
const rankings = await api.get('/rankings/daily', { limit: 10 });
const results = await api.get('/search', { q: 'đấu phá' });

// Authenticated
api.withAuth('user-bearer-token');
const profile = await api.get('/user');
const bookmarks = await api.get('/user/bookmarks');
await api.post('/stories/1/bookmark');
await api.post('/user/history', { story_id: 1, chapter_id: 100, progress: 50 });
await api.post('/stories/1/rate', { rating: 5, review: 'Hay lắm!' });
```

### PHP (Server-side)

```php
use Illuminate\Support\Facades\Http;

$api = Http::baseUrl('https://api.tangkiem.xyz/api/v1')
    ->withHeaders([
        'X-Secret-Key' => config('services.tangkiem.secret_key'),
        'Accept' => 'application/json',
    ]);

// Danh sách truyện
$stories = $api->get('/stories', ['per_page' => 10])->json();

// Chi tiết truyện
$story = $api->get('/stories/dau-pha-thuong-khung')->json();

// Tìm kiếm
$results = $api->get('/search', ['q' => 'đấu phá'])->json();
```

### cURL

```bash
# Danh sách truyện
curl -s \
  -H "X-Secret-Key: YOUR_SECRET_KEY" \
  -H "Accept: application/json" \
  "https://api.tangkiem.xyz/api/v1/stories?per_page=5" | jq

# Chi tiết truyện
curl -s \
  -H "X-Secret-Key: YOUR_SECRET_KEY" \
  "https://api.tangkiem.xyz/api/v1/stories/dau-pha-thuong-khung" | jq

# Tìm kiếm
curl -s \
  -H "X-Secret-Key: YOUR_SECRET_KEY" \
  "https://api.tangkiem.xyz/api/v1/search?q=dau+pha" | jq

# Đánh giá (authenticated)
curl -s -X POST \
  -H "X-Secret-Key: YOUR_SECRET_KEY" \
  -H "Authorization: Bearer USER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"rating": 5, "review": "Truyện hay!"}' \
  "https://api.tangkiem.xyz/api/v1/stories/1/rate" | jq
```
