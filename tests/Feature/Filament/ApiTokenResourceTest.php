<?php

use App\Filament\Resources\ApiTokens\Pages\ListApiTokens;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('lists only the signed-in user\'s tokens', function () {
    $this->user->createToken('claude-cli', ['read', 'write']);
    User::factory()->create()->createToken('someone-else', ['read']);

    Livewire::test(ListApiTokens::class)
        ->assertOk()
        ->assertCanSeeTableRecords(PersonalAccessToken::query()->where('name', 'claude-cli')->get())
        ->assertCanNotSeeTableRecords(PersonalAccessToken::query()->where('name', 'someone-else')->get())
        ->assertSee('write')
        ->assertSee('從未使用');

    $this->get('/admin/api-tokens')->assertOk()->assertSee('claude-cli');
});

it('creates a token, stores it hashed and shows the plaintext once with the claude command', function () {
    $page = Livewire::test(ListApiTokens::class)
        ->callAction('createToken', data: ['name' => 'claude-cli', 'abilities' => ['write'], 'expires_on' => today()->addDays(30)->toDateString()])
        ->assertHasNoFormErrors()
        ->assertActionMounted('showToken');

    $token = $this->user->tokens()->sole();
    $plainText = $page->instance()->mountedActions[0]['arguments']['token'];

    expect($token)
        ->name->toBe('claude-cli')
        ->abilities->toBe(['read', 'write'])
        ->expires_at->toDateTimeString()->toBe(today()->addDays(30)->endOfDay()->toDateTimeString())
        ->and(PersonalAccessToken::findToken($plainText)?->is($token))->toBeTrue()
        ->and($token->token)->not->toContain(explode('|', $plainText)[1]);

    $page->assertMountedActionModalSee($plainText)
        ->assertMountedActionModalSee('claude mcp add --transport http --scope user infolink '.url('/mcp/infolink').' --header "Authorization: Bearer '.$plainText.'"');

    Livewire::test(ListApiTokens::class)->assertDontSee($plainText);
});

it('keeps read-only tokens read-only and without expiry', function () {
    Livewire::test(ListApiTokens::class)
        ->callAction('createToken', data: ['name' => 'launchd-brief', 'abilities' => ['read']])
        ->assertHasNoFormErrors();

    expect($this->user->tokens()->sole())
        ->abilities->toBe(['read'])
        ->expires_at->toBeNull();
});

it('requires a name and at least one ability', function () {
    Livewire::test(ListApiTokens::class)
        ->callAction('createToken', data: ['name' => '', 'abilities' => []])
        ->assertHasFormErrors(['name' => 'required', 'abilities' => 'required']);

    expect(PersonalAccessToken::count())->toBe(0);
});

it('revokes a token', function () {
    $this->user->createToken('vault-agent', ['read']);
    $token = $this->user->tokens()->sole();

    Livewire::test(ListApiTokens::class)
        ->callAction(TestAction::make('delete')->table($token))
        ->assertNotified();

    expect(PersonalAccessToken::count())->toBe(0);
});

it('has no create or edit routes', function () {
    expect(Route::has('filament.admin.resources.api-tokens.create'))->toBeFalse()
        ->and(Route::has('filament.admin.resources.api-tokens.edit'))->toBeFalse();
});
