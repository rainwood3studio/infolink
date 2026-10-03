<?php

namespace App\Mcp\Prompts;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Prompts\Argument;

#[Name('company-advisor')]
#[Description('AI 顧問分析（週一～五 08:55）：把現金、收入、結案、驗收、團隊、營運六個面向的數字全部看過 → 每個面向的燈號、要注意的地方與建議 → save_report(advisor)。顯示在「AI 顧問」頁。')]
class CompanyAdvisor extends InfolinkPrompt
{
    /**
     * The areas every advisor report covers, in display order. The report stores one entry per key in
     * `metrics_snapshot.advisor.domains`; the AI 顧問 page renders them as cards.
     *
     * @var array<string, string>
     */
    public const array DOMAINS = [
        'cash' => '現金與收款',
        'revenue' => '收入展望與業務',
        'closing' => '結案專案',
        'acceptance' => '驗收與交付流程',
        'team' => '團隊產出',
        'operations' => '待辦、資料與系統',
    ];

    /** Traffic-light values of a domain, worst first. */
    public const array STATUSES = ['red', 'yellow', 'green'];

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ], [
            'date.date_format' => 'date must be YYYY-MM-DD.',
        ]);

        $date = $this->parseDate($validated['date'] ?? null, today()->toImmutable())->toDateString();
        $domains = collect(self::DOMAINS)->map(fn (string $name, string $key): string => "`{$key}`（{$name}）")->implode('、');

        return Response::text(<<<MARKDOWN
        # 聯騰資訊 AI 顧問分析：{$date}

        你是聯騰資訊（3 人軟體公司）CEO Kenneth 的營運顧問。把公司**所有面向**的數字看過一遍，告訴他現況、要注意的地方和具體建議，存成一份報告；報告會顯示在 app 的「AI 顧問」頁，是他每天打開就看的那一頁。只用 infolink 工具與讀檔，不要改 vault、不要跑 shell。

        每日簡報（`daily-brief`）負責「今天要處理的 3–5 件事」並建立 insight／待辦；**你負責全貌與判斷**：每個面向現在是好是壞、趨勢往哪走、最該做的決定是什麼。不要重複建立 insight 或待辦，引用現有的即可。

        ## 步驟

        1. `get_briefing`：全貌與資料新鮮度。任何資料過期（銀行、Redmine、GitHub）先記下來，寫在報告開頭，並在引用該資料的面向註明「數字只到 X 日」。
        2. 逐一看六個面向，每個面向都要實際呼叫工具，不要只靠 `get_briefing`：
           - `cash` 現金與收款：`get_cash_position`、`list_receivables`（逾期、30 天內到期、發票開了沒）。
           - `revenue` 收入展望與業務：`revenue_outlook`（每月缺口、現金高點、開始下滑與歸零月份；再用 `delay_months: 2` 看尾款延後的情況）、`list_deals`（缺金額／預計成交日／下一步日期的機會要點名）。
           - `closing` 結案專案：`closing_projects`（每案卡在誰、推估結案日 vs 目標日、掛著的應收）。
           - `acceptance` 驗收與交付流程：`acceptance_queue`（隊列大小、每週驗收 vs 送驗、清空週數、流程外）、`redmine_summary`（淨流量、停滯、未指派）。
           - `team` 團隊產出：`dev_activity_summary`（每人近 7 天 vs 前 7 天、Redmine 送驗／驗收、沒掛議題的 commit）。
           - `operations` 待辦、資料與系統：`list_action_items`（逾期多久、都掛在誰名下）、`list_insights`（open 的 critical／warning，含伺服器硬碟、同步失敗、簡報未產生）、資料新鮮度。
        3. `list_reports`（`type: advisor`，最近 2 份）＋ `get_report` 讀前一份：指出**跟上次比變好或變壞的地方**，上次的建議有沒有被執行。沒有前一份就略過。
        4. 排出**最重要的三件事**：跨面向挑選，以對現金與結案的影響排序。每件都要具體到「誰、做什麼、什麼時候前」，並說明不做會怎樣。寧可少而準，不要湊數。

        ## 報告格式（Markdown，繁體中文）

        - **第一行**：一句話總結公司現況（60 字內）。
        - `## 最重要的三件事`：編號清單，每件 2–3 行（為什麼重要＋數字、建議動作、誰／何時）。
        - 接著六個面向各一節，標題固定為：`## 現金與收款`、`## 收入展望與業務`、`## 結案專案`、`## 驗收與交付流程`、`## 團隊產出`、`## 待辦、資料與系統`。每節三段、各 1–4 點：
          - **現況**：2–4 個關鍵數字（照工具回傳的引用，註明含稅／未稅與資料日期）。
          - **要注意**：風險、惡化的趨勢、數字之間的矛盾（例如寫程式的量很大但驗收隊列變長）。沒有就寫「沒有特別要注意的」。
          - **建議**：可執行的下一步；需要 Kenneth 做決定的事要寫成選項與你的推薦。
        - 最後 `## 跟上次比`：變好的、變壞的、上次建議的執行情況（沒有前一份就省略這節）。
        - 全文 900–1,600 字。數字一律引用工具回傳值，不要自己估算；工具沒給的就說「沒有資料」。

        ## 燈號（寫進 metrics_snapshot）

        每個面向給一個燈號與一句話（30 字內），頁面會顯示成卡片：

        - `red`：需要本週就處理，否則會直接影響現金、結案或對客戶的承諾。
        - `yellow`：有風險或趨勢在惡化，兩週內要有動作。
        - `green`：正常，照現在的做法即可。
        - 資料過期到無法判斷時用 `yellow`，一句話寫明「資料只到 X 日」。

        {$this->advisorCaveats()}

        ## 寫回

        - `save_report`：`type: advisor`、`period_start: {$date}`、`period_end: {$date}`、`title: AI 顧問分析 {$date}`、`notify: false`（推播由每日簡報負責）；`body` 為上面的 Markdown；`metrics_snapshot` 為：
          ```json
          {
            "advisor": {
              "domains": [
                {"key": "cash", "status": "red|yellow|green", "headline": "一句話（30 字內）"}
              ],
              "top": ["最重要的三件事，各一句話（40 字內）"]
            },
            "cash.balance": 0
          }
          ```
          `domains` 必須剛好包含這六個 key，順序如下：{$domains}。其餘引用到的指標值（如 `cash.balance`、`ar.overdue_taxed`）照常放在同一層。同一天重跑會覆蓋同一份報告。
        - **不要** `raise_insight`、`create_action_item`、`record_metrics`：那是每日簡報與規則引擎的工作。發現每日簡報漏掉的重大風險時，寫在「最重要的三件事」裡即可。
        - 最後在對話中回覆報告的第一行。
        MARKDOWN);
    }

    /**
     * The domain caveats plus the ones specific to reading team and revenue numbers.
     */
    protected function advisorCaveats(): string
    {
        return $this->domainCaveats().<<<'MARKDOWN'

        - **行數與 commit 數不能比較人**：Kenneth 的 commit 大多由 Claude 協作；「解了什麼」看 Redmine 議題、送驗與驗收。GitHub 名字與 Redmine 名字不同，以 `dev_activity_summary` 的 `developers[].redmine_name` 對照。
        - **驗收者也寫程式**：唯一的驗收者同時是主要開發者之一，他的 commit 量與驗收量要一起看。
        - **收入展望的假設**：`recurring_assumed` 是假設維運合約續約的外推，不是已確定的收入；業務機會沒有金額或預計成交日就不會被計入。
        - **推估結案日是直線外推**：只代表「照近幾天的速度」，不是承諾；未結數不減反增時要直說結不完。
        - 語氣直接、就事論事，像一位看過所有報表的顧問；不要客套，不要重複每日簡報的內容。
        MARKDOWN;
    }

    /**
     * @return array<int, Argument>
     */
    public function arguments(): array
    {
        return [
            new Argument('date', '分析日期 YYYY-MM-DD，預設今天。'),
        ];
    }
}
