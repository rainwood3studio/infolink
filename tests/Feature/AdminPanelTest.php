<?php

use App\Models\User;

test('guests are redirected to the panel login', function () {
    $this->get('/admin')->assertRedirect('/admin/login');
});

test('a signed-in user can open the dashboard', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin')
        ->assertOk();
});
