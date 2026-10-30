# image-hosting

基于 **Python 3.13 + Tornado + MySQL + 七牛云存储** 的文件上传网关，配套 **PHP Composer SDK**。

客户端只负责把文件传上来，**切片、重试、推送七牛、落库**全部由服务端完成；客户端无需持有七牛 AK/SK，也不需要关心七牛区域。

```
业务方（PHP / 小程序 / Web）──► 上传网关 ──► 七牛 bucket
                                   │
                                   └─► MySQL（应用配置 / 上传记录 / 每日用量）
```

## 目录

- [功能特性](#功能特性)
- [目录结构](#目录结构)
- [快速开始](#快速开始)
- [环境变量](#环境变量)
- [接入应用管理](#接入应用管理)
- [API 说明](#api-说明)
  - [通用约定](#通用约定)
  - [GET /health](#get-health)
  - [POST /api/v1/upload](#post-apiv1upload)
  - [POST /api/v1/private-url](#post-apiv1private-url)
  - [GET /api/v1/files](#get-apiv1files)
  - [运维工具接口](#运维工具接口)
- [对象 Key 与 path_template](#对象-key-与-path_template)
- [数据表](#数据表)
- [上传通道与限制](#上传通道与限制)
- [PHP SDK（zeyen/filse）](#php-sdkzeyenfilse)
- [运维与排查](#运维与排查)
- [常见问题](#常见问题)

## 功能特性

- **双通道上传**：`application/octet-stream` 原始流（大文件流式落盘）与 `multipart/form-data` 表单
- **服务端自动分片**：≤ 2MB 单次直传，> 2MB 走七牛 SDK v2 分片（分片 4MB），客户端无感
- **应用级认证**：`X-App-Id` + `X-App-Key`，密钥只存 SHA-256 摘要
- **应用级隔离**：每个应用独立七牛 AK/SK（Fernet 加密入库）、bucket、访问域名、Key 模板、单文件上限、每日配额
- **七牛区域零配置**：区域与上传入口由官方 SDK 自动识别并缓存，代码里没有任何 region 配置
- **强制 HTTPS + 屏蔽系统代理**：避免 Clash 等把国内上传绕到境外节点
- **文件名自动识别**：客户端不传文件名时，按文件头魔数推断真实格式并随机命名（30+ 种，zip 家族会细分 docx/xlsx/pptx/epub/apk）
- **域名白名单**：按 `Origin / Referer / Host` 校验，支持 `*.example.com`
- **公有 / 私有分离**：私有文件不落公网 URL，通过独立接口签发限时签名链接
- **每日配额**：次数与字节数双重限额，超限返回 `429`
- **完整审计**：key、大小、ETag、上传方式、分片数、端到端耗时、来源域名、IP、UA、失败原因
- **表结构自适应**：读取 `information_schema` 动态拼 SQL，字段不一致自动补默认值并 WARNING，不再整条请求 500

## 目录结构

```
image-hosting/
├── app.py                  # 入口：解析端口、连接池、启动监听
├── settings.py             # 配置解析（支持 50MB 等单位）+ 启动期校验
├── db.py                   # aiomysql 连接池
├── models.py               # 数据访问（information_schema 自适应动态 SQL）
├── core/
│   ├── base.py             # ApiHandler：统一响应体、CORS、异常转 JSON
│   └── errors.py           # ErrCode + ApiError
├── handlers/
│   ├── upload.py           # POST /api/v1/upload（流式 + 表单双通道）
│   ├── file.py             # 私有链接、文件列表
│   ├── tools.py            # 运维工具：app_key 摘要、七牛密钥加解密
│   └── system.py           # 健康检查
├── services/
│   ├── qiniu.py            # 七牛：区域识别、token、直传/分片、私有 URL
│   └── upload.py           # 上传业务：配额、文件名、自动命名、Key 生成、payload
├── utils/
│   ├── auth.py             # app 认证 + 域名白名单
│   ├── domain.py           # 域名提取与通配匹配
│   ├── keys.py             # 文件名清洗、path_template 渲染、自定义路径
│   ├── sniff.py            # 文件头魔数嗅探
│   ├── crypto.py           # Fernet 加解密（与 PHP 端互通）
│   └── serialize.py        # 响应体序列化
├── scripts/
│   ├── provision_app.py    # 创建/更新接入应用
│   └── bench_qiniu.py      # 七牛上传耗时基准
└── tmp/uploads/            # 上传临时目录
```

## 快速开始

```bash
git clone <repo> && cd image-hosting

python3.13 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt

cp .env.example .env
# 生成主密钥（用于加密七牛 AK/SK）
python -c "from cryptography.fernet import Fernet; print(Fernet.generate_key().decode())"
# 把输出填入 .env 的 SECRET_MASTER_KEY

# 创建接入应用（公有）
python scripts/provision_app.py \
  --app-id demo_public --app-key demo_key_123456 --name 演示-公有 \
  --access-type 1 --qiniu-ak <AK> --qiniu-sk <SK> \
  --bucket demo-public --domain https://cdn.example.com \
  --domains "localhost,example.com,*.example.com"

# 创建接入应用（私有）
python scripts/provision_app.py \
  --app-id demo_private --app-key demo_priv_123456 --name 演示-私有 \
  --access-type 2 --qiniu-ak <AK> --qiniu-sk <SK> \
  --bucket demo-private --domain https://private.example.com \
  --domains "localhost,example.com,*.example.com"

python app.py --port 8888
```

启动参数：

| 参数 | 说明 |
|---|---|
| `--port` | 监听端口，可逗号分隔多个（`8888,8889`）；不传则读 `PORT` |
| `--host` | 监听地址；反向代理后面建议 `127.0.0.1` |

依赖：`tornado>=6.4`、`aiomysql`、`PyMySQL`、`qiniu>=7.13`、`cryptography`、`aiohttp`、`python-dotenv`

## 环境变量

| 变量 | 默认 | 说明 |
|---|---|---|
| `DEBUG` | `0` | `1` 开启 Tornado debug |
| `PORT` | `8888` | 支持逗号分隔多端口 |
| `MYSQL_HOST` / `MYSQL_PORT` | `127.0.0.1` / `3306` | 数据库地址 |
| `MYSQL_USER` / `MYSQL_PASSWORD` | `root` / 空 | |
| `MYSQL_DATABASE` | `image_hosting` | |
| `MYSQL_POOL_MIN` / `MYSQL_POOL_MAX` | `1` / `10` | 高并发建议 `2` / `20` |
| `SECRET_MASTER_KEY` | 必填 | Fernet 密钥，加解密七牛 AK/SK；支持逗号分隔多密钥用于轮换 |
| `MAX_FILE_SIZE` | `50MB` | 全局单文件上限 |
| `CHUNK_THRESHOLD` | `2MB` | 直传/分片分界，同时是表单上传上限 |
| `CHUNK_SIZE` | `4MB` | 分片大小，不能小于 1MB |
| `MAX_BODY_SIZE` | `MAX_FILE_SIZE + 4MB` | Tornado HTTP 层最大请求体，**必须 ≥ MAX_FILE_SIZE** |
| `UPLOAD_TOKEN_EXPIRES` | `3600` | 七牛上传凭证有效期 |
| `PRIVATE_URL_EXPIRES` | `3600` | 私有链接默认有效期 |
| `PRIVATE_URL_MAX_EXPIRES` | `86400` | 私有链接有效期上限 |
| `QINIU_HTTP_TIMEOUT` | `120` | 七牛 HTTP 超时（秒） |
| `UPLOAD_TMP_DIR` | `./tmp/uploads` | 临时文件目录 |
| `UPLOAD_CONCURRENCY` | `4` | 预留（当前未参与限流） |
| `ADMIN_TOKEN` | 空 | 运维工具接口的管理口令（可选） |
| `NO_PROXY` / `no_proxy` | 空 | 建议包含 `qiniup.com,qiniuapi.com,localhost,127.0.0.1` |

大小类配置支持单位：`52428800`、`50MB`、`50M` 均可。

启动校验：`CHUNK_SIZE ≥ 1MB`、`CHUNK_THRESHOLD < MAX_FILE_SIZE`、`MAX_BODY_SIZE ≥ MAX_FILE_SIZE`。

> `.env` 必须写成 `KEY=值`；写成 `KEY==值` 会导致解析出 `=值` 并启动失败。

## 接入应用管理

`scripts/provision_app.py` 负责新建与更新（加 `--update`）：

| 参数 | 说明 |
|---|---|
| `--app-id` / `--app-key` | 必填；`app_key` 只保存 SHA-256 摘要与 6 位前缀 |
| `--access-type` | `1` 公有、`2` 私有（决定默认 path_template 前缀） |
| `--qiniu-ak` / `--qiniu-sk` | 七牛密钥，写入前用 `SECRET_MASTER_KEY` 加密 |
| `--bucket` / `--domain` | 存储空间与访问域名（私有 bucket 不能填 CDN 加速域名） |
| `--path-template` | 不传则用默认模板 |
| `--max-file-size` | 应用级单文件上限，默认 50MB |
| `--daily-file-limit` | 每日上传次数上限，`0` 不限 |
| `--daily-bytes-limit` | 每日上传字节上限，`0` 不限 |
| `--domains` | 逗号分隔白名单，默认 `*`（全部放行） |
| `--no-domain-check` | 关闭域名校验 |
| `--update` | 按 `app_id` 更新已有配置 |

修改配置后无需手动清缓存：`get_auth` 以 `updated_at` 作为缓存版本号自动失效。

## API 说明

### 通用约定

- 所有业务接口需带认证头：`X-App-Id`、`X-App-Key`
- 响应统一结构：

```json
{
  "code": 0,
  "msg": "ok",
  "data": {},
  "request_id": "b1f0c2..."
}
```

- 响应头带 `X-Request-Id`；请求带 `Origin` 时自动回写 CORS 头（允许 `Content-Type`、`X-App-Id`、`X-App-Key`、`X-File-Name`）
- 错误码：

| code | HTTP | 含义 |
|---|---|---|
| `40000` | 400 | 参数错误 |
| `40100` | 401 | 未认证 / app 不存在 / 密钥错误 |
| `40300` | 403 | 域名不在白名单 |
| `40400` | 404 | 文件不存在 |
| `41300` | 413 | 文件超过上限 |
| `42900` | 429 | 超出每日配额 |
| `50000` | 500 | 服务端错误 |
| `50200` | 502 | 七牛上游错误 |

### GET /health

数据库连通性检查，用于探活。

```json
{"code":0,"msg":"ok","data":{"status":"ok"},"request_id":"..."}
```

### POST /api/v1/upload

按 `Content-Type` 自动分流两条通道。

#### 通道 A：原始流（推荐，支持大文件）

```bash
curl -X POST "http://127.0.0.1:8888/api/v1/upload?file_name=big.zip&path=album/2026" \
  -H "X-App-Id: demo_public" \
  -H "X-App-Key: demo_key_123456" \
  -H "Content-Type: application/octet-stream" \
  --data-binary "@./big.zip"
```

| 参数（Query） | 说明 |
|---|---|
| `file_name` | 也可用 `filename` / `name`，或请求头 `X-File-Name`（头部需 URL 编码） |
| `mime_type` | 强制指定 Content-Type；不传则按文件名推断 |
| `path` / `custom_path` | 自定义子路径，也可用请求头 `X-Custom-Path` |

**不传文件名也可以**：服务端读取前 1KB 判断真实格式并随机命名。

```bash
curl -X POST "http://127.0.0.1:8888/api/v1/upload" \
  -H "X-App-Id: demo_public" -H "X-App-Key: demo_key_123456" \
  -H "Content-Type: application/octet-stream" --data-binary "@./big.zip"
# → file_key: uploads/demo_public/2026/10/29/8931aa00b5c04ee693ec58bdd17d9cc8.zip
```

#### 通道 B：multipart/form-data（≤ 2MB）

```bash
curl -X POST "http://127.0.0.1:8888/api/v1/upload" \
  -H "X-App-Id: demo_public" -H "X-App-Key: demo_key_123456" \
  -F "file=@./photo.jpg" \
  -F "file_name=photo.jpg" \
  -F "path=album/2026"
```

| 字段 | 必填 | 说明 |
|---|---|---|
| `file` | 是 | 文件本身 |
| `file_name` | 否 | 覆盖文件名（临时文件名不可信时用） |
| `path` / `custom_path` | 否 | 自定义子路径 |

表单通道上限为 `CHUNK_THRESHOLD + 64KB`，超出返回 `413`，大文件请用通道 A。

#### 响应

```json
{
  "code": 0,
  "msg": "ok",
  "data": {
    "file_id": "3f2a9c1b7d4e2f60a8c1b9d0e4f5a6b7",
    "file_key": "uploads/demo_public/2026/10/29/8931aa00b5c04ee693ec58bdd17d9cc8.jpg",
    "url": "https://cdn.example.com/uploads/demo_public/2026/10/29/8931aa00b5c04ee693ec58bdd17d9cc8.jpg",
    "duration_ms": 312,
    "access_type": 1,
    "file_size": 204800
  },
  "request_id": "..."
}
```

私有应用额外返回 `url_expires_in`（秒），`url` 为带签名的临时链接。

### POST /api/v1/private-url

私有文件换取限时访问链接，请求体用 `application/x-www-form-urlencoded`（也支持 JSON）：

```bash
curl -X POST "http://127.0.0.1:8888/api/v1/private-url" \
  -H "X-App-Id: demo_private" -H "X-App-Key: demo_priv_123456" \
  -d "file_key=private/demo_private/2026/10/29/xxx.jpg" \
  -d "expires=900"
```

| 参数 | 说明 |
|---|---|
| `file_key` | 必填，上传返回的对象 key |
| `expires` | 有效期秒数，缺省 `PRIVATE_URL_EXPIRES`，自动夹在 `[60, PRIVATE_URL_MAX_EXPIRES]` |

```json
{
  "code": 0, "msg": "ok",
  "data": {
    "file_key": "private/demo_private/2026/10/29/xxx.jpg",
    "url": "https://private.example.com/private/...?e=1761700000&token=...",
    "expires_in": 900
  }
}
```

约束：文件必须存在、状态为成功、且文件与应用都是私有类型（`access_type=2`）。

### GET /api/v1/files

```bash
curl "http://127.0.0.1:8888/api/v1/files?page=1&page_size=20&access_type=1" \
  -H "X-App-Id: demo_public" -H "X-App-Key: demo_key_123456"
```

| 参数 | 说明 |
|---|---|
| `page` | 从 1 开始 |
| `page_size` | 1~100，默认 20 |
| `access_type` | 可选，`1` / `2` 过滤 |

```json
{
  "code": 0, "msg": "ok",
  "data": {
    "total": 128, "page": 1, "page_size": 20,
    "items": [
      {
        "file_id": "...", "app_id": "demo_public", "access_type": 1,
        "file_key": "uploads/...", "file_name": "a.jpg", "file_ext": "jpg",
        "mime_type": "image/jpeg", "file_size": 204800, "etag": "Fm9...",
        "upload_mode": 1, "chunk_count": 1, "duration_ms": 312,
        "url": "https://cdn.example.com/uploads/...", "status": 1,
        "created_at": "2026-10-29T10:12:03"
      }
    ]
  }
}
```

### 运维工具接口

三个接口用于运维配置：`app-key-hash` 无需认证（新建应用前即可计算），七牛加解密接口需要 app 认证；服务端配置了 `ADMIN_TOKEN` 时也可用 `X-Admin-Token` 调用。

| 接口 | 认证 | 用途 |
|---|---|---|
| `POST /api/v1/tools/app-key-hash` | 无 | 明文 `app_key` → `app_key_hash` |
| `POST /api/v1/tools/qiniu/encrypt` | app / admin | 明文 AK/SK → Fernet 密文 |
| `POST /api/v1/tools/qiniu/decrypt` | app / admin | Fernet 密文 → 明文 AK/SK |

```bash
# 1) 计算 app_key_hash
curl -X POST "http://127.0.0.1:8888/api/v1/tools/app-key-hash" \
  -H "Content-Type: application/json" \
  -d '{"app_key":"demo_key_123456"}'
# → {"app_key_hash":"6f1c...","app_key_prefix":"demo_k","app_key_length":15,"algorithm":"sha256"}

# 2) 加密七牛 AK/SK
curl -X POST "http://127.0.0.1:8888/api/v1/tools/qiniu/encrypt" \
  -H "X-App-Id: demo_public" -H "X-App-Key: demo_key_123456" \
  -H "Content-Type: application/json" \
  -d '{"qiniu_access_key":"AKxxxx","qiniu_secret_key":"SKyyyy"}'
# → {"qiniu_access_key_cipher":"gAAAAA...","qiniu_access_key_hint":"xxxx", ...}

# 3) 解密（高敏接口，仅服务端调用）
curl -X POST "http://127.0.0.1:8888/api/v1/tools/qiniu/decrypt" \
  -H "X-App-Id: demo_public" -H "X-App-Key: demo_key_123456" \
  -H "Content-Type: application/json" \
  -d '{"qiniu_access_key_cipher":"gAAAAA...","qiniu_secret_key_cipher":"gAAAAA..."}'
# → {"qiniu_access_key":"AKxxxx","qiniu_secret_key":"SKyyyy", ...}
```

注意：

- 加密接口传入已是密文的值会原样返回并置 `already_encrypted=true`，不会二次加密
- 解密失败会明确提示「SECRET_MASTER_KEY 与加密时不一致，或密文被截断/篡改」
- 每次解密都会打 WARNING 日志（app_id、IP、字段），便于审计

## 对象 Key 与 path_template

默认模板：

- 公有：`uploads/{app_id}/{yyyy}/{mm}/{dd}/{uuid}.{ext}`
- 私有：`private/{app_id}/{yyyy}/{mm}/{dd}/{uuid}.{ext}`

| 占位符 | 值 |
|---|---|
| `{app_id}` | 应用标识 |
| `{yyyy}` / `{yy}` / `{year}` | 年 |
| `{mm}` / `{month}` | 月 |
| `{dd}` / `{day}` | 日 |
| `{hh}` / `{hour}` | 时 |
| `{date}` | `YYYYMMDD` |
| `{uuid}` | 32 位 hex |
| `{rand}` | 12 位 hex |
| `{name}` | 文件名主干 |
| `{ext}` | 小写扩展名 |
| `{custom}` | 客户端自定义路径 |

出现未知占位符会直接返回 `400`，不会静默生成奇怪 key。

自定义路径（`path`）插入规则：

- 模板含 `{custom}` → 直接替换
- 否则插到 `app_id` 段之后：`uploads/demo/2026/10/26/a.png` + `avatar/1` → `uploads/demo/avatar/1/2026/10/26/a.png`
- 模板里没有 `app_id` 段 → 插到最前面
- 只允许字母、数字、`_ - . /` 与中文，禁止 `..`，最多 8 层

## 数据表

表前缀 `my_`，字符集 `utf8mb4`。

### `my_app_client` — 接入应用

`id`（自增主键）、`app_id`（唯一）、`app_key_hash`、`app_key_prefix`、`name`、`access_type`、`bucket`、`domain`、`path_template`、`qiniu_access_key_cipher`、`qiniu_secret_key_cipher`、`qiniu_access_key_hint`、`max_file_size`、`allow_domains`（每行一个）、`domain_check_enabled`、`daily_file_limit`、`daily_bytes_limit`、`status`、`remark`、`created_at`、`updated_at`、`deleted_at`

### `my_upload_file` — 上传记录

`id`（自增）、`file_id`、`app_client_id`、`app_id`、`bucket`、`access_type`、`file_key`、`file_name`、`file_ext`、`mime_type`、`file_size`、`etag`、`file_md5`、`upload_mode`（1 直传 / 2 分片）、`chunk_count`、`duration_ms`、`url`、`storage_domain`、`origin_domain`、`client_ip`、`user_agent`、`status`（1 成功 / 2 失败 / 3 已删除）、`error_msg`、`created_at`、`updated_at`

### `my_app_quota_usage` — 每日用量

`app_id`、`stat_date`、`upload_count`、`upload_bytes`、`private_url_count`，需要 `(app_id, stat_date)` 唯一键以支持 `ON DUPLICATE KEY UPDATE` 累加。

> `models.py` 会在首次使用某张表时读取 `information_schema.COLUMNS` 并缓存，再按真实列动态拼装 SQL：代码有、表没有的列自动跳过；表里 NOT NULL 而代码没给的列按类型补默认值并打 WARNING。
> 日志形如 `schema loaded: my_app_client(23) my_upload_file(25) my_app_quota_usage(6)`，列数与预期不符时按日志提示补表结构即可。

## 上传通道与限制

| 维度 | 通道 A（原始流） | 通道 B（表单） |
|---|---|---|
| Content-Type | `application/octet-stream` 或任意非 multipart | `multipart/form-data` |
| 大小上限 | `min(app.max_file_size, MAX_FILE_SIZE)` | `CHUNK_THRESHOLD`（默认 2MB） |
| 落盘方式 | 边收边写临时文件 | 内存缓冲 |
| 文件名 | Query/Header，可省略（自动嗅探） | `filename` 或表单 `file_name` |
| 适用 | 图片、视频、压缩包等大文件 | 小程序/网页小文件、需同时传业务字段 |

自动命名支持的格式（文件头嗅探）：png / jpg / gif / webp / bmp / ico / heic / avif / svg / mp4 / mov / mkv / webm / avi / mp3 / wav / ogg / flac / pdf / zip / rar / 7z / gz / bz2 / docx / xlsx / pptx / doc / epub / apk / sqlite / exe / so / json / xml / html / txt 等；zip 家族会进一步区分 docx/xlsx/pptx/epub/apk。识别不出时兜底 `.bin` 并打 WARNING。

七牛侧配置（`services/qiniu.py`）：

- `set_default(default_zone=Region(scheme="https"), connection_timeout=…, connection_retries=3, connection_pool=16, default_upload_threshold=CHUNK_THRESHOLD)`
- 自定义 `default_zone` 后 SDK 不再自动查区域，因此 `_regions_for()` 显式查询 `up_host`（进程内缓存，SDK 自身再缓存 24h）
- `qn_http_client.session.trust_env = False`：不走系统代理；启动日志会打印当前代理环境
- 上传失败仍会写一条 `status=2` 记录，`error_msg` 保存七牛返回的错误

## PHP SDK（zeyen/filse）

Composer 包 `zeyen/filse`，PHP `^8.3`，零第三方依赖（仅 `ext-curl`），支持框架无关调用，也可注入 PSR-18 客户端。

### 安装

```bash
composer require zeyen/filse
```

本地开发用 path 仓库：

```json
{
  "repositories": [
    { "type": "path", "url": "../zeyen-filse" }
  ]
}
```

### 配置

```php
use Zeyen\Filse\Client;

// 1) 环境变量 / .env（环境变量优先）
$client = Client::fromEnv('FILSE_');
$client = Client::fromEnvFile(__DIR__ . '/.env');

// 2) 显式数组（snake_case 或 camelCase）
$client = Client::create([
    'base_url' => 'https://api.example.com',
    'app_id'   => 'demo_public',
    'app_key'  => 'demo_key_123456',
    'origin'   => 'https://example.com',
    'timeout'  => 300,
]);

// 3) 注入框架自带的 PSR-18 客户端
$client = new Client($config, new \Zeyen\Filse\Http\Psr18Transport($psr18, $requestFactory, $streamFactory));
```

| 环境变量 | 说明 |
|---|---|
| `FILSE_BASE_URL` | 网关地址，必填 |
| `FILSE_APP_ID` / `FILSE_APP_KEY` | 应用凭据 |
| `FILSE_ORIGIN` | 应用开启域名白名单时必填 |
| `FILSE_ADMIN_TOKEN` | 运维工具接口用（可选） |
| `FILSE_TIMEOUT` / `FILSE_CONNECT_TIMEOUT` | 超时秒数 |
| `FILSE_VERIFY_SSL` | 是否校验证书，默认 `true` |
| `FILSE_USER_AGENT` | 自定义 UA |

### 上传

```php
// 原始流（默认，任意大小，大文件不占内存）
$res = $client->upload('/path/big.zip')
    ->fileName('big.zip')
    ->path('album/2026')
    ->mimeType('application/zip')
    ->send();

echo $res->fileKey, $res->url, $res->fileSize, $res->durationMs;

// multipart 表单（≤ 2MB）
$res = $client->upload('/path/photo.jpg')->asForm()->send();

// 直接上传内存内容
$res = $client->uploadContents($bytes, 'avatar.png')->send();
```

`fileName` 省略时由服务端嗅探真实格式并随机命名。

### 私有链接与文件列表

```php
$link = $client->privateUrl($res->fileKey, 600);
echo $link->url, $link->expiresIn;

$page = $client->files(page: 1, pageSize: 20, accessType: 1);
foreach ($page->items as $item) {
    echo $item->fileKey, $item->mimeType, PHP_EOL;
}
```

### 运维工具

```php
$hash  = $client->tools()->appKeyHash('demo_key_123456');
$keys  = $client->tools()->encryptQiniuKeys($ak, $sk);
$plain = $client->tools()->decryptQiniuKeys($keys->accessKeyCipher, $keys->secretKeyCipher);
```

### 异常处理

```php
use Zeyen\Filse\Exception\ApiException;
use Zeyen\Filse\Exception\ConfigException;
use Zeyen\Filse\Exception\TransportException;

try {
    $client->upload($path)->send();
} catch (ApiException $e) {        // 接口返回 code != 0
    $e->apiCode; $e->httpStatus; $e->requestId;
    $e->isAuthError(); $e->isQuotaExceeded(); $e->isPayloadTooLarge();
} catch (TransportException $e) {  // 网络 / 超时 / 响应非 JSON
} catch (ConfigException $e) {     // 配置缺失
}
```

### 框架集成

- **Laravel / Lumen**：`AppServiceProvider` 里 `singleton(Client::class, fn () => Client::fromEnv('FILSE_'))`
- **ThinkPHP**：`app/provider.php` 绑定 `Client::class`
- **Symfony**：`services.yaml` 注册 `Client::class`，参数绑定 `%env(FILSE_BASE_URL)%`
- **Hyperf**：把 Hyperf 的 PSR-18 客户端注入 `Psr18Transport`

### SDK 注意事项

- 大文件请用默认 `CurlTransport`；`Psr18Transport` 会把文件读进内存
- SDK 会显式清空 `Expect: 100-continue`，避免部分网络环境多等 1 秒
- `decryptQiniuKeys` 是高危接口，只在服务端调用

## 运维与排查

### 耗时定位

上传链路会打印分段耗时：

```
INFO handlers.upload upload phase processing=2971ms
INFO services.qiniu qiniu put_data key=... size=544294 cost=2963ms ok=True
```

- `cost` 大 → 七牛这一段慢（网络 / 代理 / 带宽）
- `processing` 大但 `cost` 小 → 查 `slow query` 警告，通常是连接池不足或数据库网络抖动
- 客户端侧用 `curl -w "%{time_total} %{time_starttransfer}"` 区分是发包慢还是服务端慢

### 代理

代码已设置 `trust_env=False`，但 **Clash 的 TUN/增强模式在网络层劫持，环境变量屏蔽无效**。排查上传慢时先彻底退出代理再对比：

```bash
env | grep -i proxy            # 确认无残留
python scripts/bench_qiniu.py <app_id> up.qiniup.com
```

### 孤儿对象

若七牛上传成功但落库失败，会在桶里留下没有记录的对象。日志会有 `写入 my_upload_file 失败 sql=... args=...`，据此可定位并清理。

## 常见问题

**上传大文件返回裸 400 / 连接被重置**
`MAX_BODY_SIZE < MAX_FILE_SIZE`，请求在 HTTP 解析阶段被拒。两者调大即可，启动时也有校验。

**启动报 `xxx 配置值非法`**
`.env` 写成了 `KEY==值`，或大小配置无法解析。支持 `52428800` / `50MB` / `50M`。

**上传成功但库里没记录**
落库阶段报错（表结构不一致、NOT NULL 列缺值等）。`models.py` 的动态字段机制已能自动补偿大部分不一致，日志里有完整 SQL 与参数。

**接口耗时 3~10 秒，文件只有几百 KB**
见「运维与排查 → 耗时定位」。若 `put_data` 就占满，多为代理或带宽问题。

**表单上传后文件内容带 `------WebKitFormBoundary`**
Tornado 解析结果退化成整段 multipart 报文。代码已兜底（检测到 `--` + `Content-Disposition` 时手动切分并打 WARNING）；若出现该 WARNING，检查中间 Nginx/网关是否改写了 body。

**私有 bucket 生成的链接 401**
私有 bucket 的 `domain` 必须绑定私有源站，不能填 CDN 加速域名；同时确认 `SECRET_MASTER_KEY` 与写入密钥时一致。

**调用接口返回 403「域名 xxx 不在白名单内」**
用脚本/SDK 调用时没有 `Origin`，服务端会退化用 `Host` 判断。补一个白名单内的 `Origin` 头（SDK 里配 `origin` / `FILSE_ORIGIN`），或使用 `X-Admin-Token`。

**换了 bucket 区域后上传失败**
`_REGION_CACHE` 是进程内缓存且不设 TTL，重启服务或调用 `services.qiniu.clear_cache()` 即可。
