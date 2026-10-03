<?php

use App\Mcp\Servers\InfolinkServer;
use App\Mcp\Tools\DevActivitySummary;
use App\Models\Developer;
use App\Models\GithubCommit;
use App\Models\GithubIdentity;
use App\Models\GithubRepo;
use App\Models\RedmineIssue;

beforeEach(function () {
    $this->travelTo('2026-10-05 08:45:00');
});

test('dev_activity_summary returns people, the previous window, daily commits and untracked counts', function () {
    $repo = GithubRepo::factory()->create(['name' => 'pos', 'full_name' => 'infolinktw/pos']);
    $howl = Developer::factory()->create(['name' => 'Howl']);
    Developer::factory()->create(['name' => 'Roy', 'notes' => '暫離，2027-01 回來']);
    $identity = GithubIdentity::factory()->for($howl)->create();
    RedmineIssue::factory()->create(['id' => 2881, 'subject' => '作廢要選原因', 'status' => '處理中']);

    GithubCommit::factory()->for($repo, 'repo')->for($identity, 'identity')->create([
        'authored_at' => '2026-10-02 10:00', 'subject' => 'feat(pos): 作廢原因', 'redmine_issue_ids' => [2881],
    ]);
    GithubCommit::factory()->for($repo, 'repo')->for($identity, 'identity')->create([
        'authored_at' => '2026-09-30 09:00', 'subject' => 'add react build',
    ]);
    GithubCommit::factory()->for($repo, 'repo')->for($identity, 'identity')->create([
        'authored_at' => '2026-09-25 09:00', 'subject' => 'fix: 上週的修正',
    ]);
    GithubCommit::factory()->merge()->for($repo, 'repo')->for($identity, 'identity')->create(['authored_at' => '2026-10-02 11:00']);

    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(DevActivitySummary::class)
        ->assertOk()
        ->assertSee([
            '"window":{"from":"2026-09-28","to":"2026-10-04","days":7,"previous_from":"2026-09-21","previous_to":"2026-09-27"',
            '"github_activity":{"last_status":null',
            '{"name":"Roy","is_active":true,"notes":"暫離，2027-01 回來"}',
            '"people":[{"name":"Howl","is_unmapped":false,"active_days":2,"commits":2',
            '"previous_people":[{"name":"Howl","is_unmapped":false,"active_days":1,"commits":1',
            '"date":"2026-10-02","people":[{"name":"Howl","commits":1',
            '["10:00","feat","pos","feat(pos): 作廢原因",[2881]]',
            '"issues":[{"id":2881,"subject":"作廢要選原因","status":"處理中","is_closed":false}]',
            '"untracked":[{"name":"Howl","untracked":1,"commits":2}]',
        ]);
});

test('dev_activity_summary accepts an explicit window and requires the read ability', function () {
    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(DevActivitySummary::class, ['from' => '2026-09-01', 'to' => '2026-09-03'])
        ->assertSee('"window":{"from":"2026-09-01","to":"2026-09-03","days":3,"previous_from":"2026-08-29","previous_to":"2026-08-31"');

    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(DevActivitySummary::class, ['from' => '2026-09-05', 'to' => '2026-09-01'])
        ->assertHasErrors(['to must not be before from.']);
});
