<?php

use App\Enums\Source;
use App\Models\Concerns\HasSource;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::create('sourced_records', function (Blueprint $table) {
        $table->id();
        $table->string('title');
        $table->sourceColumns();
        $table->timestamps();
    });

    $this->model = new class extends Model
    {
        use HasSource;

        protected $table = 'sourced_records';

        protected $fillable = ['title'];
    };
});

test('new records default to a manual source written by the system', function () {
    $record = $this->model::create(['title' => 'Receivable']);

    expect($record->fresh())
        ->source->toBe(Source::Manual)
        ->actor->toBe('system');
});

test('upserting the same source and external key twice keeps a single row', function () {
    $this->model::upsertFromSource(Source::Redmine, '1234', ['title' => 'First']);
    $record = $this->model::upsertFromSource(Source::Redmine, '1234', ['title' => 'Second']);

    expect($this->model::count())->toBe(1)
        ->and($record->title)->toBe('Second');
});

test('the same external key from different sources stays separate', function () {
    $this->model::upsertFromSource(Source::Redmine, '1234', ['title' => 'Issue']);
    $this->model::upsertFromSource(Source::Bank, '1234', ['title' => 'Transaction']);

    expect($this->model::fromSource(Source::Bank)->sole()->title)->toBe('Transaction');
});

test('the actor is the api token name when authenticated with a token', function () {
    $user = User::factory()->create();
    $user->withAccessToken($user->createToken('claude-cli')->accessToken);
    $this->actingAs($user);

    expect($this->model::create(['title' => 'Insight'])->actor)->toBe('claude-cli');
});

test('the actor is the user name for a panel session', function () {
    $this->actingAs(User::factory()->create(['name' => 'Kenneth']));

    expect($this->model::create(['title' => 'Action item'])->actor)->toBe('Kenneth');
});

test('an explicitly given actor is kept', function () {
    expect($this->model::create(['title' => 'Report', 'actor' => 'launchd-brief'])->actor)->toBe('launchd-brief');
});
