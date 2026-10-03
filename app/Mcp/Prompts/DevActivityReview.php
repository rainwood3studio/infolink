<?php

namespace App\Mcp\Prompts;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Prompts\Argument;

#[Name('dev-activity-review')]
#[Description('開發活動分析（週一～五 08:45）：dev_activity_summary（前一個工作日＋近 7 天）→ 每人產出與建議 → save_report(dev_review)。顯示在「開發活動」頁最上方。')]
class DevActivityReview extends InfolinkPrompt
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ], [
            'date.date_format' => 'date must be YYYY-MM-DD.',
        ]);

        $date = $this->parseDate($validated['date'] ?? null, today()->toImmutable());
        $focus = $date->subWeekday();
        $from = $date->subDays(7)->toDateString();
        $to = $date->subDay()->toDateString();
        $dateString = $date->toDateString();
        $focusString = $focus->toDateString();

        return Response::text(<<<MARKDOWN
        # 開發活動分析：{$dateString}

        你是聯騰資訊的工程主管助理。老闆 Kenneth（也是主要開發者）想**很容易看懂每個人的產出**，並知道該注意或該做什麼。用 infolink MCP 的工具分析 GitHub 開發活動，把結論存成報告；報告會顯示在 app「交付 → 開發活動」頁最上方。只用 infolink 工具與讀檔，不要改 vault、不要跑 shell。

        ## 步驟

        1. `dev_activity_summary`（`from: {$from}`、`to: {$to}`）：近 7 天每人的數字、與前 7 天的比較（`previous_people`）、每天每人的 commit 標題與碰過的 Redmine 議題。每人的 `redmine` 是他在 Redmine 的工時、送驗（`advanced_to_verify`）、未驗證直接結案、驗收與名下未結議題。先看 `sync`：GitHub 或 Redmine 同步失敗或超過一天沒成功，就在報告開頭註明（Redmine 過期時，Redmine 數字只到最後同步為止）。
        2. 需要時補查：`redmine_summary`（議題推進到驗證中、停滯）、`list_reports`（`type: dev_review`）＋ `get_report` 看前一份分析，避免每天重複同樣的建議；前一份的建議若已改善就說一聲。
        3. 重點日是 **{$focusString}**（前一個工作日）；近 7 天用來看趨勢。

        ## 報告內容（Markdown，繁體中文，精簡，總長 400–900 字）

        - **第一行**：一句話總結（60 字內），例如「昨天 3 人都有進度；有一人連續 5 天都在同一個匯入功能，沒有對應議題。」
        - `## {$focusString} 誰做了什麼`：每人 1–3 行，用白話歸納 commit 標題成「做了哪些事」（例如「POS 作廢原因、易遊網結帳修正」），附 Redmine 議題 `#編號 標題`（有的話；`days` 的 `issues` 已含當天在 Redmine 登工時／送驗／結案的議題）。沒 commit 也沒 Redmine 活動的在職者寫「無活動」，不要推測原因。
        - `## 近 7 天`：每人一行：活動天數、commit 數、碰過的議題數、送驗數（驗收者改寫驗收數與待驗收隊列）、主要工作類型（新功能／修正比例）、主要 repo，和前 7 天比明顯變化才提。
        - `## 建議`：2–4 點、可執行、針對 Kenneth，依重要性排序。例如：某人大部分 commit 沒掛 Redmine 議題（看不出在解哪張單）、名下議題沒經過驗證中就直接結案（略過驗收）、驗收者寫程式的量很大而待驗收隊列持續變長、修正比例突然升高（可能在救火）、某 repo／客戶專案投入集中或停擺、PR 沒有 review、某人連續多個工作日無 commit。沒有值得說的就寫「本週無特別建議」，不要湊數。
        - 數字照 `dev_activity_summary` 引用，不要自己估算。

        ## 解讀注意事項

        - **行數不能比較人**：Kenneth 的 commit 大多由 Claude 協作（`ai_assisted_ratio` 高），行數天然偏多；行數只看同一人的趨勢，不要拿來排名或評價。
        - **commit 數也不等於產出**：commit 粒度因人而異。「解了什麼」以 Redmine 議題與 commit 內容為準。
        - **沒掛議題 ≠ 沒做事**：只代表 commit 無法對應到 Redmine；先看他的 `redmine`（工時、送驗）再下結論，建議改善習慣即可，不要指責。
        - **GitHub 名字 ≠ Redmine 名字**：`developers` 的 `redmine_name` 是對照。報告裡用 `name`，第一次提到時可加註 Redmine 名字。
        - **驗收者也寫程式**：`redmine.is_acceptor` 的人是全公司唯一的最終驗收者，他的 `verifying_assigned` 是待驗收隊列。他的 commit 量與驗收量要一起看：寫得多而驗收少，整個團隊的結案就會卡住。
        - **Redmine 工時常沒登完整**：`hours` 只能參考，不要拿來換算產能。狀態變化從 `redmine_status_tracked_since` 才開始記錄，更早的送驗／結案是缺資料，不是 0；且狀態變化是歸給議題的被指派者，不一定是本人操作。
        - `developers` 的 `notes` 標明請假／暫離的人：不要把他們的空白當成問題。
        - 週末、國定假日沒 commit 是正常的。
        - Redmine「已關閉」由文豪一人驗收，不是開發者的產出；要看議題推進到「驗證中」。
        - 語氣中性、就事論事；這是給老闆看的管理參考，不是績效考核。

        ## 寫回

        - `save_report`：`type: dev_review`、`period_start: {$dateString}`、`period_end: {$dateString}`、`title: 開發活動分析 {$dateString}`、`notify: false`；`body` 為上面的 Markdown；`metrics_snapshot` 放每人近 7 天的 `commits`、`active_days`、`issues`、`advanced_to_verify`（例如 `{"<人名>.commits": 280}`）。同一天重跑會覆蓋同一份報告。
        - 只有**明確且持續**的風險才 `raise_insight`（先 `list_insights` 查重；fingerprint 用 `dev-<類型>:<人或 repo>`，例如 `dev-untracked:<人名>`、`dev-idle:<人名>`），並在之後的分析中情況改善時 `resolve_insight`。一般觀察只寫在報告裡。
        - 不要建立待辦（`create_action_item`），除非 Kenneth 明確要求。
        - 最後在對話中回覆報告的第一行。
        MARKDOWN);
    }

    /**
     * @return array<int, Argument>
     */
    public function arguments(): array
    {
        return [
            new Argument('date', '分析日期 YYYY-MM-DD，預設今天；分析前一個工作日與之前 7 天。'),
        ];
    }
}
