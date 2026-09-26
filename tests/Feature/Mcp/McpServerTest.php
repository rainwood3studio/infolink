<?php

use App\Mcp\Servers\InfolinkServer;
use App\Mcp\Tools\GetCashPosition;
use App\Models\BankTransaction;
use App\Models\User;

test('the endpoint rejects requests without a token', function () {
    $this->postJson('/mcp/infolink', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
        ->assertUnauthorized();
});

test('a bearer token can list tools over http', function () {
    $user = User::factory()->create();
    $token = $user->createToken('claude-cli', ['read'])->plainTextToken;

    $this->withToken($token)
        ->postJson('/mcp/infolink', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
        ->assertOk()
        ->assertJsonFragment(['name' => 'get_cash_position']);
});

test('read tools are hidden and refused without the read ability', function () {
    InfolinkServer::actingAs(mcpUser(['write']))
        ->tool(GetCashPosition::class)
        ->assertHasErrors(['not found']);
});

test('get_cash_position reports the balance and forecast', function () {
    BankTransaction::factory()->create(['txn_date' => today(), 'balance' => 1_072_776]);

    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(GetCashPosition::class)
        ->assertOk()
        ->assertSee(['"balance":1072776', 'year_end_balance']);
});

test('get_cash_position says when no bank data exists', function () {
    InfolinkServer::actingAs(mcpUser(['read']))
        ->tool(GetCashPosition::class)
        ->assertSee('No bank transactions imported yet.');
});
