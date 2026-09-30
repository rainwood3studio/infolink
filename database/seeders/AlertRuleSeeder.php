<?php

namespace Database\Seeders;

use App\Domain\Alerts\Rules\BriefMissingRule;
use App\Domain\Alerts\Rules\CashLowRule;
use App\Domain\Alerts\Rules\ClosingRiskRule;
use App\Domain\Alerts\Rules\DealStaleRule;
use App\Domain\Alerts\Rules\DeliveryBacklogGrowingRule;
use App\Domain\Alerts\Rules\ReceivableDueRule;
use App\Domain\Alerts\Rules\ReceivableOverdueRule;
use App\Domain\Alerts\Rules\ServerDiskRule;
use App\Domain\Alerts\Rules\SyncFailedRule;
use App\Domain\Alerts\Rules\VatReserveRule;
use App\Enums\Category;
use App\Enums\InsightSeverity;
use App\Models\AlertRule;
use Illuminate\Database\Seeder;

/**
 * The initial alert rules from docs/04-metrics-and-dashboard.md (警示規則).
 *
 * Idempotent by `key` and create-only: an existing rule is never touched, so thresholds, severity, templates and
 * `is_active` edited in the admin survive re-seeding (template improvements here therefore only reach new installs).
 */
class AlertRuleSeeder extends Seeder
{
    /**
     * Seed the alert rules.
     */
    public function run(): void
    {
        foreach (self::rules() as $rule) {
            AlertRule::query()->firstOrCreate(['key' => $rule['key']], [
                'metric_key' => null,
                'query_class' => null,
                'operator' => null,
                'threshold' => null,
                'critical_threshold' => null,
                'params' => null,
                'is_active' => true,
                ...$rule,
            ]);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function rules(): array
    {
        return [
            [
                'key' => 'cash-low',
                'name' => '現金低點',
                'description' => '未來 90 天內（目前餘額與每月月底推估）的最低現金低於門檻。fingerprint 含低點月份，低點移到別的月份會開新的注意事項。',
                'query_class' => CashLowRule::class,
                'operator' => '<',
                'threshold' => 500_000,
                'severity' => InsightSeverity::Critical,
                'category' => Category::Finance,
                'title_template' => '90 天內現金低點 {amount} 萬（{month}）',
                'fingerprint_template' => 'cash-low:{month}',
            ],
            [
                'key' => 'cash-runway',
                'name' => '現金可撐月數',
                'description' => '`cash.runway_months`（現金餘額 ÷ 月成本）最新值 < 3 為注意、< 2 為嚴重。',
                'metric_key' => 'cash.runway_months',
                'operator' => '<',
                'threshold' => 3,
                'critical_threshold' => 2,
                'severity' => InsightSeverity::Warning,
                'category' => Category::Finance,
                'title_template' => '現金只夠撐 {value} 個月',
                'fingerprint_template' => 'cash-runway',
                'params' => ['suggestion' => '檢視現金推估，催收應收、延後非必要支出，並確認下一筆大額收款的時間。'],
            ],
            [
                'key' => 'receivable-due',
                'name' => '應收即將到期',
                'description' => '高確定性、尚未開發票（planned）的應收在門檻天數內到期（含經常性維護費；params include_recurring=false 可排除）。',
                'query_class' => ReceivableDueRule::class,
                'operator' => '<=',
                'threshold' => 7,
                'severity' => InsightSeverity::Info,
                'category' => Category::Finance,
                'title_template' => '{label} {amount} 萬將於 {date} 到期，尚未開發票',
                'fingerprint_template' => 'receivable-due:{id}',
            ],
            [
                'key' => 'receivable-overdue',
                'name' => '應收逾期',
                'description' => '未收應收超過預計收款日「門檻」天為注意，超過「嚴重門檻」天為嚴重。任何確定性，含經常性維護費（params include_recurring=false 可排除）。',
                'query_class' => ReceivableOverdueRule::class,
                'operator' => '>',
                'threshold' => 3,
                'critical_threshold' => 30,
                'severity' => InsightSeverity::Warning,
                'category' => Category::Finance,
                'title_template' => '{label} {amount} 萬已逾期 {days} 天',
                'fingerprint_template' => 'receivable-overdue:{id}',
                // Money not arriving is urgent enough to reach the phone even at warning level.
                'notify_channels' => ['database', 'line'],
            ],
            [
                'key' => 'vat-reserve',
                'name' => '營業稅預留',
                'description' => '奇數月 15 日申報前 window_days 天內，現金餘額扣掉營業稅預留後低於月成本。預留優先用本期的 tax.vat_reserve 指標，否則以本期已開發票銷項稅額 − 月成本已含的營業稅 × 2 推算。',
                'query_class' => VatReserveRule::class,
                'severity' => InsightSeverity::Warning,
                'category' => Category::Finance,
                'title_template' => '營業稅（{deadline} 前申報）預留 {reserve} 萬後餘額 {remaining} 萬，低於月成本',
                'fingerprint_template' => 'vat-reserve:{period}',
                'params' => ['window_days' => 14],
            ],
            [
                'key' => 'delivery-backlog-growing',
                'name' => 'backlog 成長',
                'description' => '`delivery.net_flow`（每週新增 − 結案）連續 weeks 個已結束的週 > 門檻。',
                'query_class' => DeliveryBacklogGrowingRule::class,
                'operator' => '>',
                'threshold' => 0,
                'severity' => InsightSeverity::Warning,
                'category' => Category::Delivery,
                'title_template' => '連續 {weeks} 週新增議題多於結案（未結 {total}）',
                'fingerprint_template' => 'delivery-backlog-growing',
                'params' => ['weeks' => 3],
            ],
            [
                'key' => 'delivery-offflow',
                'name' => '脫離驗收流程',
                'description' => '各專案 `delivery.verifying.others`（驗證中但指派給文豪以外的人）> 門檻，或 7 天內增加超過 increase_threshold。',
                'metric_key' => 'delivery.verifying.others',
                'operator' => '>',
                'threshold' => 20,
                'severity' => InsightSeverity::Warning,
                'category' => Category::Delivery,
                'title_template' => '{project_name}：{value} 筆驗證中未走驗收流程{change_text}',
                'fingerprint_template' => 'delivery-offflow:{project}',
                'params' => [
                    'dimension_prefix' => 'project:',
                    'increase_threshold' => 10,
                    'increase_days' => 7,
                    'suggestion' => '請驗證中的負責人把議題轉給文豪驗收，或確認是否其實已完成可直接結案。',
                ],
            ],
            [
                'key' => 'delivery-stalled',
                'name' => '停滯惡化',
                'description' => '各專案 `delivery.stalled_90d`（90 天沒有更新的未結議題）7 天內增加超過 increase_threshold。',
                'metric_key' => 'delivery.stalled_90d',
                'operator' => '>',
                'severity' => InsightSeverity::Warning,
                'category' => Category::Delivery,
                'title_template' => '{project_name}：停滯 90 天的議題增為 {value} 筆{change_text}',
                'fingerprint_template' => 'delivery-stalled:{project}',
                'params' => [
                    'dimension_prefix' => 'project:',
                    'increase_threshold' => 10,
                    'increase_days' => 7,
                    'suggestion' => '逐筆確認停滯議題：還要做的排入時程，不做的關閉，避免存量繼續膨脹。',
                ],
            ],
            [
                'key' => 'closing-risk',
                'name' => '結案專案風險',
                'description' => '結案中專案距目標結案日 < days 天（含已過期）且連結的 Redmine 專案未結議題 > 門檻。沒有目標日的結案中專案另外列在一則 closing-target-missing 資訊。',
                'query_class' => ClosingRiskRule::class,
                'operator' => '>',
                'threshold' => 10,
                'severity' => InsightSeverity::Critical,
                'category' => Category::Company,
                'title_template' => '{project_name}：{days_text}，仍有 {open} 筆未結議題',
                'fingerprint_template' => 'closing-risk:{project}',
                'params' => ['days' => 30],
            ],
            [
                'key' => 'deal-stale',
                'name' => '業務斷層',
                'description' => '進行中的業務機會沒有下一步（next_action_on 為空，以最後更新日起算）或下一步已逾期，持續門檻天數以上；每筆機會一則 deal-stale:<id>。設定未來的下一步或結案後自動解除。',
                'query_class' => DealStaleRule::class,
                'operator' => '>=',
                'threshold' => 7,
                'severity' => InsightSeverity::Info,
                'category' => Category::Sales,
                'title_template' => '{label}{reason}',
                'fingerprint_template' => 'deal-stale:{id}',
            ],
            [
                'key' => 'sync-failed',
                'name' => '資料過期',
                'description' => '任一同步工作在上次成功後有失敗，且已超過門檻小時沒有成功（單晚離開 VPN 不會觸發）。',
                'query_class' => SyncFailedRule::class,
                'operator' => '>=',
                'threshold' => 24,
                'severity' => InsightSeverity::Warning,
                'category' => Category::Company,
                'title_template' => '{job_label} 已 {hours} 小時沒有成功同步（失敗 {failures} 次）',
                'fingerprint_template' => 'sync-failed:{job}',
            ],
            [
                'key' => 'brief-missing',
                'name' => '每日簡報未產生',
                'description' => '工作日 after 之後仍沒有當日的 daily_brief 報告；報告產生後自動結案。國定假日不認得。',
                'query_class' => BriefMissingRule::class,
                'severity' => InsightSeverity::Warning,
                'category' => Category::Company,
                'title_template' => '{date} 每日簡報到 {after} 仍未產生',
                'fingerprint_template' => 'brief-missing:{date}',
                'params' => ['after' => '10:00'],
            ],
            [
                'key' => 'server-disk',
                'name' => '伺服器硬碟空間',
                'description' => 'SSM 管理的機器任一分割區使用率達門檻 %（達 critical 門檻升為嚴重），或依近 7 天增長推估 days 天內會滿；每個分割區一則，清出空間後自動結案。',
                'query_class' => ServerDiskRule::class,
                'operator' => '>=',
                'threshold' => 80,
                'critical_threshold' => 90,
                'severity' => InsightSeverity::Warning,
                'category' => Category::Company,
                'title_template' => '{name} {mount} 已用 {percent}%（剩 {free} GB）',
                'fingerprint_template' => 'disk-usage:{instance_id}:{mount}',
                'params' => ['days' => 14],
            ],
        ];
    }
}
