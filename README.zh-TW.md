# FileMagic

[English](README.md) | [繁體中文](README.zh-TW.md)

FileMagic 是一個採用強型別設計的 Laravel 檔案管理套件。它可以接收上傳檔案、可讀取的本機路徑、二進位字串、一般 Base64 與 Base64 Data URI，也能產生 TXT、JSON 與 CSV 文件。套件會偵測或指定可信任的檔案資訊，透過 Laravel Filesystem 儲存檔案，再使用 Eloquent 保存檔案紀錄。

## 系統需求

- PHP 8.3 或以上
- Laravel 12 或 13
- PHP `fileinfo` extension
- 至少一個已設定完成的 Laravel Filesystem disk

圖片縮放功能另外需要：

- `intervention/image` 4.0 或以上
- PHP GD 或 Imagick extension

ZIP 批次下載另外需要 PHP `ext-zip`。

## 安裝

透過 Composer 安裝套件：

```bash
composer require mattmy/file-magic
```

發佈設定檔：

```bash
php artisan vendor:publish --tag=file-magic-config
```

發佈並執行 migration：

```bash
php artisan vendor:publish --tag=file-magic-migrations
php artisan migrate
```

Laravel 會自動發現 `Mattmy\FileMagic\FileMagicServiceProvider` 與 `FileMagic` Facade。

如果專案停用了 package discovery，可以在 `bootstrap/providers.php` 手動註冊 Service Provider：

```php
use Mattmy\FileMagic\FileMagicServiceProvider;

return [
    FileMagicServiceProvider::class,
];
```

## 設定

發佈後的 `config/file-magic.php` 內容如下：

```php
<?php

declare(strict_types=1);

return [
    'disk' => \env('FILE_MAGIC_DISK', \env('FILESYSTEM_DISK', 'local')),
    'directory' => \env('FILE_MAGIC_DIRECTORY', 'files'),
    'visibility' => \env('FILE_MAGIC_VISIBILITY', 'private'),
    'max_size' => 100 * 1024 * 1024,
    'allowed_mime_types' => [],
    'blocked_mime_types' => [
        'application/x-httpd-php',
        'application/x-php',
    ],
    'collision' => 'unique',
    'checksum_algorithm' => 'sha256',
    'temporary_url_ttl' => 5,
    'model' => Mattmy\FileMagic\Models\StoredFile::class,
    'table' => 'stored_files',
    'image' => [
        'quality' => 80,
        'max_width' => 1920,
    ],
    'zip' => [
        'max_files' => 100,
        'max_size' => 1024 * 1024 * 1024,
    ],
];
```

| 設定 | 用途 |
| --- | --- |
| `disk` | 預設的 Filesystem disk |
| `directory` | 預設的相對儲存目錄 |
| `visibility` | `private` 或 `public` |
| `max_size` | 偵測後允許的最大檔案大小，單位為 bytes |
| `allowed_mime_types` | MIME type 白名單；空陣列代表允許所有未被封鎖的類型 |
| `blocked_mime_types` | 預設一律拒絕的 MIME type |
| `collision` | 檔名碰撞策略：`unique`、`error` 或 `overwrite` |
| `checksum_algorithm` | PHP hash 演算法；無效值會回退為 `sha256` |
| `temporary_url_ttl` | temporary URL 預設有效分鐘數 |
| `model` | 必須繼承 `StoredFile` 的 Model class |
| `table` | 儲存檔案紀錄的資料表 |
| `image.quality` | 圖片處理的預設品質 |
| `image.max_width` | 圖片處理的預設最大寬度 |
| `zip.max_files` | 單次 ZIP 下載允許的最大檔案數 |
| `zip.max_size` | 單次 ZIP 下載允許的未壓縮來源總 bytes |

可以透過環境變數覆寫常用設定：

```dotenv
FILE_MAGIC_DISK=s3
FILE_MAGIC_DIRECTORY=uploads
FILE_MAGIC_VISIBILITY=private
```

## 核心操作流程

FileMagic 的操作分成三個階段：

1. 使用 `fromUpload()`、`fromPath()`、`fromContent()`、`fromBase64()`、`text()`、`json()` 或 `csv()` 建立 `PendingFile`。
2. 使用 `onDisk()`、`inDirectory()`、`named()`、`visibility()` 等方法設定儲存方式。
3. 呼叫 `store()` 儲存實體檔案及資料庫紀錄。

```php
$file = FileMagic::fromUpload($uploadedFile)
    ->onDisk('local')
    ->inDirectory('documents')
    ->named('contract')
    ->store();
```

只有來源方法與最後的 `store()` 是必要步驟，中間的設定方法皆為選用。

| 目的 | 方法 |
| --- | --- |
| 建立待儲存檔案 | `fromUpload()`、`fromPath()`、`fromContent()`、`fromBase64()` |
| 產生文件 | `text()`、`json()`、`csv()` |
| 設定儲存位置 | `onDisk()`、`inDirectory()` |
| 設定檔名 | `named()` |
| 完成儲存 | `store()` |
| 查詢與操作已儲存檔案 | `find()` |
| 將多個檔案下載為 ZIP | `find()->downloadZip()` |

## 儲存上傳檔案

將檔案傳給 FileMagic 前，仍應先在 HTTP 邊界進行 Laravel request validation：

```php
use Illuminate\Http\Request;
use Mattmy\FileMagic\Facades\FileMagic;

public function store(Request $request)
{
    $validated = $request->validate([
        'document' => ['required', 'file', 'max:10240'],
    ]);

    $file = FileMagic::fromUpload($validated['document'])->store();

    return response()->json($file);
}
```

FileMagic 會再次根據檔案內容進行檢查。Laravel request validation 與套件內部檢查保護的是不同的信任邊界，不應省略其中任何一層。

## 從其他來源儲存

### 本機路徑

路徑必須指向可讀取的本機檔案：

```php
$file = FileMagic::fromPath(storage_path('imports/report.pdf'))
    ->inDirectory('reports')
    ->store();
```

只能傳入由應用程式信任並選擇的路徑。不要直接把使用者提交的任意伺服器路徑傳入 `fromPath()`。

### 字串或二進位內容

```php
$file = FileMagic::fromContent(
    contents: $pdfContents,
    originalFilename: 'invoice.pdf',
    mimeType: 'application/pdf',
)->inDirectory('invoices')->store();
```

傳入的 MIME type 只會被視為來源提示。實際儲存的 MIME type 與副檔名會根據檔案內容重新偵測。

### 一般 Base64

```php
$file = FileMagic::fromBase64(
    base64: \base64_encode($contents),
    originalFilename: 'document.pdf',
)->store();
```

Data URI 前綴是選用的。省略前綴時，FileMagic 會根據解碼後的實際內容偵測 MIME type，不需要呼叫端自行提供。

### Base64 Data URI

```php
$file = FileMagic::fromBase64(
    base64: 'data:text/plain;base64,'.\base64_encode('Hello'),
    originalFilename: 'hello.txt',
)->store();
```

Base64 採用嚴格解碼。無效或非標準化的內容會拋出 `InvalidBase64`。

Base64 字串本身及解碼後的內容都會占用記憶體。大型檔案應優先使用 upload 或本機路徑來源。

## 產生 TXT、JSON 與 CSV 文件

產生 UTF-8 TXT 文件：

```php
$file = FileMagic::text("第一行\n第二行")
    ->onDisk('local')
    ->inDirectory('documents')
    ->named('notes')
    ->store();
```

`text()` 會完整保留輸入內容，包括空白與換行。空字串會產生空的 `.txt` 檔案。

從 array 或 `JsonSerializable` 物件產生容易閱讀的 JSON：

```php
$file = FileMagic::json([
    'message' => '我是文字',
    'items' => ['第一筆', '第二筆'],
])
    ->named('messages')
    ->store();
```

JSON 使用 pretty print、保留 Unicode 與未跳脫的 slash，並在文件結尾加入換行。

產生 CSV 文件：

```php
$file = FileMagic::csv([
    ['name' => '第一筆', 'content' => '我是文字'],
    ['name' => '第二筆', 'content' => '我是文字 2'],
])
    ->named('messages')
    ->store();
```

Associative rows 會使用第一列的 key 自動產生 header；list rows 不會產生 header。每列必須使用相同的 key 與順序，每個值必須是 scalar 或 `null`。CSV 固定使用不含 BOM 的 UTF-8、逗號 delimiter、雙引號 enclosure 與 CRLF 行尾。

三個方法都會回傳一般的 `PendingFile`，因此仍可使用 disk、directory、filename、visibility、collision、owner、metadata、MIME 與 size 設定。`PendingFile` 固定使用 `store()` 完成儲存，不提供 `storage()`、`toTxt()`、`toJson()` 或 `toCsv()` 別名。儲存後可直接使用既有的 `StoredFile` 與 `FileQuery` API。

無效 UTF-8、無法編碼的 JSON 值，以及結構不一致的 CSV rows 會拋出 `InvalidDocumentData`。

## 自訂儲存方式

```php
use Mattmy\FileMagic\Enums\CollisionPolicy;
use Mattmy\FileMagic\Enums\FileVisibility;

$file = FileMagic::fromUpload($uploadedFile)
    ->onDisk('s3')
    ->inDirectory('accounts/42/contracts')
    ->named('signed-contract')
    ->visibility(FileVisibility::Private)
    ->onCollision(CollisionPolicy::Unique)
    ->store();
```

`named()` 接受不含副檔名的檔名。副檔名會由 FileMagic 根據可信任的 MIME type 決定。

儲存目錄必須是相對路徑。絕對路徑、Windows drive path、null byte、`.` 與 `..` 都會被拒絕。

### 檔名碰撞策略

```php
CollisionPolicy::Unique;
CollisionPolicy::Error;
CollisionPolicy::Overwrite;
```

- `Unique`：目標路徑已存在時，自動加入隨機字串。
- `Error`：目標路徑已存在時拋出 `FileWriteFailed`。
- `Overwrite`：覆寫既有實體檔案並更新同一筆資料庫紀錄。只有確定要取代原檔案時才應使用。

## 檔案大小與 MIME type 限制

限制單次操作的檔案大小：

```php
$file = FileMagic::fromUpload($uploadedFile)
    ->maxSize(10 * 1024 * 1024)
    ->store();
```

設定單次操作的 MIME type 白名單：

```php
$file = FileMagic::fromUpload($uploadedFile)
    ->allowMimeTypes([
        'application/pdf',
        'image/jpeg',
        'image/png',
    ])
    ->store();
```

設定單次操作的 MIME type 黑名單：

```php
$file = FileMagic::fromUpload($uploadedFile)
    ->blockMimeTypes([
        'image/svg+xml',
        'text/html',
    ])
    ->store();
```

單次操作的設定會覆寫對應的全域設定。FileMagic 使用 `finfo` 偵測內容，不信任瀏覽器提供的 MIME header。

## 附加 metadata

Metadata 會轉換為陣列並以 JSON 儲存：

```php
$file = FileMagic::fromUpload($uploadedFile)
    ->withMetadata([
        'category' => 'invoice',
        'year' => 2026,
    ])
    ->store();
```

Metadata 必須可以被 JSON 序列化，而且不應包含密碼、token 或其他機密資料。

## 關聯 owner

任何已儲存的 Eloquent Model 都可以成為檔案 owner：

```php
$file = FileMagic::fromUpload($uploadedFile)
    ->ownedBy($user)
    ->store();

$owner = $file->owner;
```

可以在 owner model 增加反向關聯：

```php
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Mattmy\FileMagic\Models\StoredFile;

public function files(): MorphMany
{
    return $this->morphMany(StoredFile::class, 'owner');
}
```

從 owner model eager load relation，再把已取得的 File Model 傳給 FileMagic：

```php
$post = Post::query()
    ->with('attachment')
    ->findOrFail($postId);

return FileMagic::find($post->attachment)->download();
```

傳入既有的 `StoredFile` Model 不會再次執行資料庫查詢。

`owner_id` 使用字串欄位，因此可支援整數、UUID 與 ULID 主鍵。

## 圖片縮放

先安裝 optional dependency：

```bash
composer require "intervention/image:^4.0"
```

確認 GD 或 Imagick 已啟用後，即可按比例縮放：

```php
$file = FileMagic::fromUpload($image)
    ->resizeImage(maxWidth: 1600, quality: 82)
    ->store();
```

使用設定檔的預設尺寸及品質：

```php
$file = FileMagic::fromUpload($image)
    ->resizeImage()
    ->store();
```

目前會在使用中的 driver 支援時處理 JPEG、PNG、WebP 與 BMP。

`resizeImage()` 採用 best-effort 行為：非圖片、GIF、SVG、不支援的格式，以及 Intervention Image 無法解碼或編碼的內容，都會忽略圖片設定並原樣儲存，不會拋出圖片處理例外。無效的圖片選項，以及處理受支援圖片時缺少 Intervention Image、GD 或 Imagick，仍會拋出明確例外。

## 查詢檔案

一般檔案查詢全部透過唯一的 `find()` 入口。它可以接受正整數 ID、UUID 或已存在的 `StoredFile` Model：

```php
$file = FileMagic::find($id)->one();
$file = FileMagic::find($uuid)->one();
$file = FileMagic::find($fileModel)->one();
```

不需要先取出 Model，也可以直接操作第一個符合的檔案：

```php
FileMagic::find($uuid)->contents();
FileMagic::find($fileModel)->download();
FileMagic::find($id)->delete();
```

批次查詢支援 variadic targets、array 及 Laravel Collection：

```php
$variadic = FileMagic::find(
    $firstId,
    $secondUuid,
    $fileModel,
)->get();

$array = FileMagic::find([
    $firstId,
    $secondUuid,
    $fileModel,
])->get();

$collection = FileMagic::find(collect([
    $firstId,
    $secondUuid,
    $fileModel,
]))->get();
```

三種形式都會保留輸入順序並移除重複 Model。ID 與 UUID 會合併成一筆查詢；Model target 會直接使用，不會重新查詢。空 array 或 Collection 會回傳空的 `Illuminate\Support\Collection`，且不執行查詢。

Array 與 Collection 必須是一維結構，每個元素都必須是正整數 ID、合法 UUID 或已儲存的 `StoredFile`。無效元素會拋出 `InvalidFileTarget`，不會被靜默移除。

`one()` 會回傳第一個符合的 `StoredFile` 或 `null`；`get()` 會回傳 `Illuminate\Support\Collection<int, StoredFile>`，可使用完整的 Laravel Collection API，同時不會暴露 Eloquent query builder。

## 取得 URL

公開 URL：

```php
$url = FileMagic::find($target)->url();
```

使用設定檔預設有效時間的 temporary URL：

```php
$url = FileMagic::find($target)->temporaryUrl();
```

自訂到期時間：

```php
$url = FileMagic::find($target)
    ->temporaryUrl(now()->addMinutes(30));
```

批次取得公開 URL：

```php
$urls = FileMagic::find([
    $firstUuid,
    $secondUuid,
])->urls();
```

`urls()` 會回傳以 Model key 為索引的 `Illuminate\Support\Collection<int|string, string>`。實體檔案不存在於 disk 的紀錄不會包含在結果中。

使用的 disk 必須支援對應的 URL 操作。本機 temporary URL 需要在 Laravel local disk 設定 `serve => true`；S3 等 cloud disk 則需要完成正常的憑證及 bucket 設定。

## 讀取與串流

檢查實體檔案是否存在：

```php
if (FileMagic::find($target)->exists()) {
    // 實體檔案存在。
}
```

將小型檔案完整讀入記憶體：

```php
$contents = FileMagic::find($target)->contents();
```

大型檔案應使用 stream：

```php
$stream = FileMagic::find($target)->readStream();

try {
    while (\feof($stream) === false) {
        $chunk = \fread($stream, 8192);

        if ($chunk === false) {
            break;
        }

        // 處理目前讀取到的內容。
    }
} finally {
    \fclose($stream);
}
```

呼叫端擁有 `readStream()` 回傳的 stream，並且必須負責關閉它。

## 下載

使用原始檔名下載：

```php
return FileMagic::find($target)->download();
```

自訂下載檔名：

```php
return FileMagic::find($target)->download('invoice-2026.pdf');
```

Laravel Filesystem 會以 stream 回傳 response，並使用從內容偵測到的 MIME type。

## 將多個檔案下載為 ZIP

必須先安裝並啟用 PHP `ext-zip`。

使用安全的自動產生下載名稱：

```php
return FileMagic::find([
    $firstId,
    $secondUuid,
    $fileModel,
])->downloadZip();
```

自訂 ZIP 下載名稱：

```php
return FileMagic::find($targets)->downloadZip('project-documents');
```

傳入 `project-documents` 或 `project-documents.zip` 都會產生
`project-documents.zip`。ZIP entry 預設使用每個檔案的原始名稱；名稱重複時會依
query 順序產生 `report (2).pdf`、`report (3).pdf`。

ZIP 會透過有上限的本機暫存檔串流建立，不會把所有來源內容同時載入記憶體。
Response 傳送完成後會自動刪除暫存 archive。每次操作受到 `zip.max_files` 與
`zip.max_size` 限制；`zip.max_size` 計算未壓縮的來源總 bytes。

查詢結果為空或任一實體檔案不存在時，整個操作都會失敗，不會靜默回傳不完整的
ZIP。`downloadZip()` 只建立暫時的 HTTP 下載，不會新增 `StoredFile` 紀錄。

## 刪除檔案

刪除單筆實體檔案與資料庫紀錄：

```php
$deleted = FileMagic::find($target)->delete();
```

批次刪除：

```php
$deleted = FileMagic::find($targets)->delete();
```

批次刪除會按照 disk 分組處理實體路徑，再使用一筆 database query 刪除紀錄。大量刪除時不要在迴圈中逐一呼叫 model 的 `delete()`。

## 自訂 Model

建立繼承套件 Model 的應用程式 Model：

```php
namespace App\Models;

use Mattmy\FileMagic\Models\StoredFile as BaseStoredFile;

final class StoredFile extends BaseStoredFile
{
    // 加入應用程式專用的 relationship 或 scope。
}
```

更新設定：

```php
'model' => App\Models\StoredFile::class,
```

自訂 Model 必須繼承套件提供的 `StoredFile`。

## 自訂資料表

發佈及執行 migration 前修改：

```php
'table' => 'assets',
```

如果 migration 已經部署到正式環境，應建立新的 migration 重新命名資料表，不要修改已經部署的 migration。

## 例外

所有套件例外都繼承 `Mattmy\FileMagic\Exceptions\FileMagicException`。

| 例外 | 原因 |
| --- | --- |
| `InvalidFileSource` | 無效 upload、路徑或 stream |
| `InvalidBase64` | 無效 Base64 或 Data URI |
| `InvalidDocumentData` | 無效 UTF-8、JSON 資料或 CSV rows |
| `InvalidFileName` | 不安全或系統保留的檔名 |
| `InvalidStoragePath` | 不安全的相對目錄 |
| `InvalidFileTarget` | 無效 ID、UUID、Model、array 或 Collection target |
| `FileTooLarge` | 檔案超過 byte 限制 |
| `DisallowedMimeType` | MIME type 不被允許 |
| `FileWriteFailed` | storage 寫入、檔名碰撞或刪除失敗 |
| `FileRecordFailed` | database 紀錄儲存失敗 |
| `FileNotFound` | 找不到符合的檔案紀錄，或實體檔案內容或 stream 不存在 |
| `ImageProcessingUnavailable` | 處理受支援圖片時缺少圖片 dependency 或 driver |
| `ZipCreationUnavailable` | PHP `ext-zip` 不可用 |
| `ZipCreationFailed` | 暫存 ZIP 建立或結束寫入失敗 |
| `ZipLimitExceeded` | ZIP 檔案數量或未壓縮大小超過限制 |

在應用程式層處理例外：

```php
use Mattmy\FileMagic\Exceptions\FileMagicException;

try {
    $file = FileMagic::fromUpload($uploadedFile)->store();
} catch (FileMagicException $exception) {
    report($exception);

    return back()->withErrors([
        'file' => '檔案無法儲存。',
    ]);
}
```

套件不會自行決定 HTTP status code 或 response 格式。

## 測試

搭配 Laravel fake storage：

```php
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mattmy\FileMagic\Facades\FileMagic;

Storage::fake('documents');

$file = FileMagic::fromUpload(
    UploadedFile::fake()->createWithContent('notes.txt', 'hello'),
)->onDisk('documents')->store();

Storage::disk('documents')->assertExists($file->path);

expect($file->contents())->toBe('hello');
```

需要進行 database assertion 時，請使用 `RefreshDatabase` 並確保測試環境已載入套件 migration。

## 效能注意事項

- Upload 與本機路徑會使用 stream 檢查及儲存。
- Checksum 會分段計算。
- `contents()` 會將整個檔案載入記憶體，大型檔案應使用 `readStream()`。
- Base64 一定會使用額外記憶體。
- 圖片解碼後的記憶體用量可能遠高於壓縮檔案大小。
- ZIP 下載會使用本機暫存空間，最高可能同時包含未壓縮來源檔案及 archive。
- 將多個目標一次傳給 `find()`，套件會合併資料庫查詢。
- 大量查詢應使用 `find()`，大量刪除應使用 `FileQuery::delete()`。

## 安全性注意事項

- 每個儲存、讀取、下載及刪除操作都必須先進行 authorization。
- 在套件前保留 Laravel request validation。
- 將原始檔名與 client MIME type 視為不可信任的 metadata。
- 高敏感度上傳流程應採用 MIME type 白名單。
- 從同一網域提供檔案時，應考慮封鎖 HTML 與 SVG。
- 私密檔案應放在 private disk，並使用短效 temporary URL。
- 不要把使用者控制的伺服器路徑直接傳給 `fromPath()`。
- ZIP 下載前必須先對每個 target 進行 authorization。
- 除了 `max_size`，也應設定 Web Server 與 PHP request limit。
- 威脅模型有需求時，應額外串接防毒掃描服務。

## API 參考

### `FileMagic`

```php
fromUpload(UploadedFile $file): PendingFile
fromPath(string $path): PendingFile
fromContent(string $contents, ?string $originalFilename = null, ?string $mimeType = null): PendingFile
fromBase64(string $base64, ?string $originalFilename = null): PendingFile
text(string $text): PendingFile
json(array|\JsonSerializable $data): PendingFile
csv(iterable $rows): PendingFile
find(int|string|StoredFile|array|Collection ...$targets): FileQuery
```

### `PendingFile`

```php
onDisk(string $disk): self
inDirectory(string $directory): self
named(string|int $filename): self
visibility(FileVisibility $visibility): self
onCollision(CollisionPolicy $policy): self
maxSize(int $bytes): self
allowMimeTypes(array $mimeTypes): self
blockMimeTypes(array $mimeTypes): self
withMetadata(array $metadata): self
ownedBy(Model $owner): self
resizeImage(?int $maxWidth = null, ?int $quality = null): self
store(): StoredFile
```

### `FileQuery`

```php
one(): ?StoredFile
get(): Collection
urls(): Collection
exists(): bool
url(): string
temporaryUrl(?DateTimeInterface $expiration = null): string
contents(): string
readStream(): resource
download(?string $name = null): StreamedResponse
downloadZip(?string $name = null): BinaryFileResponse
delete(): int
```

`get()` 回傳 `Illuminate\Support\Collection<int, StoredFile>`，可直接使用 `map()`、`filter()`、`groupBy()`、`pluck()`、`values()` 等 Laravel Collection 方法。涉及檔案系統的批次行為仍應保留在 `FileQuery`，請在捨棄 query 物件前呼叫 `urls()` 或 `delete()`。

## 常見問題

### MIME type 與瀏覽器提供的值不同

這是正常行為。FileMagic 信任 `finfo` 根據檔案內容偵測的結果，而不是瀏覽器提供的 MIME type。

### 檔案被指定為 `.bin`

Symfony Mime 找不到偵測到的 MIME type 所對應的副檔名。請檢查紀錄中的 `mime_type`，再決定該工作流程是否應允許這種檔案。

### 本機 temporary URL 無法使用

在 Laravel local disk 啟用 `serve => true`，或改用支援 temporary URL 的 driver。

### 圖片處理拋出 `ImageProcessingUnavailable`

安裝 `intervention/image` 並啟用 GD 或 Imagick。非圖片與不支援格式會自動略過圖片處理，不會拋出此例外。

### 實體檔案被外部系統移除

`existsOnDisk()` 會回傳 `false`；`contents()` 與 `readStream()` 會拋出 `FileNotFound`。外部 storage 變更應由應用程式專用的維護流程進行同步。

### 發佈 migration 後檔名包含時間

Service Provider 會為發佈的 migration 加上 timestamp，確保 Laravel 按正確順序執行。每個專案只需發佈一次，並將產生的 migration 納入版本控制。

### ZIP 下載功能無法使用

請安裝並啟用 PHP `ext-zip`，同時確認 PHP process 可以寫入系統暫存目錄，且暫存
空間足以容納來源檔案及 ZIP archive。

## 授權

FileMagic 是使用 [MIT License](LICENSE) 發佈的開源軟體。

## 專案維護

- 提交 Pull Request 前請閱讀 [CONTRIBUTING.md](CONTRIBUTING.md)。
- 安全性問題請依照 [SECURITY.md](SECURITY.md) 私下回報。
- 發布版本遵守 [Semantic Versioning](https://semver.org/)，變更內容記錄於
  [CHANGELOG.md](CHANGELOG.md)。
