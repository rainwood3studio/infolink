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
- 日誌輪替已於 2026-09-30 補上，見下方「scm 日誌輪替」。
- ⚠️ **scm-app1 不可重建**：除了 `/home/dev` 之外，程式碼、`.env`、`storage/app`（export、import、public 上傳檔）、nginx 設定都只存在容器內部。`docker restart` 沒問題，但 `docker rm` 或用新設定重建會讓這些檔案全部消失。

### scm 日誌輪替（不重啟容器）

scm-app1 容器裡有 logrotate 設定檔，但沒有安裝 logrotate 程式，所以日誌一直沒有輪替。改由主機負責：

| 設定 | 內容 |
| --- | --- |
| `/usr/local/bin/scm-app-logrotate.sh`（repo 原始檔：`scripts/scm/scm-app-logrotate.sh`） | 透過容器的 overlay merged 目錄，從主機輪替容器內的 nginx `access.log`、`error.log`、`laravel.log`（每天或超過 200M，保留 14 份）與 `redis-server.log`（每週或超過 200M，保留 4 份）。一律使用 copytruncate，服務不用重啟 |
| `/etc/cron.d/scm-app-logrotate` | 每小時第 5 分執行，超過大小上限也能及時輪替。紀錄在 `/var/log/scm-app-logrotate.log` |
| `/etc/logrotate.d/docker-containers` | 主機上所有 docker json 日誌：每天或超過 100M 輪替，保留 7 份，使用 copytruncate。不改 docker 的 `max-size`，因為那需要重建容器 |
| `/etc/systemd/journald.conf.d/size.conf` | 主機 journal 上限 500M（`SystemMaxUse`） |

如果 scm-app1 將來被重建，腳本會自動抓新的目錄，不需要修改。

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

- [x] 第一次完整備份（2026-09-30 09:51 手動執行）：7 分 43 秒，1.5G，已上傳 S3
- [x] 清理 binlog（2026-09-30）：27 個 26.8G 降到 8 個 7.7G；已用 `SET PERSIST` 把 `binlog_expire_logs_seconds` 改成 604800（7 天）。scm 從 71% 降到 51%
- [ ] 做一次還原測試，還原到暫時的容器
- [ ] 在 infolink 加警示：S3 上最新的備份超過 26 小時就通知
- [x] scm 日誌輪替（2026-09-30）：由主機跑 logrotate，容器沒有重啟，也沒有重建
- [ ] 把 scm-app1 容器內的檔案納入備份：`storage/app/public`（上傳檔）與 `.env`。目前只有 MySQL 有備份
- [ ] 長期：把 scm-app1 的 `storage` 改成掛載到主機上。需要重建一次容器，要找維護時段和 SCM 負責人一起做
- [ ] 請負責人處理 scm-app1 裡 2025-05 開著的 `vi .env`
- [ ] 請 SCM 負責人確認 `storage/app/export`、`storage/app/import`（約 15G）能不能清
