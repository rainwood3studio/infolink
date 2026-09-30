# 06 伺服器維運：硬碟空間監控與 scm 備份

> 建立：2026-09-30

## 1. 硬碟空間儀表板

**頁面**：後台「維運 → 硬碟空間」（`/admin/server-disks`）

### 資料來源

- 對 AWS Systems Manager（SSM）管理的每台機器執行**唯讀**指令（SSM Run Command，註記 `infolink: disk usage (read-only)`）：
  - Linux：`df -PT -B1`，排除 tmpfs、overlay、squashfs、vfat 等虛擬或開機用的檔案系統
  - Windows：`Get-CimInstance Win32_LogicalDisk`
- 收集的帳號由 `.env` 的 `SSM_TARGETS` 設定，格式為 `profile@region` 並以逗號分隔。預設 `default@ap-northeast-1,EC@ap-northeast-1`（`INFOLINK` 帳號目前沒有 SSM 機器）。
- AWS 帳密：`~/.aws` 以唯讀方式掛進 PHP 容器的 `/aws`（`docker-compose.yml`，位置可用 `AWS_CONFIG_PATH` 覆寫）。
- 排程：每小時 :15 執行 `php artisan infolink:collect-server-disks`。頁面上的「立即更新」可手動收集，約 7 秒。
- 每次收集都寫一筆 `sync_runs`（job `server_disks`），因此「資料過期」規則也涵蓋這個工作。

### 資料表

| 表 | 內容 |
| --- | --- |
| `servers` | 每台 SSM 受管機器：instance id、帳號、Name tag、平台、SSM 狀態、`last_collected_at`、`last_error`。SSM 不再列出的機器會被標成 `is_active = false` |
| `server_disk_samples` | 每次收集、每個分割區一筆：大小、已用、可用、使用率。保留 180 天 |

使用率的算法與 df 相同：`used / (used + available)`，保留區塊算作已用。

### 頁面內容

- **卡片**：機器數、最滿的分割區、≥ 80% 的數量（其中 ≥ 90% 另外標出）、無法取得資料的機器
- **趨勢圖**：最滿的 6 個分割區近 30 天的每日最高使用率
- **表格**：各分割區的使用率（80% 起黃色、90% 起紅色）、剩餘空間、近 7 天增長，以及依線性增長推估的「預計幾天滿」。至少要有 24 小時的資料才會計算增長。

### 警示規則 `server-disk`

- 任一分割區使用率 ≥ 80% 時發 warning，≥ 90% 升為 critical；依近 7 天增長推估 14 天內會滿也會觸發。
- 每個分割區一則注意事項（`disk-usage:<instance>:<mount>`），清出空間後自動結案。
- 門檻、嚴重度、推估天數（`params.days`）都可以在後台「警示規則」修改。

### 程式位置

| 檔案 | 用途 |
| --- | --- |
| `app/Domain/Infra/SsmGateway.php` | AWS SDK 包裝：列出機器、送出 df 指令並等待結果 |
| `app/Domain/Infra/DiskUsageParser.php` | 解析 df 與 Windows 的輸出 |
| `app/Domain/Infra/ServerDiskCollector.php` | 收集流程，並寫入 SyncRun |
| `app/Domain/Infra/ServerDiskReport.php` | 讀取模型：目前狀況、增長、趨勢 |
| `app/Domain/Alerts/Rules/ServerDiskRule.php` | 警示規則 |
| `app/Filament/Pages/ServerDisks.php` | 頁面，以及 `ServerDiskStatsWidget` 和 `ServerDiskTrendChart` 兩個 widget |

## 2. 2026-09-30 硬碟清理紀錄

### UAT | 開發 | 特斯拉APIs（EC 帳號 `i-00c288724a55c877b`）：88% → 49%

| 動作 | 釋出 |
| --- | --- |
| 清除 `~/.cache/yarn/v6`（yarn 下載快取） | 9.8G |
| 移除 10 個停用的舊 snap 版本 | 約 1G |

未處理：journal（1.8G，清理指令回報 freed 0B）、10 個沒在用的 docker volume（約 134M，可能含資料，保留）。

### scm（default 帳號 `i-0ee34c11107b06f27`）：83% → 69%

| 動作 | 釋出 |
| --- | --- |
| 主機 journal 由 1.8G 降到 208M；scm-app1 容器內 journal 由 4.1G 降到 209M | 約 5.5G |
| scm-app1 的 nginx `access.log`（1.7G）與 `laravel.log`（830M）先壓縮封存再清空 | 約 2.4G |
| mysql8 的 docker 日誌（1.6G）先壓縮封存再清空 | 約 1.5G |
| 刪除 `/var/log/nginx/.access.log.swp`（2024-10 留下的 vim 暫存檔） | 3.2G |
| 移除停用兩年的 `scm-app`、`pma` 容器與 pma image | 約 1.3G |

- 封存檔都在 `/home/ubuntu/log-archive/`，被移除容器的設定也另存成 JSON 放在這裡。
- **沒有動**：`storage/app/export`（11G）、`storage/app/import`（3.8G）、`~/dbbackup`、MySQL binlog。
- ⚠️ scm-app1 容器裡有一個 `vi .env` 從 2025-05-05 開到現在，可能還有沒存檔的修改。請負責人確認後再結束這個行程。
- ⚠️ scm 的日誌（nginx、laravel.log、容器內 journal、docker 日誌）**都沒有設定輪替**，會慢慢長回來。之後應補 logrotate，並設定 docker 的 log `max-size`。

## 3. scm 資料庫每日備份

**背景**：scm 的 MySQL（`mysql8` 容器、`scm` 資料庫、約 12.3G、全部 InnoDB）在此之前**沒有任何備份**。唯一能還原的是保留 30 天的 binlog（約 27G）。

### 設定

| 項目 | 內容 |
| --- | --- |
| 腳本 | `/usr/local/bin/scm-db-backup.sh`（repo 內的原始檔：`scripts/scm/scm-db-backup.sh`） |
| 排程 | `/etc/cron.d/scm-db-backup`：每天 19:00 UTC，即台灣時間 03:00 |
| 本機 | `/home/ubuntu/dbbackup/daily/`，保留最新 5 份 |
| S3 | `s3://scm-db-backup-625240399201/scm/`，生命週期規則 5 天後刪除，禁止公開存取，預設加密 |
| 權限 | `scm-ssm-role` 的 inline policy `scm-db-backup-put`：只能上傳到這個 bucket，不能讀、不能刪 |
| 紀錄 | `/var/log/scm-db-backup.log`，每月輪替，保留 6 份 |

### 腳本流程

1. 取得檔案鎖，避免同時跑兩個備份；硬碟剩不到 10G 就中止。
2. 在容器內執行 `mysqldump --single-transaction --routines --triggers --events`。InnoDB 在備份時不鎖表，密碼從容器的環境變數讀取，不會出現在主機上。
3. 以 gzip 壓縮，並確認最後一行是 `-- Dump completed`，不完整就丟棄。
4. 上傳 S3。上傳失敗時保留本機檔案，並以非 0 結束。
5. 刪除本機超過 5 份的舊檔（只清 `daily/` 資料夾，不會動舊的 `~/dbbackup/*.sql.gz`）。

`scm-db-backup.sh --test` 只備份結構、不含資料，可以用來快速檢查整條流程。2026-09-30 已用這個模式測試成功。

### 還原

```bash
# 在 scm 上，還原到另一個暫時的 MySQL 容器做驗證（不要直接還原到正式的 mysql8）
zcat /home/ubuntu/dbbackup/daily/scm-YYYYMMDD-HHMM.sql.gz | docker exec -i <temp-mysql> mysql -uroot -p<password>
# 從 S3 下載（需要有讀取權限的帳號，scm 本機的 role 只能上傳）
aws s3 cp s3://scm-db-backup-625240399201/scm/scm-YYYYMMDD-HHMM.sql.gz .
```

## 4. 待辦

- [ ] 確認第一次完整備份（2026-10-01 03:00）成功：看紀錄檔與 S3，記下耗時與檔案大小
- [ ] 完整備份成功後清理 binlog：`PURGE BINARY LOGS BEFORE NOW() - INTERVAL 7 DAY`，並用 `SET PERSIST binlog_expire_logs_seconds = 604800` 把保留期改成 7 天，約可釋出 20G
- [ ] 做一次還原測試，還原到暫時的容器
- [ ] 在 infolink 加警示：S3 上最新的備份超過 26 小時就通知
- [ ] scm 補上日誌輪替：logrotate（nginx、laravel.log）與 docker log `max-size`
- [ ] 請負責人處理 scm-app1 裡 2025-05 開著的 `vi .env`
- [ ] 請 SCM 負責人確認 `storage/app/export`、`storage/app/import`（約 15G）能不能清
