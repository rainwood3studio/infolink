<?php

use App\Domain\Delivery\Exceptions\RedmineRequestException;
use App\Domain\Delivery\Exceptions\RedmineUnavailableException;
use App\Domain\Delivery\RedmineClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Feature\Domain\Delivery\Sync\FakeRedmine;

beforeEach(function () {
    config(['services.redmine.url' => 'http://redmine.test/', 'services.redmine.key' => 'secret-key']);
    Sleep::fake();
});

test('paginate walks every page with offset/limit and sends the api key', function () {
    $redmine = new FakeRedmine;
    $redmine->issues = array_map(fn (int $id) => FakeRedmine::issue($id), range(1, 250));

    $ids = app(RedmineClient::class)->paginate('issues.json', ['status_id' => '*'])->pluck('id')->all();

    expect($ids)->toBe(range(1, 250));

    Http::assertSentCount(3);
    foreach ([0, 100, 200] as $offset) {
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'http://redmine.test/issues.json?')
            && $request['offset'] === $offset
            && $request['limit'] === 100
            && $request['status_id'] === '*'
            && $request->hasHeader('X-Redmine-API-Key', 'secret-key'));
    }
});

test('paginate is lazy and stops on an empty page', function () {
    $redmine = new FakeRedmine;

    expect(app(RedmineClient::class)->paginate('time_entries.json')->all())->toBe([]);

    Http::assertSentCount(1);
});

test('a connection failure is retried once then reported as unavailable', function () {
    $redmine = new FakeRedmine;
    $redmine->unreachable = true;

    expect(fn () => app(RedmineClient::class)->get('issues.json'))
        ->toThrow(RedmineUnavailableException::class, 'Redmine unreachable');

    Sleep::assertSleptTimes(1);
});

test('a 5xx is unavailable and a 4xx is a refused request', function () {
    Http::fake([
        'redmine.test/issues.json*' => Http::response('boom', 503),
        'redmine.test/projects.json*' => Http::response(['errors' => []], 401),
    ]);

    expect(fn () => app(RedmineClient::class)->get('issues.json'))->toThrow(RedmineUnavailableException::class, 'HTTP 503')
        ->and(fn () => app(RedmineClient::class)->get('projects.json'))->toThrow(RedmineRequestException::class, 'HTTP 401');
});

test('it refuses to run without configuration', function () {
    config(['services.redmine.key' => null]);
    Http::fake();

    expect(fn () => app(RedmineClient::class)->get('issues.json'))->toThrow(RedmineRequestException::class, 'not configured');

    Http::assertNothingSent();
});
