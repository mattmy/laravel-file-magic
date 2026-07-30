# FileMagic

[English](README.md) | [繁體中文](README.zh-TW.md)

FileMagic 是專為 Laravel 打造的檔案管理套件。它透過 Laravel Filesystem 與
Eloquent，提供一致的檔案接收、驗證、儲存、查詢、下載及刪除流程。

## 系統需求

- PHP 8.3 或以上
- Laravel 12 或 13
- PHP `ext-fileinfo`
- 至少一個設定完成的 Laravel Filesystem disk

Composer 會在安裝階段檢查 `ext-fileinfo`，因為 FileMagic 必須依實際檔案內容偵測
MIME type，而不會信任檔名或 client 提供的 MIME type。

透過 `fromUrl()` 匯入遠端 HTTP(S) 檔案時，另外需要 PHP `ext-curl`。缺少時其他功能
仍可使用，但儲存遠端來源會拋出 `RemoteDownloadUnavailable`。圖片縮放另外需要
`intervention/image` 4.0 或以上，以及 GD 或 Imagick。ZIP 批次下載另外需要 PHP
`ext-zip`。

## 安裝

```bash
composer require mattmy/laravel-file-magic
php artisan vendor:publish --tag=file-magic-config
php artisan vendor:publish --tag=file-magic-migrations
php artisan migrate
```

## 快速開始

儲存上傳檔案：

```php
use Mattmy\FileMagic\Facades\FileMagic;

$file = FileMagic::fromUpload($uploadedFile)
    ->onDisk('local')
    ->inDirectory('documents')
    ->named('contract')
    ->store();
```

透過 ID、UUID、Model、array 或 Laravel Collection 取得檔案：

```php
$file = FileMagic::find($uuid)->one();

return FileMagic::find($file)->download();
```

本機路徑、字串或二進位內容、Base64、遠端 HTTP(S) 檔案，以及產生的 TXT、JSON、
CSV 文件，都能使用相同的 `PendingFile` 流程：

```php
$document = FileMagic::json([
    'message' => '你好',
])
    ->onDisk('s3')
    ->inDirectory('exports')
    ->named('message')
    ->store();
```

## 主要功能

- 嚴格內容檢查、大小限制、MIME allowlist 與安全路徑處理
- 串流儲存、讀取、下載及受限制的 ZIP 建立流程
- 預設驗證 TLS 且具備 SSRF 防護的網址匯入
- 使用 Intervention Image 4 的 best-effort 圖片縮放
- 支援檔名碰撞策略，並在 Overwrite 失敗時還原
- 保留順序的批次查詢、維持一致性的批次刪除與 storage 稽核
- 支援自訂 StoredFile Model 與資料表
- 完整英文與繁體中文文件

`Overwrite` 會在取代 object 前，先將完整舊檔案備份到 PHP 伺服器的本機暫存硬碟，
因此會占用額外硬碟空間與 I/O，整體效能也低於一般儲存。除非必須維持相同 storage
path，否則建議使用預設的 `Unique`。

## 完整文件

完整教學、設定參考、安全性說明、使用範例及常見問題請閱讀：

**[開啟 FileMagic 繁體中文文件](https://mattmy.github.io/laravel-file-magic-docs/zh-TW/)**

- [開始使用](https://mattmy.github.io/laravel-file-magic-docs/zh-TW/guide/getting-started)
- [儲存檔案](https://mattmy.github.io/laravel-file-magic-docs/zh-TW/guide/storing-files)
- [遠端檔案](https://mattmy.github.io/laravel-file-magic-docs/zh-TW/guide/remote-files)
- [文件與圖片](https://mattmy.github.io/laravel-file-magic-docs/zh-TW/guide/documents-and-images)
- [查詢檔案](https://mattmy.github.io/laravel-file-magic-docs/zh-TW/guide/querying-files)
- [ZIP 與刪除](https://mattmy.github.io/laravel-file-magic-docs/zh-TW/guide/zip-and-deletion)
- [一致性稽核](https://mattmy.github.io/laravel-file-magic-docs/zh-TW/guide/maintenance)
- [Model 與例外](https://mattmy.github.io/laravel-file-magic-docs/zh-TW/guide/models-and-exceptions)
- [API 參考與常見問題](https://mattmy.github.io/laravel-file-magic-docs/zh-TW/guide/reference)

## 一致性稽核

`php artisan file-magic:audit` 會檢查每筆 database record 是否仍有對應的
`disk + path` object，預設完全唯讀。每筆 record 都會呼叫一次 filesystem
`exists()`；S3 等遠端 disk 因此可能增加執行時間與 request 費用。`--chunk` 只限制
database memory，不會減少 storage requests。此指令不會列舉 storage，也不會刪除
任何實體檔案。

清理時必須使用 `--delete-missing-records` 並確認；非互動環境還要使用 `--force`。
清理會在每個 chunk 執行一次 database bulk delete，不觸發逐筆 Eloquent model
events，也不是包住整個指令的單一 transaction；後段失敗時，前面 chunks 可能已經
刪除。Storage 或網路錯誤只代表狀態不明，絕不會被當成 object 缺失的證據。啟用清理
或排程前，請先閱讀[一致性稽核文件](https://mattmy.github.io/laravel-file-magic-docs/zh-TW/guide/maintenance)。

## 安全性

應用程式必須對每個檔案操作進行 authorization，並在 HTTP 邊界保留 Laravel
request validation。原始檔名、client MIME、遠端內容及儲存完成的檔案都應視為
不可信任資料。接受不可信任檔案或網址前，請先閱讀
[安全性說明](https://mattmy.github.io/laravel-file-magic-docs/zh-TW/guide/reference#安全性注意事項)。

安全性問題請依照 [SECURITY.md](SECURITY.md) 私下回報。

## 一致性稽核

`php artisan file-magic:audit` 會檢查每筆 database record 是否仍有對應的
`disk + path` object，預設完全唯讀。每筆 record 都會呼叫一次 filesystem
`exists()`；S3 等遠端 disk 因此可能增加執行時間與 request 費用。`--chunk` 只限制
database memory，不會減少 storage requests。

清理時必須使用 `--delete-missing-records` 並確認；非互動環境還要使用 `--force`。
清理會在每個 chunk 執行一次 database bulk delete，不觸發逐筆 Eloquent model
events，也不是包住整個指令的單一 transaction；後段失敗時，前面 chunks 可能已經
刪除。Storage 或網路錯誤只代表狀態不明，絕不會被當成 object 缺失的證據。啟用清理
或排程前，請先閱讀[一致性稽核文件](https://mattmy.github.io/laravel-file-magic-docs/zh-TW/guide/maintenance)。

## 授權

FileMagic 是使用 [MIT License](LICENSE) 發佈的開源軟體。

提交 Pull Request 前請閱讀 [CONTRIBUTING.md](CONTRIBUTING.md)。發布版本遵守
[Semantic Versioning](https://semver.org/)，變更內容記錄於
[CHANGELOG.md](CHANGELOG.md)。
