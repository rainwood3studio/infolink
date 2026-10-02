<?php

use App\Filament\Resources\Developers\DeveloperResource;
use App\Filament\Resources\Developers\Pages\CreateDeveloper;
use App\Filament\Resources\Developers\Pages\EditDeveloper;
use App\Filament\Resources\Developers\RelationManagers\IdentitiesRelationManager;
use App\Filament\Resources\GithubIdentities\GithubIdentityResource;
use App\Filament\Resources\GithubIdentities\Pages\ListGithubIdentities;
use App\Filament\Resources\GithubRepos\GithubRepoResource;
use App\Filament\Resources\GithubRepos\Pages\ListGithubRepos;
use App\Models\Developer;
use App\Models\GithubIdentity;
use App\Models\GithubRepo;
use App\Models\Project;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
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
