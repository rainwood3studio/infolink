<?php

use App\Models\Deal;
use App\Models\GithubRepo;
use App\Models\Project;

it('resolves a branch to its override project, else to the repo project', function (?string $branch, string $expected) {
    $projects = ['apos' => Project::factory()->create(), 'hr' => Project::factory()->create()];
    $repo = GithubRepo::factory()->branchProject('dycare-dev', $projects['hr'])->create(['project_id' => $projects['apos']->id])->fresh();

    expect($repo->projectIdForBranch($branch))->toBe($projects[$expected]->id);
})->with([
    'overridden branch' => ['dycare-dev', 'hr'],
    'default branch' => ['main', 'apos'],
    'branch that only starts with the override' => ['dycare-dev-hotfix', 'apos'],
    'unknown branch' => [null, 'apos'],
]);

it('has no project for a branch when the repo is tied to nothing or only to a deal', function () {
    $deal = Deal::factory()->create();
    $repo = GithubRepo::factory()->create(['deal_id' => $deal->id])->fresh();

    expect($repo->projectIdForBranch('main'))->toBeNull()
        ->and($repo->deal->is($deal))->toBeTrue();
});

it('unties the repo when its deal is deleted', function () {
    $deal = Deal::factory()->create();
    $repo = GithubRepo::factory()->create(['deal_id' => $deal->id]);

    $deal->delete();

    expect($repo->fresh()->deal_id)->toBeNull();
});

it('normalizes branch overrides for storage', function () {
    expect(GithubRepo::normalizeBranchProjects([
        ['branch' => ' dycare-dev ', 'project_id' => '6'],
        ['branch' => '', 'project_id' => 3],
        ['branch' => 'next', 'project_id' => null],
        ['branch' => 'dycare-dev', 'project_id' => 7],
    ]))->toBe([['branch' => 'dycare-dev', 'project_id' => 7]])
        ->and(GithubRepo::normalizeBranchProjects([]))->toBeNull()
        ->and(GithubRepo::normalizeBranchProjects(null))->toBeNull();
});
