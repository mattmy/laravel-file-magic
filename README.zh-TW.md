# FileMagic

[English](README.md)

FileMagic 將檔案存到 Laravel Filesystem 磁碟，並回傳一筆 Eloquent `StoredFile` 紀錄。
要讀取、下載或刪除檔案時，可用 `FileMagic::find()` 查詢。

## 可以做什麼

- 儲存上傳檔案、本機檔案、字串內容、Base64 與遠端 HTTP(S) 檔案。
- 產生 TXT、JSON 或 CSV，也能用可信任的 MIME 類型儲存應用程式自行產生的內容。
- 限制檔案大小與 MIME 類型、設定檔名衝突的處理方式，以及縮小支援的圖片。
- 附加中繼資料，並將檔案關聯至 Eloquent Model。
- 用 ID、UUID 或 Model 查詢。批次查詢可傳入陣列或 Laravel Collection。
- 產生檔案網址、將多個檔案打包成 ZIP 下載，或批次刪除檔案。
- 稽核哪些資料庫紀錄對應的檔案已遺失。

## 系統需求

- PHP 8.3–8.x
- Laravel 12 或 13
- PHP `ext-fileinfo`
- 至少一個已設定的 Laravel Filesystem 磁碟及資料庫連線

遠端檔案需要 PHP `ext-curl`；圖片縮放功能另外需要 `intervention/image` 4.x 與 PHP GD 或 Imagick；
ZIP 下載需要 PHP `ext-zip`。

## 安裝

```bash
composer require mattmy/laravel-file-magic
php artisan vendor:publish --tag=file-magic-migrations
php artisan migrate
```

要調整預設值時，可執行 `php artisan vendor:publish --tag=file-magic-config` 發布設定檔。

如果需要圖片縮放功能，請另外安裝 Intervention Image 4，並啟用 PHP GD 或 Imagick：

```bash
composer require intervention/image:^4.0
```

## 快速開始

使用這段範例前，請先驗證上傳檔案並檢查使用者的權限。

```php
use Mattmy\FileMagic\Facades\FileMagic;

$file = FileMagic::fromUpload($request->file('document'))
    ->onDisk('local')
    ->inDirectory('documents')
    ->named('contract')
    ->store();

return FileMagic::find($file)->download();
```

## 從不同來源儲存檔案

```php
FileMagic::fromUpload($uploadedFile)->store();
FileMagic::fromPath($trustedPath)->store();
FileMagic::fromContent($contents, 'report.pdf')->store();
FileMagic::fromGeneratedContent($dxfContents, 'drawing.dxf', 'image/vnd.dxf')->store();
FileMagic::fromBase64($base64, 'avatar.png')->store();
FileMagic::fromUrl('https://example.com/manual.pdf')->store();
```

產生的文件也能使用相同的儲存選項：

```php
FileMagic::text('Hello')->named('message')->store();
FileMagic::json(['status' => 'ready'])->named('status')->store();
FileMagic::csv($rows)->named('report')->store();
```

`fromContent()` 會從內容偵測 MIME 類型。使用 `fromGeneratedContent()` 時，只有 Symfony 找得到
對應副檔名，FileMagic 才會採用傳入的 MIME 類型。請只在應用程式能保證內容格式時指定可信任的 MIME 類型。
若省略 MIME 類型或找不到對應的副檔名，FileMagic 會改用 `fromContent()` 偵測內容。

```php
// 不安全：bytes 與 MIME type 都不是由應用程式控制。
FileMagic::fromGeneratedContent($request->getContent(), null, $request->header('Content-Type'));
```

要儲存 HTTP 請求的原始內容，請用 `fromContent($request->getContent())`。這兩個方法都會接收
已存在於 PHP 記憶體的完整 `$contents` 字串。大型檔案請依來源改用上傳、本機路徑或網址。

## 設定檔案

```php
use Mattmy\FileMagic\Enums\CollisionPolicy;
use Mattmy\FileMagic\Enums\FileVisibility;

$file = FileMagic::fromUpload($uploadedFile)
    ->onDisk('s3')
    ->inDirectory('accounts/42/contracts')
    ->named('signed-contract')
    ->visibility(FileVisibility::Private)
    ->onCollision(CollisionPolicy::Unique)
    ->maxSize(10 * 1024 * 1024)
    ->allowMimeTypes(['application/pdf'])
    ->withMetadata(['category' => 'contract'])
    ->ownedBy($user)
    ->store();
```

`named()` 會保留檔名主體中的點號，再依 MIME 類型附加副檔名。例如純文字檔指定 `report.txt` 時，
在檔名未被占用的情況下會儲存為 `report.txt.txt`。

要在儲存前縮小支援的圖片，可使用 `resizeImage()`。

```php
$image = FileMagic::fromUpload($uploadedImage)
    ->resizeImage(maxWidth: 1200, quality: 80)
    ->store();
```

## 尋找與使用檔案

```php
$file = FileMagic::find($uuid)->one();
$files = FileMagic::find([$firstId, $secondUuid])->get();
$exists = FileMagic::find($uuid)->exists();
$url = FileMagic::find($uuid)->url();
$temporaryUrl = FileMagic::find($uuid)->temporaryUrl();
$customTemporaryUrl = FileMagic::find($uuid)->temporaryUrl(now()->addMinutes(15));
$contents = FileMagic::find($uuid)->contents();
$stream = FileMagic::find($uuid)->readStream();

return FileMagic::find($uuid)->download();
```

對同一批目標執行多個操作時，可沿用同一個 `FileQuery`。大型檔案請用 `readStream()`，用完後要關閉串流。
`contents()` 會把整個檔案讀進記憶體。

## 安全性與資源限制

FileMagic 會在解碼前檢查 Base64 解碼後的大小。編碼字串留在記憶體，解碼結果則分段寫入暫存檔。
儲存路徑須為以 `/` 分隔的相對路徑。圖片處理前會檢查大小和 MIME 類型，輸出有變更時會再檢查一次。

## ZIP 下載與刪除

```php
return FileMagic::find($targets)->downloadZip('documents');
```

```php
$deleted = FileMagic::find($targets)->delete();
```

應用程式須檢查每次操作的權限，包括 ZIP 內的每個檔案。啟用路徑鎖定後，儲存、批次刪除與稽核清理
會透過同一個快取後端，在鎖的有效期限內協調。直接呼叫 `StoredFile::delete()` 或從外部寫入時不會使用這些鎖。

## 一致性稽核

檢查資料庫紀錄對應的檔案是否仍存在。

```bash
php artisan file-magic:audit
```

稽核預設為唯讀。加上 `--delete-missing-records` 後，會重新核對紀錄與檔案，確認檔案仍遺失才刪除紀錄。
啟用路徑鎖定時，這些檢查會在鎖內進行。使用遠端磁碟時，每次確認檔案是否存在都可能增加等待時間與請求費用。

## 處理錯誤

所有套件例外都繼承 `FileMagicException`：

```php
use Mattmy\FileMagic\Exceptions\FileMagicException;

try {
    $file = FileMagic::fromUpload($uploadedFile)->store();
} catch (FileMagicException $exception) {
    report($exception);
}
```

## 完整文件

操作指南與 API 參考請見 [FileMagic 繁體中文文件](https://mattmy.github.io/laravel-file-magic-docs/zh-TW/)。

安全性問題請依照 [SECURITY.md](SECURITY.md) 私下回報。

## 授權

FileMagic 是使用 [MIT License](LICENSE) 發佈的開源軟體。
