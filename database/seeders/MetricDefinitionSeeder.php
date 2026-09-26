<?php

namespace Database\Seeders;

use App\Enums\Category;
use App\Enums\MetricDirection;
use App\Enums\MetricUnit;
use App\Enums\PeriodType;
use App\Models\MetricDefinition;
use Illuminate\Database\Seeder;

/**
 * The metric catalogue from docs/04-metrics-and-dashboard.md. Idempotent: re-running updates definitions by key.
 *
 * `description` is read by Claude (list_metric_definitions), so it states exactly how the number is derived and its caveats.
 */
class MetricDefinitionSeeder extends Seeder
{
    /**
     * Seed the metric definitions.
     */
    public function run(): void
    {
        foreach (self::definitions() as $index => $definition) {
            MetricDefinition::query()->updateOrCreate(
                ['key' => $definition['key']],
                [
                    ...$definition,
                    'target' => $definition['target'] ?? null,
                    'warn_threshold' => $definition['warn_threshold'] ?? null,
                    'critical_threshold' => $definition['critical_threshold'] ?? null,
                    'calculator' => null,
                    'is_pinned' => $definition['is_pinned'] ?? false,
                    'sort' => ($index + 1) * 10,
                ],
            );
        }
    }

    /**
     * @return list<array{key: string, name: string, category: Category, unit: MetricUnit, period_type: PeriodType, better: MetricDirection, description: string, is_pinned?: bool, target?: int|float, warn_threshold?: int|float, critical_threshold?: int|float}>
     */
    public static function definitions(): array
    {
        return [
            // 財務 finance
            [
                'key' => 'cash.balance',
                'name' => '現金餘額',
                'category' => Category::Finance,
                'unit' => MetricUnit::Twd,
                'period_type' => PeriodType::Snapshot,
                'better' => MetricDirection::Up,
                'is_pinned' => true,
                'description' => '觀測日當天最新一筆銀行交易的銀行列印餘額（bank_transactions.balance），新台幣元。period_start 為觀測日。參考值：1,072,776（2026-09-22）。',
            ],
            [
                'key' => 'cash.runway_months',
                'name' => '現金可撐月數',
                'category' => Category::Finance,
                'unit' => MetricUnit::Months,
                'period_type' => PeriodType::Snapshot,
                'better' => MetricDirection::Up,
                'is_pinned' => true,
                'warn_threshold' => 3,
                'critical_threshold' => 2,
                'description' => '現金餘額 ÷ 常態月成本（最新 cost_baselines.monthly_cost）。不計任何未收應收，是「完全沒有收入時還能撐幾個月」的保守值。< 3 為警告，< 2 為嚴重。參考值：4.7 個月。',
            ],
            [
                'key' => 'cash.monthly_cost',
                'name' => '常態月成本',
                'category' => Category::Finance,
                'unit' => MetricUnit::Twd,
                'period_type' => PeriodType::Month,
                'better' => MetricDirection::Down,
                'description' => '當月（period_start 為該月 1 日）所有帳戶 bank_transactions.withdrawal 加總，排除 is_one_off = true 的一次性支出（例如中秋獎金、禮盒）與代墊款（category = reimbursement）。現金基礎：以實際扣款日歸月，遲繳（例如 7 月勞健保 8/03 補繳）或雙月營業稅會讓單月偏高或偏低；要看常態水準請用 cost_baselines（目前 229,666）。只記錄有逐筆交易的月份；資料尚未涵蓋到月底的當月會在 notes 標示 partial month，數字會再變。',
            ],
            [
                'key' => 'cash.forecast_min_90d',
                'name' => '未來 90 天推估最低餘額',
                'category' => Category::Finance,
                'unit' => MetricUnit::Twd,
                'period_type' => PeriodType::Snapshot,
                'better' => MetricDirection::Up,
                'is_pinned' => true,
                'critical_threshold' => 500000,
                'description' => '最新一版 cash_forecasts 中，基準日起 90 天內的最低推估餘額。流入只計高確定性（confidence = high）應收，流出用常態月成本。< 500,000 為嚴重。',
            ],
            [
                'key' => 'cash.forecast_year_end',
                'name' => '推估年底餘額',
                'category' => Category::Finance,
                'unit' => MetricUnit::Twd,
                'period_type' => PeriodType::Snapshot,
                'better' => MetricDirection::Up,
                'description' => '最新一版 cash_forecasts 的年底（12 月底）推估餘額，假設與該版推估相同（預設只計高確定性應收）。比較不同觀測日的值可看推估的變化。參考值：2,128,778。',
            ],
            [
                'key' => 'cash.forecast_error',
                'name' => '上月推估月底餘額誤差',
                'category' => Category::Finance,
                'unit' => MetricUnit::Twd,
                'period_type' => PeriodType::Month,
                'better' => MetricDirection::None,
                'description' => '推估準確度：該月（M，period_start 為 M 的 1 日）實際月底餘額 − 上個月推估的 M 月底餘額，新台幣元。推估取 as_of 落在 M−1 月內的最新一版 cash_forecasts（同日多版取最後建立者），讀其 rows 中 month = M 的 balance；實際為各帳戶在 M 月底當天或之前最後一筆交易 balance 的加總。只在 M 已完整匯入（存在 M 月底之後的交易）且該推估有 M 月的列時才記錄。正數 = 實際比推估好（多收或少花），負數 = 推估太樂觀。notes 記錄所用推估的 id、as_of、推估值與實際值。12 月的推估預設只算到年底，因此 1 月通常沒有值。',
            ],
            [
                'key' => 'cash.forecast_error_ratio',
                'name' => '上月推估誤差率',
                'category' => Category::Finance,
                'unit' => MetricUnit::Ratio,
                'period_type' => PeriodType::Month,
                'better' => MetricDirection::None,
                'description' => 'cash.forecast_error ÷ |推估的 M 月底餘額|，以小數儲存（0.05 = 實際比推估高 5%，−0.1 = 低 10%），四捨五入到小數 4 位。配對規則與 cash.forecast_error 相同；推估值為 0 時不記錄。看絕對值大小判斷推估是否可靠。',
            ],
            [
                'key' => 'ar.outstanding_taxed',
                'name' => '未收應收（含稅）',
                'category' => Category::Finance,
                'unit' => MetricUnit::Twd,
                'period_type' => PeriodType::Snapshot,
                'better' => MetricDirection::None,
                'is_pinned' => true,
                'description' => 'receivables 中狀態為 planned／invoiced（含逾期）的含稅金額加總，只計高確定性（confidence = high）；低確定性另見 ar.low_confidence_taxed。數字大不一定是壞事，要看是否逾期。參考值：1,365,000。',
            ],
            [
                'key' => 'ar.overdue_taxed',
                'name' => '逾期應收（含稅）',
                'category' => Category::Finance,
                'unit' => MetricUnit::Twd,
                'period_type' => PeriodType::Snapshot,
                'better' => MetricDirection::Down,
                'is_pinned' => true,
                'description' => '已過 expected_on 仍未收（狀態 planned／invoiced）的應收含稅金額加總。逾期是由日期即時算出，不依賴人工把狀態改成 overdue。',
            ],
            [
                'key' => 'ar.low_confidence_taxed',
                'name' => '低確定性應收',
                'category' => Category::Finance,
                'unit' => MetricUnit::Twd,
                'period_type' => PeriodType::Snapshot,
                'better' => MetricDirection::None,
                'description' => '未收且 confidence = low 的應收含稅金額加總。這些款項不計入現金推估的基本情境。參考值：577,500。',
            ],
            [
                'key' => 'revenue.received',
                'name' => '當月入帳收入',
                'category' => Category::Finance,
                'unit' => MetricUnit::Twd,
                'period_type' => PeriodType::Month,
                'better' => MetricDirection::Up,
                'description' => '當月（period_start 為該月 1 日）所有帳戶 bank_transactions 中 category = revenue 的 deposit 加總（含稅，即實際入帳金額，匯費已被扣掉）。是現金基礎，不是發票或權責基礎的營收。只記錄有逐筆交易的月份；未涵蓋到月底的當月在 notes 標示 partial month。',
            ],
            [
                'key' => 'revenue.recurring_monthly',
                'name' => '每月經常性收入',
                'category' => Category::Finance,
                'unit' => MetricUnit::Twd,
                'period_type' => PeriodType::Month,
                'better' => MetricDirection::Up,
                'description' => 'receivables 中 is_recurring = true、expected_on 落在該月、狀態不是 cancelled（已收或未收都算）的 amount_taxed 加總（含稅）。代表每月可預期的收入底（例如墊腳石維運 100,000＋我識 APP 維護 20,000）。該月沒有任何經常性應收紀錄時不寫值（不是 0）——例如 2026-07、08 的維運費沒有建成應收。參考值：約 120,000。',
            ],
            [
                'key' => 'cost.personnel_ratio',
                'name' => '人事占支出比',
                'category' => Category::Finance,
                'unit' => MetricUnit::Ratio,
                'period_type' => PeriodType::Month,
                'better' => MetricDirection::None,
                'description' => '當月 category ∈ {salary, insurance} 的常態支出（排除 is_one_off）÷ 同月 cash.monthly_cost（常態支出，排除一次性與代墊）。勞退（勞工退休金）與勞保、健保都歸在 insurance 類別裡。以 0–1 小數儲存（0.85 = 85%），四捨五入到小數 4 位。現金基礎，單月會受遲繳、營業稅繳款月份影響（例如 8 月沒有營業稅與 AWS 支出，比例接近 1）；看趨勢時以多個月平均為準。參考值：0.85。',
            ],
            [
                'key' => 'tax.vat_reserve',
                'name' => '營業稅應預留',
                'category' => Category::Finance,
                'unit' => MetricUnit::Twd,
                'period_type' => PeriodType::Snapshot,
                'better' => MetricDirection::None,
                'description' => '本期（雙月）已開發票的銷項稅額，減去已攤入常態月成本的部分，即下次申報（奇數月 15 日前）需要另外準備的現金。參考值：11 月約 80,000。',
            ],
            [
                'key' => 'company.net_cashflow_ytd',
                'name' => '今年累計淨現金流',
                'category' => Category::Finance,
                'unit' => MetricUnit::Twd,
                'period_type' => PeriodType::Month,
                'better' => MetricDirection::Up,
                'description' => '當年 1 月 1 日到該月底，所有帳戶 deposit − withdrawal 的累計（所有類別都算，包含代墊與一次性支出，所以等於「月底餘額 − 去年底餘額」）。是年度目標「開始獲利」的代理指標（現金基礎，非會計損益）。period_start 為該月 1 日。計算方式為上月值＋本月淨額：1 月直接等於 1 月淨額；其他月份需要上月已有值，否則不寫。逐筆交易開始前的月份（2026-01～06）來自 vault《帳戶流水分析》月彙總（source = vault），並已核對 6 月底餘額與逐筆交易的期初餘額一致。',
            ],

            // 業務 sales
            [
                'key' => 'sales.pipeline_weighted',
                'name' => '加權業務機會金額',
                'category' => Category::Sales,
                'unit' => MetricUnit::Twd,
                'period_type' => PeriodType::Snapshot,
                'better' => MetricDirection::Up,
                'is_pinned' => true,
                'description' => 'Σ(deals.amount_untaxed × probability)，未稅，不含 stage 為 won／lost 的機會。',
            ],
            [
                'key' => 'sales.deals_open',
                'name' => '進行中業務機會數',
                'category' => Category::Sales,
                'unit' => MetricUnit::Count,
                'period_type' => PeriodType::Snapshot,
                'better' => MetricDirection::Up,
                'description' => 'stage 不是 won／lost 的業務機會筆數。dimension 空字串為總數，`stage:<stage>` 為依階段拆分。',
            ],
            [
                'key' => 'sales.deals_no_next_action',
                'name' => '沒有下一步的機會',
                'category' => Category::Sales,
                'unit' => MetricUnit::Count,
                'period_type' => PeriodType::Snapshot,
                'better' => MetricDirection::Down,
                'description' => '進行中的業務機會中，next_action_on 為空或已過期的筆數。應該維持 0。',
            ],
            [
                'key' => 'sales.won_amount',
                'name' => '當月成交金額',
                'category' => Category::Sales,
                'unit' => MetricUnit::Twd,
                'period_type' => PeriodType::Month,
                'better' => MetricDirection::Up,
                'description' => '當月轉為 won 的業務機會 amount_untaxed 加總（未稅）。是簽約金額，不是入帳金額。',
            ],
            [
                'key' => 'saas.mrr',
                'name' => 'SaaS 月經常性收入',
                'category' => Category::Sales,
                'unit' => MetricUnit::Twd,
                'period_type' => PeriodType::Month,
                'better' => MetricDirection::Up,
                'description' => 'SaaS POS 的月經常性收入（未稅）。SaaS POS 上線後才啟用，目前由手動輸入；沒有值代表尚未上線，不是 0。',
            ],

            // 交付 delivery（Redmine）
            [
                'key' => 'delivery.open',
                'name' => '未結案存量',
                'category' => Category::Delivery,
                'unit' => MetricUnit::Count,
                'period_type' => PeriodType::Day,
                'better' => MetricDirection::Down,
                'is_pinned' => true,
                'description' => '觀測日當天 Redmine 未結案（is_closed = false）議題數。dimension 空字串為全公司，`project:<identifier>` 為依專案拆分。參考值（W36）：601。',
            ],
            [
                'key' => 'delivery.created',
                'name' => '新增議題',
                'category' => Category::Delivery,
                'unit' => MetricUnit::Count,
                'period_type' => PeriodType::Week,
                'better' => MetricDirection::None,
                'description' => '該週（週一起算）在 Redmine 新建立的議題數（依 created_on）。參考值（W36）：46。',
            ],
            [
                'key' => 'delivery.closed',
                'name' => '結案議題',
                'category' => Category::Delivery,
                'unit' => MetricUnit::Count,
                'period_type' => PeriodType::Week,
                'better' => MetricDirection::None,
                'description' => '該週（週一起算）結案的議題數（依 closed_on）。注意：這不是產能指標——幾乎所有議題都由文豪驗收後結案，反映的是文豪的驗收量；團隊產能請看 delivery.advanced_to_verify。參考值（W36）：72。',
            ],
            [
                'key' => 'delivery.net_flow',
                'name' => '淨流量',
                'category' => Category::Delivery,
                'unit' => MetricUnit::Count,
                'period_type' => PeriodType::Week,
                'better' => MetricDirection::Down,
                'is_pinned' => true,
                'description' => '該週新增議題數 − 結案議題數。正數代表 backlog 在成長，負數代表在消化。連續 3 週 > 0 會觸發警告。參考值（W36）：−26。',
            ],
            [
                'key' => 'delivery.verifying.wenhao',
                'name' => '驗證中（文豪隊列）',
                'category' => Category::Delivery,
                'unit' => MetricUnit::Count,
                'period_type' => PeriodType::Day,
                'better' => MetricDirection::Down,
                'is_pinned' => true,
                'description' => '狀態為「驗證中」且指派給文豪的未結案議題數。這是正常的驗收排隊，數量受文豪一人的驗收吞吐限制，不代表停滯。參考值（W36）：153。',
            ],
            [
                'key' => 'delivery.verifying.others',
                'name' => '驗證中（非文豪）',
                'category' => Category::Delivery,
                'unit' => MetricUnit::Count,
                'period_type' => PeriodType::Day,
                'better' => MetricDirection::Down,
                'is_pinned' => true,
                'warn_threshold' => 20,
                'description' => '狀態為「驗證中」但指派給文豪以外的人的未結案議題數（未指派的驗證中不計入）。這些議題脫離了正常驗收流程，通常已經停滯。> 20 為警告。可拆 `project:<identifier>`。參考值（W36）：61（週報的 58 只算裕樺 30＋妤欣 28，另有鈺文 2、永彬 1）。',
            ],
            [
                'key' => 'delivery.advanced_to_verify',
                'name' => '推進到驗證中的筆數',
                'category' => Category::Delivery,
                'unit' => MetricUnit::Count,
                'period_type' => PeriodType::Week,
                'better' => MetricDirection::Up,
                'description' => '該週狀態變更為「驗證中」的議題數。這才是真正的團隊產能指標（開發完成交付驗收）。dimension `assignee:<姓名>` 為依推進者拆分。資料來源是同步時觀察到的狀態變化，從 2026-09-26 第一次同步才開始記錄：之前的週沒有資料（不是 0），開始記錄的那一週只有部分天數。',
            ],
            [
                'key' => 'delivery.wenhao_throughput',
                'name' => '文豪每週驗收量',
                'category' => Category::Delivery,
                'unit' => MetricUnit::Count,
                'period_type' => PeriodType::Week,
                'better' => MetricDirection::Up,
                'description' => '該週由文豪從「驗證中」驗收結案的議題數。因為幾乎所有結案都經過文豪，這個值是公司結案速度的上限。',
            ],
            [
                'key' => 'delivery.stalled_30d',
                'name' => '停滯議題（30 天）',
                'category' => Category::Delivery,
                'unit' => MetricUnit::Count,
                'period_type' => PeriodType::Day,
                'better' => MetricDirection::Down,
                'description' => '未結案且 updated_on 超過 30 天沒有更新的議題數。可拆 `project:<identifier>`。',
            ],
            [
                'key' => 'delivery.stalled_90d',
                'name' => '停滯議題（90 天）',
                'category' => Category::Delivery,
                'unit' => MetricUnit::Count,
                'period_type' => PeriodType::Day,
                'better' => MetricDirection::Down,
                'description' => '未結案且 updated_on 超過 90 天沒有更新的議題數。週增 > 10 會觸發警告。可拆 `project:<identifier>`。參考值（W36）：53。',
            ],
            [
                'key' => 'delivery.overdue',
                'name' => '逾期議題',
                'category' => Category::Delivery,
                'unit' => MetricUnit::Count,
                'period_type' => PeriodType::Day,
                'better' => MetricDirection::Down,
                'description' => '未結案、有 due_date 且 due_date 早於觀測日的議題數。沒有填 due_date 的議題不計入。',
            ],
            [
                'key' => 'delivery.unassigned',
                'name' => '未指派',
                'category' => Category::Delivery,
                'unit' => MetricUnit::Count,
                'period_type' => PeriodType::Day,
                'better' => MetricDirection::Down,
                'description' => '未結案且沒有指派人的議題數。',
            ],
            [
                'key' => 'delivery.hours_logged',
                'name' => '登錄工時',
                'category' => Category::Delivery,
                'unit' => MetricUnit::Hours,
                'period_type' => PeriodType::Week,
                'better' => MetricDirection::None,
                'description' => '該週 Redmine 登錄的工時加總，dimension `user:<姓名>` 依人拆分。僅供參考：目前驗收工時沒有登錄，登錄習慣也不一致，不能用來衡量產能或工作量。參考值（W36）：25.5。',
            ],
            [
                'key' => 'delivery.inflow_to_wenhao_ratio',
                'name' => '新案落到文豪的比例',
                'category' => Category::Delivery,
                'unit' => MetricUnit::Ratio,
                'period_type' => PeriodType::Week,
                'better' => MetricDirection::Down,
                'description' => '該週新增議題中，目前指派給文豪的比例，以 0–1 小數儲存（0.8 = 80%）。單點瓶頸指標：越高代表越依賴文豪一人。參考值（W36）：0.8。',
            ],

            // 公司 company
            [
                'key' => 'company.closing_projects',
                'name' => '年底前待結案專案進度',
                'category' => Category::Company,
                'unit' => MetricUnit::Count,
                'period_type' => PeriodType::Snapshot,
                'better' => MetricDirection::Down,
                'is_pinned' => true,
                'description' => 'projects.status = closing 的專案（目前四案）各自在 Redmine 的未結議題數。dimension 為 `project:<identifier>`，空字串為四案合計。距目標日天數不存在這個指標裡，請看 projects.target_close_date。目標日前 30 天內仍有 > 10 筆未結議題會觸發嚴重警示。',
            ],
        ];
    }
}
