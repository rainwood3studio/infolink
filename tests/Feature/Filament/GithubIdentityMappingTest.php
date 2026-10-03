<?php

use App\Enums\DealStage;
use App\Filament\Resources\Developers\DeveloperResource;
use App\Filament\Resources\Developers\Pages\CreateDeveloper;
use App\Filament\Resources\Developers\Pages\EditDeveloper;
use App\Filament\Resources\Developers\RelationManagers\IdentitiesRelationManager;
use App\Filament\Resources\GithubIdentities\GithubIdentityResource;
use App\Filament\Resources\GithubIdentities\Pages\ListGithubIdentities;
use App\Filament\Resources\GithubRepos\GithubRepoResource;
use App\Filament\Resources\GithubRepos\Pages\ListGithubRepos;
use App\Filament\Resources\GithubRepos\Tables\GithubReposTable;
use App\Models\Deal;
use App\Models\Developer;
use App\Models\GithubIdentity;
use App\Models\GithubRepo;
use App\Models\Project;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Repeater;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('renders the developer, identity and repo pages', function () {
    $developer = Developer::factory()->create();
    GithubIdentity::factory()->for($developer)->create();
    GithubRepo::factory()->create();

    $this->get(DeveloperResource::getUrl('index'))->assertOk();
    $this->get(DeveloperResource::getUrl('edit', ['record' => $developer]))->assertOk();
    $this->get(GithubIdentityResource::getUrl('index'))->assertOk();
    $this->get(GithubRepoResource::getUrl('index'))->assertOk();
});

it('creates a developer', function () {
    Livewire::test(CreateDeveloper::class)
        ->fillForm(['name' => '永彬', 'redmine_name' => '陳永彬', 'is_active' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Developer::sole())->name->toBe('永彬')->redmine_name->toBe('陳永彬');
});

it('maps an identity to a developer inline and filters the unmapped ones', function () {
    $developer = Developer::factory()->create(['name' => '永彬']);
    $mapped = GithubIdentity::factory()->for($developer)->create();
    $unmapped = GithubIdentity::factory()->create();

    Livewire::test(ListGithubIdentities::class)
        ->filterTable('mapped', false)
        ->assertCanSeeTableRecords([$unmapped])
        ->assertCanNotSeeTableRecords([$mapped])
        ->call('updateTableColumnState', 'developer_id', (string) $unmapped->getKey(), $developer->id);

    expect($unmapped->refresh()->developer_id)->toBe($developer->id)
        ->and(GithubIdentityResource::getNavigationBadge())->toBeNull();
});

it('assigns several identities at once', function () {
    $developer = Developer::factory()->create();
    $identities = GithubIdentity::factory()->count(2)->create();

    Livewire::test(ListGithubIdentities::class)
        ->selectTableRecords($identities)
        ->callAction(TestAction::make('assign')->table()->bulk(), data: ['developer_id' => $developer->id])
        ->assertHasNoFormErrors();

    expect($developer->identities()->count())->toBe(2);
});

it('lists and unmaps identities under a developer', function () {
    $developer = Developer::factory()->create();
    $identity = GithubIdentity::factory()->for($developer)->create(['login' => 'yubin']);

    Livewire::test(IdentitiesRelationManager::class, ['ownerRecord' => $developer, 'pageClass' => EditDeveloper::class])
        ->assertCanSeeTableRecords([$identity])
        ->callAction(TestAction::make('unmap')->table($identity));

    expect($identity->refresh()->developer_id)->toBeNull();
});

it('ties a repo to a project inline', function () {
    $repo = GithubRepo::factory()->create();
    $project = Project::factory()->create();

    Livewire::test(ListGithubRepos::class)
        ->call('updateTableColumnState', 'project_id', (string) $repo->getKey(), $project->id);

    expect($repo->refresh()->project_id)->toBe($project->id);
});

it('ties a repo to an open deal inline and only offers open deals or ones already in use', function () {
    $repo = GithubRepo::factory()->create();
    $open = Deal::factory()->create(['prospect_name' => '多羅滿賞鯨', 'title' => '賞鯨訂位中樞']);
    Deal::factory()->create(['stage' => DealStage::Lost]);
    $wonInUse = Deal::factory()->create(['prospect_name' => '長照', 'title' => 'HR 二期', 'stage' => DealStage::Won]);
    GithubRepo::factory()->create(['deal_id' => $wonInUse->id]);

    Livewire::test(ListGithubRepos::class)
        ->call('updateTableColumnState', 'deal_id', (string) $repo->getKey(), $open->id);

    expect($repo->refresh()->deal_id)->toBe($open->id)
        ->and(GithubReposTable::dealOptions())->toBe([
            $wonInUse->id => '長照｜HR 二期（成交）',
            $open->id => '多羅滿賞鯨｜賞鯨訂位中樞',
        ]);
});

it('sets the branch overrides of a repo and shows them in the list', function () {
    $undoRepeaterFake = Repeater::fake();
    $apos = Project::factory()->create(['name' => 'APOS 2.0']);
    $hr = Project::factory()->create(['name' => 'HR 系統']);
    $repo = GithubRepo::factory()->create(['project_id' => $apos->id]);

    Livewire::test(ListGithubRepos::class)
        ->callAction(TestAction::make('branchProjects')->table($repo), data: [
            'branch_projects' => [['branch' => ' dycare-dev ', 'project_id' => $hr->id]],
        ])
        ->assertHasNoFormErrors()
        ->assertSee('dycare-dev → HR 系統');

    $undoRepeaterFake();

    expect($repo->refresh()->branch_projects)->toBe([['branch' => 'dycare-dev', 'project_id' => $hr->id]])
        ->and($repo->projectIdForBranch('dycare-dev'))->toBe($hr->id);
});

it('clears the branch overrides when the last one is removed', function () {
    $undoRepeaterFake = Repeater::fake();
    $hr = Project::factory()->create();
    $repo = GithubRepo::factory()->branchProject('dycare-dev', $hr)->create();

    Livewire::test(ListGithubRepos::class)
        ->callAction(TestAction::make('branchProjects')->table($repo), data: ['branch_projects' => []])
        ->assertHasNoFormErrors();

    $undoRepeaterFake();

    expect($repo->refresh()->branch_projects)->toBeNull();
});

it('filters the repos that are tied to nothing', function () {
    $unmapped = GithubRepo::factory()->create();
    $withProject = GithubRepo::factory()->create(['project_id' => Project::factory()->create()->id]);
    $withDeal = GithubRepo::factory()->create(['deal_id' => Deal::factory()->create()->id]);

    Livewire::test(ListGithubRepos::class)
        ->filterTable('mapped', false)
        ->assertCanSeeTableRecords([$unmapped])
        ->assertCanNotSeeTableRecords([$withProject, $withDeal]);
});
