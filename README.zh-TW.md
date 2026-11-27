# FileMagic

[English](README.md)

FileMagic 將檔案存到 Laravel Filesystem 磁碟，並為每個檔案保留一筆 Eloquent `StoredFile` 紀錄。

## 可以做什麼

- 儲存上傳檔案、本機檔案、文字或二進位內容、Base64 與遠端 HTTP(S) 檔案。
- 產生 TXT、JSON 或 CSV，也能用可信任的 MIME 類型儲存應用程式自行產生的內容。
- 限制檔案大小與 MIME 類型、設定檔名衝突的處理方式，以及縮小支援的圖片。
- 附加中繼資料，並將檔案關聯至 Eloquent Model。
- 用 ID、UUID 或 Model 查出檔案，再讀取內容、產生網址、下載單檔或 ZIP，也能批次刪除。
- 稽核哪些資料庫紀錄對應的檔案已遺失。

## 系統需求

- PHP 8（最低版本為 8.3）
- Laravel 12 或 13
- PHP `ext-fileinfo`
- 至少一個已設定的 Laravel Filesystem 磁碟及資料庫連線

匯入遠端檔案需要 `ext-curl`，下載 ZIP 需要 `ext-zip`。

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
// 請求內容和 MIME 類型都由呼叫端提供，這種用法不安全。
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

覆寫時，省略 `ownedBy()` 或 `withMetadata()` 會保留對應的原值。傳入 `withMetadata([])` 可清空中繼資料。

要在儲存前縮小支援的圖片，可使用 `resizeImage()`。

```php
$image = FileMagic::fromUpload($uploadedImage)
    ->resizeImage(maxWidth: 1200, quality: 80)
    ->store();
```

FileMagic 會在縮放前檢查大小與 MIME 類型，輸出有變更時會再檢查一次。

## 尋找與使用檔案

```php
$query = FileMagic::find($uuid);
$file = $query->one();
$files = FileMagic::find([$firstId, $secondUuid])->get();
$exists = $query->exists();
$url = $query->url();
$temporaryUrl = $query->temporaryUrl();
$customTemporaryUrl = $query->temporaryUrl(now()->addMinutes(15));
$contents = $query->contents();

return $query->download();
```

`find()` 接受 `'123'` 這類十進位 ID 字串，並略過 `1.0` 這類無效純量。
沿用同一個 `FileQuery` 可避免重複查詢。`contents()` 會把整個檔案讀進記憶體。大型檔案請用 `readStream()`，
用完後關閉串流。

## 安全性與資源限制

- 應用程式須檢查每次操作的權限。下載 ZIP 時，要確認使用者可存取其中每個檔案。
- 儲存路徑須為以 `/` 分隔的相對路徑。
- `store()` 會把來源複製到本機暫存檔。請另為 Base64 解碼檔、遠端下載、ZIP 內容與壓縮檔，以及覆寫備份預留空間。
- FileMagic 會在解碼前檢查 Base64 解碼後的大小。Base64 編碼字串和 `csv()` 產生的完整內容都會占用 PHP 記憶體。

## ZIP 下載與刪除

```php
return FileMagic::find($targets)->downloadZip('documents');
```

```php
$deleted = FileMagic::find($targets)->delete();
```

啟用路徑鎖定後，儲存、批次刪除與稽核清理會共用同一個快取後端，並在鎖的有效期限內協調。
直接呼叫 `StoredFile::delete()` 或從外部寫入時不會使用這些鎖。

## 一致性稽核

檢查資料庫紀錄對應的檔案是否仍存在。

```bash
php artisan file-magic:audit
```

稽核預設為唯讀。`--delete-missing-records` 會重新核對紀錄與檔案，確認檔案仍遺失才刪除紀錄。
非互動清理需要 `--force`。使用遠端磁碟時，每次檢查檔案是否存在都可能增加等待時間與請求費用。

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

FileMagic 採用 [MIT 授權條款](LICENSE)。
