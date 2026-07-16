<?php

/**
 * Unit tests for SmsBroadcastSmsProvider — response parsing and capability surface.
 *
 * Covers:
 *   - send() with fake client: OK/BAD mix → per-recipient statuses and remoteIds
 *   - send(): ERROR line → all recipients marked FAILED with raw line in rawResponse
 *   - send(): international number normalisation (61XXXXXXXXX → 0XXXXXXXXX mapping)
 *   - getBalance(): OK:<credits> → success; ERROR:<reason> → failure; malformed → failure
 *   - hasCapability(): GET_BALANCE and DEFERRED_SEND true; others false
 *   - registerSenderId() and registerSenderNumber() return failure "Not supported"
 *
 * Pins improvements:
 *   - docs/sms/improvements/32-test-coverage.md (parser coverage)
 *   - docs/sms/improvements/48-smsbroadcast-duplicate-failed-deliveries.md
 *     (one delivery per recipient; the "unseen recipients → FAILED" loop must
 *     not duplicate recipients already present in the gateway response)
 */

namespace Test\Sms\SmsBroadcastParsing;

use function \Test\{test, assert_true, assert_false, assert_eq, assert_contains};
use \Sms\{
    PhoneNumber, SmsStatus, SmsCapability,
    HttpClient, HttpRequest, HttpResponse};
use Sms\Providers\SmsBroadcastSmsProvider;
use function \Sms\sendSummary;

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------
require_once __DIR__ . '/../../src/load.php';

// ---------------------------------------------------------------------------
// Test doubles
// ---------------------------------------------------------------------------

/**
 * Fake HttpClient — captures the last request and returns a fixed Result.
 */
final class FakeHttpClient implements HttpClient
{
    public ?HttpRequest $lastRequest = null;

    public function __construct(private \Result $response) {}

    public function send(HttpRequest $request): \Result
    {
        $this->lastRequest = $request;
        return $this->response;
    }
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeProvider(HttpClient $http): SmsBroadcastSmsProvider
{
    return new SmsBroadcastSmsProvider(
        username: 'testuser',
        password: 'testpass',
        url: 'https://api.test.example/smsbroadcast',
        httpClient: $http,
    );
}

function successResponse(string $body): \Result
{
    return \Result::success(new HttpResponse($body));
}

function failureResponse(string $error): \Result
{
    return \Result::failure($error);
}

// ===========================================================================
// send() — OK/BAD mix
// ===========================================================================

test('send(): OK line → SENT delivery with remoteId from ref field', function () {
    $fake = new FakeHttpClient(successResponse("OK:0400000001:myref123\n"));
    $provider = makeProvider($fake);

    $result = $provider->send(entries('Hello', [new PhoneNumber('0400000001')]), new PhoneNumber('0411111111'));
    assert_true($result->isSuccess(), 'Expected success Result');

    $deliveries = $result->getValue()->deliveries;
    assert_eq(count($deliveries), 1, 'Exactly one delivery per recipient');
    $sent = $deliveries[0];
    assert_eq($sent->status(), SmsStatus::SENT, 'OK line should yield SENT status');
    assert_eq($sent->recipient()->value, '0400000001', 'SENT delivery should have correct recipient');
    assert_eq($sent->remoteId(), 'myref123', 'SENT delivery should have remoteId from OK line ref');
});

test('send(): BAD line → FAILED delivery with rawResponse set', function () {
    $fake = new FakeHttpClient(successResponse("BAD:0400000001:Blocked\n"));
    $provider = makeProvider($fake);

    $result = $provider->send(entries('Hello', [new PhoneNumber('0400000001')]), new PhoneNumber('0411111111'));
    assert_true($result->isSuccess());

    $deliveries = $result->getValue()->deliveries;
    assert_eq(count($deliveries), 1, 'Exactly one delivery per recipient');
    $bad = $deliveries[0];
    assert_eq($bad->status(), SmsStatus::FAILED, 'BAD line should yield FAILED status');
    assert_contains($bad->rawResponse(), 'BAD:0400000001:Blocked', 'Raw response should contain the BAD line');
});

test('send(): mixed OK+BAD → correct per-recipient statuses', function () {
    $body = "OK:0400000001:ref1\nBAD:0400000002:Blocked\n";
    $fake = new FakeHttpClient(successResponse($body));
    $provider = makeProvider($fake);

    $recipients = [new PhoneNumber('0400000001'), new PhoneNumber('0400000002')];
    $result = $provider->send(entries('Hello', $recipients), new PhoneNumber('0411111111'));
    assert_true($result->isSuccess());

    $deliveries = $result->getValue()->deliveries;
    assert_eq(count($deliveries), 2, 'Exactly one delivery per recipient, no duplicates');

    $byRecipient = [];
    foreach ($deliveries as $d) {
        $byRecipient[$d->recipient()->value] = $d;
    }
    assert_eq($byRecipient['0400000001']->status(), SmsStatus::SENT, 'OK recipient should be SENT');
    assert_contains($byRecipient['0400000001']->rawResponse(), 'OK:0400000001:ref1');
    assert_eq($byRecipient['0400000002']->status(), SmsStatus::FAILED, 'BAD recipient should be FAILED');
    assert_contains($byRecipient['0400000002']->rawResponse(), 'BAD:0400000002:Blocked');
});

test('send(): recipient missing from the response is marked FAILED, others not duplicated', function () {
    // Two recipients requested; the gateway only mentions the first.
    $fake = new FakeHttpClient(successResponse("OK:0400000001:ref1\n"));
    $provider = makeProvider($fake);

    $recipients = [new PhoneNumber('0400000001'), new PhoneNumber('0400000002')];
    $result = $provider->send(entries('Hi', $recipients), new PhoneNumber('0411111111'));
    $deliveries = $result->getValue()->deliveries;

    assert_eq(count($deliveries), 2, 'One delivery per requested recipient');

    $byRecipient = [];
    foreach ($deliveries as $d) {
        $byRecipient[$d->recipient()->value] = $d;
    }
    assert_eq($byRecipient['0400000001']->status(), SmsStatus::SENT,
        'Recipient present in the response must not be duplicated as FAILED');
    assert_eq($byRecipient['0400000002']->status(), SmsStatus::FAILED,
        'Recipient missing from the response must be marked FAILED');
});

// ===========================================================================
// send() — ERROR line (overall failure)
// ===========================================================================

test('send(): ERROR line → all recipients marked FAILED with ERROR raw line', function () {
    $fake = new FakeHttpClient(successResponse("ERROR:Invalid credentials\n"));
    $provider = makeProvider($fake);

    $recipients = [new PhoneNumber('0400000001'), new PhoneNumber('0400000002')];
    $result = $provider->send(entries('Hello', $recipients), new PhoneNumber('0411111111'));
    assert_true($result->isSuccess());

    $deliveries = $result->getValue()->deliveries;
    assert_eq(count($deliveries), 2, 'ERROR line should produce one FAILED delivery per recipient');
    foreach ($deliveries as $d) {
        assert_eq($d->status(), SmsStatus::FAILED, 'All should be FAILED on ERROR');
        assert_contains($d->rawResponse(), 'ERROR:Invalid credentials', 'Raw response should contain ERROR line');
    }
});

test('send(): ERROR line rawResponse is the ERROR line verbatim', function () {
    $fake = new FakeHttpClient(successResponse("ERROR:Invalid credentials\n"));
    $provider = makeProvider($fake);

    $result = $provider->send(entries('Hello', [new PhoneNumber('0400000001')]), new PhoneNumber('0411111111'));
    $deliveries = $result->getValue()->deliveries;

    assert_eq($deliveries[0]->rawResponse(), 'ERROR:Invalid credentials',
        'rawResponse should be the ERROR line without trailing newline');
});

// ===========================================================================
// send() — international number normalisation
// ===========================================================================

test('send(): response with 61XXXXXXXX format matched back to local 0XXXXXXXXX', function () {
    // SMS Broadcast returns Australian numbers in international format in its response.
    // The parser should map 61400000001 back to the original request number 0400000001.
    $fake = new FakeHttpClient(successResponse("OK:61400000001:ref9\n"));
    $provider = makeProvider($fake);

    $result = $provider->send(entries('Hi', [new PhoneNumber('0400000001')]), new PhoneNumber('0411111111'));
    assert_true($result->isSuccess());

    $deliveries = $result->getValue()->deliveries;
    // Find the SmsBroadcastSmsDelivery (SENT, with rawResponse)
    $sent = null;
    foreach ($deliveries as $d) {
        if ($d->status() === SmsStatus::SENT) {
            $sent = $d;
        }
    }
    assert_true($sent !== null, 'Should have a SENT delivery');
    // The delivery's recipient should be the original local number, not the international form
    assert_eq($sent->recipient()->value, '0400000001',
        'Delivery recipient should be matched back to the original local-format number');
    assert_eq($sent->remoteId(), 'ref9');
});

// ===========================================================================
// send() — empty response
// ===========================================================================

test('send(): empty response body → empty deliveries array', function () {
    $fake = new FakeHttpClient(successResponse(''));
    $provider = makeProvider($fake);

    $result = $provider->send(entries('Hi', [new PhoneNumber('0400000001')]), new PhoneNumber('0411111111'));
    assert_true($result->isSuccess());
    assert_eq($result->getValue()->deliveries, [], 'Empty response body should produce empty deliveries');
});

// ===========================================================================
// send() — request body shape
// ===========================================================================

test('send(): request body contains username, password, to, from, message, ref', function () {
    $fake = new FakeHttpClient(successResponse("OK:0400000001:ref1\n"));
    $provider = makeProvider($fake);

    $provider->send(entries('Test msg', [new PhoneNumber('0400000001')]), new PhoneNumber('0411234567'));

    assert_true($fake->lastRequest !== null);
    $body = $fake->lastRequest->body;
    parse_str($body, $params);
    assert_eq($params['username'], 'testuser');
    assert_eq($params['password'], 'testpass');
    assert_contains($params['to'] ?? '', '0400000001');
    assert_eq($params['from'], '0411234567');
    assert_eq($params['message'], 'Test msg');
    assert_true(isset($params['ref']) && $params['ref'] !== '', 'ref field should be present');
});

test('send(): deferred send adds "delay" field to request', function () {
    $fake = new FakeHttpClient(successResponse("OK:0400000001:ref1\n"));
    $provider = makeProvider($fake);

    $sendAt = time() + 3600; // 1 hour from now
    $provider->send(entries('Hi', [new PhoneNumber('0400000001')]), new PhoneNumber('0411111111'), $sendAt);

    parse_str($fake->lastRequest->body, $params);
    assert_true(isset($params['delay']) && (int)$params['delay'] > 0,
        'deferred send should include positive delay field');
});

test('send(): immediate send (sendAt=null) omits "delay" field', function () {
    $fake = new FakeHttpClient(successResponse("OK:0400000001:ref1\n"));
    $provider = makeProvider($fake);

    $provider->send(entries('Hi', [new PhoneNumber('0400000001')]), new PhoneNumber('0411111111'), null);

    parse_str($fake->lastRequest->body, $params);
    assert_false(isset($params['delay']), 'Immediate send should not include delay field');
});

// ===========================================================================
// getBalance() — response parsing
// ===========================================================================

test('getBalance(): "OK:100" → returns 100', function () {
    $fake = new FakeHttpClient(successResponse('OK:100'));
    $provider = makeProvider($fake);

    $result = $provider->getBalance();
    assert_true($result->isSuccess(), 'Expected success: ' . ($result->isFailure() ? $result->getError() : ''));
    assert_eq($result->getValue(), 100);
});

test('getBalance(): "OK:0" → returns 0', function () {
    $fake = new FakeHttpClient(successResponse('OK:0'));
    $provider = makeProvider($fake);

    $result = $provider->getBalance();
    assert_true($result->isSuccess());
    assert_eq($result->getValue(), 0);
});

test('getBalance(): "ERROR:Invalid username/password" → failure', function () {
    $fake = new FakeHttpClient(successResponse('ERROR:Invalid username/password'));
    $provider = makeProvider($fake);

    $result = $provider->getBalance();
    assert_true($result->isFailure(), 'Expected failure for ERROR response');
    assert_contains($result->getError(), 'Invalid username/password');
});

test('getBalance(): malformed response (no colon) → failure', function () {
    $fake = new FakeHttpClient(successResponse('malformed'));
    $provider = makeProvider($fake);

    $result = $provider->getBalance();
    assert_true($result->isFailure(), 'Malformed response should yield failure');
});

test('getBalance(): HTTP failure propagates', function () {
    $fake = new FakeHttpClient(failureResponse('Connection refused'));
    $provider = makeProvider($fake);

    $result = $provider->getBalance();
    assert_true($result->isFailure());
    assert_contains($result->getError(), 'Connection refused');
});

test('getBalance(): balance=config-override skips API call', function () {
    // When $balance is set, the provider returns it directly without making HTTP call.
    $fake = new FakeHttpClient(failureResponse('should not be called'));
    $provider = new SmsBroadcastSmsProvider(
        username: 'u',
        password: 'p',
        url: 'https://api.test.example',
        balance: '42',
        httpClient: $fake,
    );

    $result = $provider->getBalance();
    assert_true($result->isSuccess());
    assert_eq($result->getValue(), 42, 'Config balance override should be returned directly');
});

// ===========================================================================
// hasCapability() — capability surface
// ===========================================================================

test('hasCapability(): GET_BALANCE → true', function () {
    $provider = new SmsBroadcastSmsProvider(username: 'u', password: 'p');
    assert_true($provider->hasCapability(SmsCapability::GET_BALANCE));
});

test('hasCapability(): DEFERRED_SEND → true', function () {
    $provider = new SmsBroadcastSmsProvider(username: 'u', password: 'p');
    assert_true($provider->hasCapability(SmsCapability::DEFERRED_SEND));
});

test('hasCapability(): GET_SENDER_IDS → false', function () {
    $provider = new SmsBroadcastSmsProvider(username: 'u', password: 'p');
    assert_false($provider->hasCapability(SmsCapability::GET_SENDER_IDS));
});

test('hasCapability(): REGISTER_SENDER_NUMBER → false', function () {
    $provider = new SmsBroadcastSmsProvider(username: 'u', password: 'p');
    assert_false($provider->hasCapability(SmsCapability::REGISTER_SENDER_NUMBER));
});

test('hasCapability(): REGISTER_SENDER_ID → false', function () {
    $provider = new SmsBroadcastSmsProvider(username: 'u', password: 'p');
    assert_false($provider->hasCapability(SmsCapability::REGISTER_SENDER_ID));
});

test('hasCapability(): DEFERRED_SEND_CANCEL → false', function () {
    $provider = new SmsBroadcastSmsProvider(username: 'u', password: 'p');
    assert_false($provider->hasCapability(SmsCapability::DEFERRED_SEND_CANCEL));
});

// ===========================================================================
// registerSenderId() / registerSenderNumber() — unsupported → failure
// ===========================================================================

test('registerSenderId(): returns failure "Not supported"', function () {
    $provider = new SmsBroadcastSmsProvider(username: 'u', password: 'p');
    $result = $provider->registerSenderId();
    assert_true($result->isFailure(), 'registerSenderId should return failure');
    assert_eq($result->getError(), 'Not supported');
});

test('registerSenderId(): with SenderID arg also returns failure "Not supported"', function () {
    $provider = new SmsBroadcastSmsProvider(username: 'u', password: 'p');
    $result = $provider->registerSenderId(new \Sms\SenderID('MYID'), ['field' => 'value']);
    assert_true($result->isFailure());
    assert_eq($result->getError(), 'Not supported');
});

test('registerSenderNumber(): returns failure "Not supported"', function () {
    $provider = new SmsBroadcastSmsProvider(username: 'u', password: 'p');
    $result = $provider->registerSenderNumber();
    assert_true($result->isFailure(), 'registerSenderNumber should return failure');
    assert_eq($result->getError(), 'Not supported');
});

test('getSenderIds(): always returns empty array', function () {
    $provider = new SmsBroadcastSmsProvider(username: 'u', password: 'p');
    $result = $provider->getSenderIds();
    assert_true($result->isSuccess());
    assert_eq($result->getValue(), []);
});

test('getSenderNumbers(): always returns empty array', function () {
    $provider = new SmsBroadcastSmsProvider(username: 'u', password: 'p');
    $result = $provider->getSenderNumbers();
    assert_true($result->isSuccess());
    assert_eq($result->getValue(), []);
});
