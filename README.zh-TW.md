# FileMagic

[English](README.md) | [繁體中文](README.zh-TW.md)

FileMagic 是一個採用強型別設計的 Laravel 檔案管理套件。它可以接收上傳檔案、可讀取的本機路徑、二進位字串、一般 Base64 與 Base64 Data URI，並從實際內容偵測可信任的檔案資訊，透過 Laravel Filesystem 儲存檔案，再使用 Eloquent 保存檔案紀錄。

## 系統需求

- PHP 8.2 或以上
- Laravel 11、12 或 13
- PHP `fileinfo` extension
- 至少一個已設定完成的 Laravel Filesystem disk

圖片縮放功能另外需要：

- `intervention/image` 3.8 或以上
- PHP GD 或 Imagick extension

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

如果專案停用了 package discovery，可以手動註冊 Service Provider：

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

可以透過環境變數覆寫常用設定：

```dotenv
FILE_MAGIC_DISK=s3
FILE_MAGIC_DIRECTORY=uploads
FILE_MAGIC_VISIBILITY=private
```

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
    \base64_encode($contents),
    'document.pdf',
)->store();
```

### Base64 Data URI

```php
$file = FileMagic::fromBase64(
    'data:text/plain;base64.'.\base64_encode('Hello'),
    'hello.txt',
)->store();
```

Base64 採用嚴格解碼。無效或非標準化的內容會拋出 `InvalidBase64`。

Base64 字串本身及解碼後的內容都會占用記憶體。大型檔案應優先使用 upload 或本機路徑來源。

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
composer require intervention/image
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

目前支援 JPEG、PNG、WebP 與 BMP。

GIF 與 SVG 不會被圖片處理器轉換，以免 GIF 動畫被無聲移除，或把可能包含主動內容的 SVG 當成普通點陣圖片。若不呼叫 `resizeImage()`，仍然可以將它們當成一般檔案儲存。

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

三種形式都會保留輸入順序並移除重複 Model。ID 與 UUID 會合併成一筆查詢；Model target 會直接使用，不會重新查詢。空 array 或 Collection 會回傳空的 `FileCollection`，且不執行查詢。

Array 與 Collection 必須是一維結構，每個元素都必須是正整數 ID、合法 UUID 或已儲存的 `StoredFile`。無效元素會拋出 `InvalidFileTarget`，不會被靜默移除。

`one()` 會回傳第一個符合的 `StoredFile` 或 `null`；`get()` 會回傳可執行批次行為的 `FileCollection`，而不是 Eloquent query builder。

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

## 刪除檔案

刪除單筆實體檔案與資料庫紀錄：

```php
$deleted = FileMagic::find($target)->delete();
```

批次刪除：

```php
$files = FileMagic::find($targets)->get();
$deleted = $files->delete();
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
| `InvalidFileName` | 不安全或系統保留的檔名 |
| `InvalidStoragePath` | 不安全的相對目錄 |
| `InvalidFileTarget` | 無效 ID、UUID、Model、array 或 Collection target |
| `FileTooLarge` | 檔案超過 byte 限制 |
| `DisallowedMimeType` | MIME type 不被允許 |
| `FileWriteFailed` | storage 寫入、檔名碰撞或刪除失敗 |
| `FileRecordFailed` | database 紀錄儲存失敗 |
| `FileNotFound` | 實體檔案內容或 stream 不存在 |
| `ImageProcessingUnavailable` | 缺少圖片 dependency、driver 或格式不支援 |

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
- 批次查詢應使用 `whereIn()`。
- 查詢 owner 時應使用 `with('owner')`。
- 大量查詢應使用 `find()`，大量刪除應使用 `FileCollection::delete()`。

## 安全性注意事項

- 每個儲存、讀取、下載及刪除操作都必須先進行 authorization。
- 在套件前保留 Laravel request validation。
- 將原始檔名與 client MIME type 視為不可信任的 metadata。
- 高敏感度上傳流程應採用 MIME type 白名單。
- 從同一網域提供檔案時，應考慮封鎖 HTML 與 SVG。
- 私密檔案應放在 private disk，並使用短效 temporary URL。
- 不要把使用者控制的伺服器路徑直接傳給 `fromPath()`。
- 除了 `max_size`，也應設定 Web Server 與 PHP request limit。
- 威脅模型有需求時，應額外串接防毒掃描服務。

## 從 `App\Support\File` 遷移

儲存上傳檔案：

```php
// 舊版
App\Support\File\FileMagic::parse($uploadedFile)->save();

// 新版
Mattmy\FileMagic\Facades\FileMagic::fromUpload($uploadedFile)->store();
```

儲存 Base64：

```php
// 舊版
App\Support\File\FileMagic::base64($base64)->save();

// 新版
Mattmy\FileMagic\Facades\FileMagic::fromBase64($base64)->store();
```

查詢 UUID：

```php
// 舊版
App\Support\File\FileMagic::find($uuid)->one();

// 新版
Mattmy\FileMagic\Facades\FileMagic::find($uuid)->one();
```

讀取內容：

```php
// 舊版
$data = App\Support\File\FileMagic::find($id)->data();
$contents = $data?->content();

// 新版
$contents = Mattmy\FileMagic\Facades\FileMagic::find($id)->contents();
```

遷移舊資料時，應建立應用程式專用 migration 或 command，先確認舊版 `name`、`original_name` 與 `path` 如何對應到新版的完整 `path`。資料結構轉換完成前，不要讓兩個 Model 共用同一張資料表。

## API 參考

### `FileMagic`

```php
fromUpload(UploadedFile $file): PendingFile
fromPath(string $path): PendingFile
fromContent(string $contents, ?string $originalFilename = null, ?string $mimeType = null): PendingFile
fromBase64(string $base64, ?string $originalFilename = null): PendingFile
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
get(): FileCollection
exists(): bool
url(): string
temporaryUrl(?DateTimeInterface $expiration = null): string
contents(): string
readStream(): resource
download(?string $name = null): StreamedResponse
delete(): int
```

### `FileCollection`

```php
count(): int
isEmpty(): bool
first(): ?StoredFile
urls(): Collection
delete(): int
getIterator(): Traversable
```

## 常見問題

### MIME type 與瀏覽器提供的值不同

這是正常行為。FileMagic 信任 `finfo` 根據檔案內容偵測的結果，而不是瀏覽器提供的 MIME type。

### 檔案被指定為 `.bin`

Symfony Mime 找不到偵測到的 MIME type 所對應的副檔名。請檢查紀錄中的 `mime_type`，再決定該工作流程是否應允許這種檔案。

### 本機 temporary URL 無法使用

在 Laravel local disk 啟用 `serve => true`，或改用支援 temporary URL 的 driver。

### 圖片處理拋出 `ImageProcessingUnavailable`

安裝 `intervention/image`、啟用 GD 或 Imagick，並確認輸入格式為 JPEG、PNG、WebP 或 BMP。

### 實體檔案被外部系統移除

`existsOnDisk()` 會回傳 `false`；`contents()` 與 `readStream()` 會拋出 `FileNotFound`。外部 storage 變更應由應用程式專用的維護流程進行同步。

### 發佈 migration 後檔名包含時間

Service Provider 會為發佈的 migration 加上 timestamp，確保 Laravel 按正確順序執行。每個專案只需發佈一次，並將產生的 migration 納入版本控制。

## 授權

FileMagic 是使用 [MIT License](LICENSE) 發佈的開源軟體。
