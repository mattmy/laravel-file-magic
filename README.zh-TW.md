# FileMagic

[English](README.md)

透過清楚一致的 API 在 Laravel 管理檔案。FileMagic 使用 Eloquent 保存檔案紀錄，
並支援 Laravel Filesystem disks，讓你不必針對每種來源重複開發儲存、查詢、讀取、
下載與刪除流程。

## 可以做什麼

- 儲存上傳檔案、本機檔案、字串內容、Base64 與遠端 HTTP(S) 檔案。
- 產生並儲存 TXT、JSON、CSV，以及具有可信任 MIME type 的應用程式產物。
- 為每個檔案選擇 disk、目錄、檔名、visibility 與檔名碰撞處理方式。
- 限制檔案大小，並允許或封鎖指定 MIME types。
- 加入 metadata，並將檔案關聯至 Eloquent Model。
- 在儲存前縮小支援的圖片。
- 透過 ID、UUID、Model、array 或 Laravel Collection 尋找檔案。
- 讀取內容、開啟 stream、產生 URL 與回傳下載。
- 將多個檔案下載為 ZIP，或批次刪除檔案。
- 稽核已經找不到實體檔案的 database records。

## 系統需求

- PHP 8.3 或以上
- Laravel 12 或 13
- PHP `ext-fileinfo`
- 至少一個設定完成的 Laravel Filesystem disk

遠端檔案需要 PHP `ext-curl`；圖片縮放需要 Intervention Image 4 搭配 GD 或 Imagick；
ZIP 下載需要 PHP `ext-zip`。

## 安裝

```bash
composer require mattmy/laravel-file-magic
php artisan vendor:publish --tag=file-magic-config
php artisan vendor:publish --tag=file-magic-migrations
php artisan migrate
```

## 快速開始

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

```php
// 不安全：bytes 與 MIME type 都不是由應用程式控制。
FileMagic::fromGeneratedContent($request->getContent(), null, $request->header('Content-Type'));
```

完整 `$contents` 字串在呼叫前就已位於 PHP 記憶體。產物可能接近 worker 記憶體上限時，請改用
upload、path 或 remote source。

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

需要在儲存前縮小支援的圖片時，可以使用 `resizeImage()`：

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

大型檔案請使用 `readStream()`，不要使用 `contents()`；使用完畢後必須關閉回傳的 stream。

## 安全性與資源限制

套件設定會被嚴格驗證。Base64 輸入會在解碼前依解碼後的大小拒絕，之後以有界線的區塊解碼至暫存 stream：編碼後輸入保留於記憶體，解碼後的 bytes 使用暫存磁碟空間。儲存路徑必須事先是 canonical 形式，而圖片的大小與 MIME 政策會在處理前後都檢查。

## ZIP 下載與刪除

```php
return FileMagic::find($targets)->downloadZip('documents');
```

```php
$deleted = FileMagic::find($targets)->delete();
```

應用程式必須先確認使用者有權限操作每個要讀取、下載或刪除的檔案。
啟用 collision lock 時，儲存、`FileQuery::delete()` 與 audit cleanup 會協調相同 storage path；
direct `StoredFile::delete()` 與外部 mutation 不在此保證範圍。

## 一致性稽核

檢查 database record 在 storage 上是否仍有對應檔案：

```bash
php artisan file-magic:audit
```

除非加上 `--delete-missing-records`，否則指令不會修改資料。Cleanup 會在鎖內重新確認 record
identity，並在刪除前再次檢查 storage。遠端 disk 可能增加網路
等待時間與 storage request 費用，排程清理前請先閱讀稽核文件。

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

所有方法、欄位、參數、例外、設定、效能及安全注意事項，請參考
[FileMagic 繁體中文文件](https://mattmy.github.io/laravel-file-magic-docs/zh-TW/)。

安全性問題請依照 [SECURITY.md](SECURITY.md) 私下回報。

## 授權

FileMagic 是使用 [MIT License](LICENSE) 發佈的開源軟體。
