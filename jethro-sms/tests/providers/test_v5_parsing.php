<?php

/**
 * Unit tests for FiveCentSmsV5Provider — response parsing.
 *
 * Covers:
 *   - send() response parsing (all sent, missing recipient, extra recipient, error, invalid JSON)
 *   - send() request body shape (key-id/key-secret/sender/to/message, schedule field)
 *   - getBalance() response parsing (success, error field, missing field, invalid JSON)
 *   - getSenderIds() / parseSenderIds() via fake client:
 *       four historical JSON key shapes, plain-string vs object items,
 *       status 'approved' and 'acma_approved' → acmaApproved true,
 *       other/absent status, phone-number exclusion
 *   - getSenderNumbers() returns approved phone-number-like entries
 *
 * Pins improvements:
 *   - docs/sms/improvements/32-test-coverage.md (general parser coverage)
 *   - docs/sms/improvements/26-acma-approved-parser-mismatch.md (status mapping fix)
 */

namespace Test\Sms\V5Parsing;

use function \Test\{test, assert_true, assert_false, assert_eq, assert_contains};
use \Sms\{
    PhoneNumber, SenderID, SmsStatus,
    HttpClient, HttpRequest, HttpResponse};
use Sms\Providers\FiveCentSmsV5Provider;
use function \Sms\sendSummary;

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------
require_once __DIR__ . '/../../src/load.php';

// ---------------------------------------------------------------------------
// Test doubles
// ---------------------------------------------------------------------------

/**
 * Fake HttpClient — returns a fixed Result for every request.
 *
 * Reset between tests by constructing a fresh instance.
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

/**
 * Fake SmsCache — in-memory, no TTL, per-instance.
 *
 * A fresh instance is constructed per test so the 30-minute sender-ID cache
 * cannot leak between tests.
 */
final class FakeSmsCache implements \Sms\SmsCache
{
    /** @var array<string, mixed> */
    private array $store = [];

    public function get(string $key): mixed
    {
        return $this->store[$key] ?? null;
    }

    public function set(string $key, mixed $value, int $ttl = 0): void
    {
        $this->store[$key] = $value;
    }

    public function delete(string $key): void
    {
        unset($this->store[$key]);
    }
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeV5Provider(HttpClient $http, ?\Sms\SmsCache $cache = null): FiveCentSmsV5Provider
{
    $p = new FiveCentSmsV5Provider(
        url: 'https://api.test.example/v5',
        keyId: 'k',
        keySecret: 's',
        httpClient: $http,
        cache: $cache,
    );
    return $p;
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
// send() — response parsing
// ===========================================================================

test('send(): all recipients present in response → all deliveries with correct statuses', function () {
    $json = json_encode([
        'messages' => [
            ['destination' => '0400000001', 'status' => 1001, 'status_text' => 'Sent', 'id' => 'abc'],
            ['destination' => '0400000002', 'status' => 1002, 'status_text' => 'Delivered', 'id' => 'def'],
        ],
    ]);
    $fake = new FakeHttpClient(successResponse($json));
    $provider = makeV5Provider($fake);

    $recipients = [new PhoneNumber('0400000001'), new PhoneNumber('0400000002')];
    $result = $provider->send(entries('Hello', $recipients), new PhoneNumber('0411000000'));

    assert_true($result->isSuccess(), 'Expected success Result');
    $deliveries = $result->getValue()->deliveries;
    assert_eq(count($deliveries), 2, 'Expected 2 deliveries');
    assert_eq($deliveries[0]->status(), SmsStatus::SENT, 'First delivery should be SENT (1001)');
    assert_eq($deliveries[1]->status(), SmsStatus::DELIVERED, 'Second delivery should be DELIVERED (1002)');
    assert_eq($deliveries[0]->remoteId(), 'abc', 'First delivery should have remoteId "abc"');
    assert_eq($deliveries[1]->remoteId(), 'def', 'Second delivery should have remoteId "def"');
});

test('send(): recipient MISSING from response → that delivery has FAILED status', function () {
    $json = json_encode([
        'messages' => [
            ['destination' => '0400000001', 'status' => 1001, 'status_text' => 'Sent', 'id' => 'abc'],
            // 0400000002 is absent from the response
        ],
    ]);
    $fake = new FakeHttpClient(successResponse($json));
    $provider = makeV5Provider($fake);

    $recipients = [new PhoneNumber('0400000001'), new PhoneNumber('0400000002')];
    $result = $provider->send(entries('Hello', $recipients), new PhoneNumber('0411000000'));

    assert_true($result->isSuccess());
    $deliveries = $result->getValue()->deliveries;
    assert_eq(count($deliveries), 2, 'Both original recipients should appear in results');

    // Find the delivery for the missing number
    $missing = null;
    foreach ($deliveries as $d) {
        if ($d->recipient()->value === '0400000002') {
            $missing = $d;
        }
    }
    assert_true($missing !== null, '0400000002 should appear in results');
    assert_eq($missing->status(), SmsStatus::FAILED, 'Missing recipient should be FAILED');
});

test('send(): EXTRA destination in response (not in request) → ignored, delivery count equals request count', function () {
    $json = json_encode([
        'messages' => [
            ['destination' => '0400000001', 'status' => 1001, 'status_text' => 'Sent', 'id' => 'r1'],
            ['destination' => '0400000099', 'status' => 1001, 'status_text' => 'Sent', 'id' => 'r9'],  // not requested
        ],
    ]);
    $fake = new FakeHttpClient(successResponse($json));
    $provider = makeV5Provider($fake);

    $recipients = [new PhoneNumber('0400000001')];
    $result = $provider->send(entries('Hello', $recipients), new PhoneNumber('0411000000'));

    assert_true($result->isSuccess());
    $deliveries = $result->getValue()->deliveries;
    assert_eq(count($deliveries), 1, 'Delivery count must equal request count (extras ignored)');
    assert_eq($deliveries[0]->recipient()->value, '0400000001');
});

test('send(): top-level {"error":"..."} response → empty deliveries array, sendSummary() returns Failed', function () {
    $json = json_encode(['error' => 'Invalid credentials']);
    $fake = new FakeHttpClient(successResponse($json));
    $provider = makeV5Provider($fake);

    $recipients = [new PhoneNumber('0400000001')];
    $result = $provider->send(entries('Hello', $recipients), new PhoneNumber('0411000000'));

    assert_true($result->isSuccess(), 'send() itself should succeed (wraps empty deliveries)');
    $deliveries = $result->getValue()->deliveries;
    assert_eq($deliveries, [], 'Error response should yield empty deliveries');

    $summary = sendSummary($deliveries, $recipients);
    assert_true($summary instanceof \Sms\Failed, 'sendSummary() of empty deliveries should be Failed');
});

test('send(): invalid JSON response → empty deliveries', function () {
    $fake = new FakeHttpClient(successResponse('not valid json'));
    $provider = makeV5Provider($fake);

    $recipients = [new PhoneNumber('0400000001')];
    $result = $provider->send(entries('Hello', $recipients), new PhoneNumber('0411000000'));

    assert_true($result->isSuccess());
    assert_eq($result->getValue()->deliveries, [], 'Invalid JSON should yield empty deliveries');
});

// ===========================================================================
// send() — request body shape
// ===========================================================================

test('send(): request body contains key-id, key-secret, sender, to, message', function () {
    $fake = new FakeHttpClient(successResponse(json_encode([
        'messages' => [['destination' => '0400000001', 'status' => 1001, 'status_text' => 'Sent']],
    ])));
    $provider = makeV5Provider($fake);

    $provider->send(entries('Test message', [new PhoneNumber('0400000001')]), new PhoneNumber('0411111111'));

    assert_true($fake->lastRequest !== null, 'Expected an HTTP request');
    $body = json_decode($fake->lastRequest->body, true);
    assert_true(is_array($body), 'Request body should be valid JSON');
    assert_eq($body['key-id'], 'k', 'key-id should be present');
    assert_eq($body['key-secret'], 's', 'key-secret should be present');
    assert_eq($body['sender'], '0411111111', 'sender should be present');
    assert_contains($body['to'], '0400000001', 'to should contain recipient');
    assert_eq($body['message'], 'Test message', 'message should be present');
});

test('send(): sendAt set → "schedule" key present in request body', function () {
    $fake = new FakeHttpClient(successResponse(json_encode([
        'messages' => [['destination' => '0400000001', 'status' => 1001, 'status_text' => 'Sent']],
    ])));
    $provider = makeV5Provider($fake);

    $sendAt = time() + 3600;
    $provider->send(entries('Test', [new PhoneNumber('0400000001')]), new PhoneNumber('0411111111'), $sendAt);

    $body = json_decode($fake->lastRequest->body, true);
    assert_true(array_key_exists('schedule', $body), '"schedule" key should be present when sendAt is set');
    assert_eq($body['schedule'], $sendAt);
});

test('send(): sendAt null → "schedule" key absent from request body', function () {
    $fake = new FakeHttpClient(successResponse(json_encode([
        'messages' => [['destination' => '0400000001', 'status' => 1001, 'status_text' => 'Sent']],
    ])));
    $provider = makeV5Provider($fake);

    $provider->send(entries('Test', [new PhoneNumber('0400000001')]), new PhoneNumber('0411111111'), null);

    $body = json_decode($fake->lastRequest->body, true);
    assert_false(array_key_exists('schedule', $body), '"schedule" key must be absent when sendAt is null');
});

test('send(): multiple recipients sent as comma-separated "to" field', function () {
    $fake = new FakeHttpClient(successResponse(json_encode([
        'messages' => [
            ['destination' => '0400000001', 'status' => 1001, 'status_text' => 'Sent'],
            ['destination' => '0400000002', 'status' => 1001, 'status_text' => 'Sent'],
        ],
    ])));
    $provider = makeV5Provider($fake);

    $provider->send(entries('Hi', [new PhoneNumber('0400000001'), new PhoneNumber('0400000002')]), new PhoneNumber('0411111111'));

    $body = json_decode($fake->lastRequest->body, true);
    assert_contains($body['to'], '0400000001');
    assert_contains($body['to'], '0400000002');
    assert_contains($body['to'], ',');
});

// ===========================================================================
// getBalance() — response parsing
// ===========================================================================

test('getBalance(): {"balance":{"credits":42}} → returns 42', function () {
    $json = json_encode(['balance' => ['credits' => 42]]);
    $fake = new FakeHttpClient(successResponse($json));
    $provider = makeV5Provider($fake);

    $result = $provider->getBalance();
    assert_true($result->isSuccess(), 'Expected success: ' . ($result->isFailure() ? $result->getError() : ''));
    assert_eq($result->getValue(), 42, 'Balance should be 42');
});

test('getBalance(): {"error":"bad"} → returns failure', function () {
    $json = json_encode(['error' => 'bad']);
    $fake = new FakeHttpClient(successResponse($json));
    $provider = makeV5Provider($fake);

    $result = $provider->getBalance();
    assert_true($result->isFailure(), 'Expected failure for error response');
});

test('getBalance(): missing "credits" field → returns failure', function () {
    $json = json_encode(['balance' => ['something_else' => 99]]);
    $fake = new FakeHttpClient(successResponse($json));
    $provider = makeV5Provider($fake);

    $result = $provider->getBalance();
    assert_true($result->isFailure(), 'Missing credits field should yield failure');
});

test('getBalance(): invalid JSON → returns failure', function () {
    $fake = new FakeHttpClient(successResponse('not-json'));
    $provider = makeV5Provider($fake);

    $result = $provider->getBalance();
    assert_true($result->isFailure(), 'Invalid JSON should yield failure');
});

test('getBalance(): HTTP failure propagates', function () {
    $fake = new FakeHttpClient(failureResponse('Connection refused'));
    $provider = makeV5Provider($fake);

    $result = $provider->getBalance();
    assert_true($result->isFailure(), 'HTTP failure should propagate');
    assert_contains($result->getError(), 'Connection refused');
});

// ===========================================================================
// getSenderIds() — four historical response shapes
// ===========================================================================

test('getSenderIds(getAll:true): "senderids" key → parses plain string items', function () {
    $json = json_encode(['senderids' => ['MYCHURCH', 'MYORG']]);
    $fake = new FakeHttpClient(successResponse($json));
    $provider = makeV5Provider($fake, new FakeSmsCache());

    $result = $provider->getSenderIds(getAll: true);
    assert_true($result->isSuccess(), 'Expected success');
    $ids = $result->getValue();
    $values = array_map(fn($s) => $s->value, $ids);
    assert_true(in_array('MYCHURCH', $values, true));
    assert_true(in_array('MYORG', $values, true));
});

test('getSenderIds(getAll:true): "sender_ids" key → parses items', function () {
    $json = json_encode(['sender_ids' => ['ALPHA', 'BETA']]);
    $fake = new FakeHttpClient(successResponse($json));
    $provider = makeV5Provider($fake, new FakeSmsCache());

    $result = $provider->getSenderIds(getAll: true);
    assert_true($result->isSuccess());
    $values = array_map(fn($s) => $s->value, $result->getValue());
    assert_true(in_array('ALPHA', $values, true));
    assert_true(in_array('BETA', $values, true));
});

test('getSenderIds(getAll:true): "data" key → parses items', function () {
    $json = json_encode(['data' => ['GAMMA']]);
    $fake = new FakeHttpClient(successResponse($json));
    $provider = makeV5Provider($fake, new FakeSmsCache());

    $result = $provider->getSenderIds(getAll: true);
    assert_true($result->isSuccess());
    $values = array_map(fn($s) => $s->value, $result->getValue());
    assert_true(in_array('GAMMA', $values, true));
});

test('getSenderIds(getAll:true): "senderid" key (singular) → parses items', function () {
    $json = json_encode(['senderid' => ['DELTA']]);
    $fake = new FakeHttpClient(successResponse($json));
    $provider = makeV5Provider($fake, new FakeSmsCache());

    $result = $provider->getSenderIds(getAll: true);
    assert_true($result->isSuccess());
    $values = array_map(fn($s) => $s->value, $result->getValue());
    assert_true(in_array('DELTA', $values, true));
});

test('getSenderIds(getAll:true): objects with senderid+status "approved" → acmaApproved true', function () {
    $json = json_encode(['senderids' => [
        ['senderid' => 'APPROVED1', 'status' => 'approved'],
    ]]);
    $fake = new FakeHttpClient(successResponse($json));
    $provider = makeV5Provider($fake, new FakeSmsCache());

    $result = $provider->getSenderIds(getAll: true);
    assert_true($result->isSuccess());
    $ids = $result->getValue();
    assert_eq(count($ids), 1);
    assert_eq($ids[0]->value, 'APPROVED1');
    assert_eq($ids[0]->acmaApproved, true, '"approved" status should set acmaApproved=true');
});

test('getSenderIds(getAll:true): objects with senderid+status "acma_approved" → acmaApproved true', function () {
    $json = json_encode(['senderids' => [
        ['senderid' => 'APPROVED2', 'status' => 'acma_approved'],
    ]]);
    $fake = new FakeHttpClient(successResponse($json));
    $provider = makeV5Provider($fake, new FakeSmsCache());

    $result = $provider->getSenderIds(getAll: true);
    assert_true($result->isSuccess());
    $ids = $result->getValue();
    assert_eq(count($ids), 1);
    assert_eq($ids[0]->acmaApproved, true, '"acma_approved" status should set acmaApproved=true');
});

test('getSenderIds(getAll:true): objects with any other status → acmaApproved false', function () {
    $json = json_encode(['senderids' => [
        ['senderid' => 'PENDING', 'status' => 'pending'],
        ['senderid' => 'REJECTED', 'status' => 'rejected'],
    ]]);
    $fake = new FakeHttpClient(successResponse($json));
    $provider = makeV5Provider($fake, new FakeSmsCache());

    $result = $provider->getSenderIds(getAll: true);
    assert_true($result->isSuccess());
    $ids = $result->getValue();
    // Should be 2 items with acmaApproved false (non-phone-number items)
    assert_eq(count($ids), 2);
    assert_eq($ids[0]->acmaApproved, false, 'Other status should be acmaApproved=false');
    assert_eq($ids[1]->acmaApproved, false, 'Other status should be acmaApproved=false');
});

test('getSenderIds(getAll:true): objects with absent "status" key → acmaApproved null', function () {
    $json = json_encode(['senderids' => [
        ['senderid' => 'NOSTATUS'],
    ]]);
    $fake = new FakeHttpClient(successResponse($json));
    $provider = makeV5Provider($fake, new FakeSmsCache());

    $result = $provider->getSenderIds(getAll: true);
    assert_true($result->isSuccess());
    $ids = $result->getValue();
    assert_eq(count($ids), 1);
    assert_eq($ids[0]->acmaApproved, null, 'Absent status key should leave acmaApproved=null');
});

test('getSenderIds(getAll:false): only ACMA-approved IDs returned (filters out null and false)', function () {
    $json = json_encode(['senderids' => [
        ['senderid' => 'APPROVED', 'status' => 'approved'],
        ['senderid' => 'PENDING',  'status' => 'pending'],
        ['senderid' => 'NOSTATUS'],
    ]]);
    $fake = new FakeHttpClient(successResponse($json));
    $provider = makeV5Provider($fake, new FakeSmsCache());

    $result = $provider->getSenderIds(getAll: false);
    assert_true($result->isSuccess());
    $ids = $result->getValue();
    // getSenderIds also filters out phone numbers (digit-only ≥7 chars); these are alpha names so fine
    $values = array_map(fn($s) => $s->value, $ids);
    assert_eq(count($ids), 1, 'Only the approved item should pass the filter');
    assert_eq($values[0], 'APPROVED');
});

test('getSenderIds(getAll:true): phone-number-like entries (digit-only ≥7) excluded from getSenderIds', function () {
    $json = json_encode(['senderids' => [
        ['senderid' => '0400000001', 'status' => 'approved'],
        ['senderid' => 'MYCHURCH',   'status' => 'approved'],
    ]]);
    $fake = new FakeHttpClient(successResponse($json));
    $provider = makeV5Provider($fake, new FakeSmsCache());

    $result = $provider->getSenderIds(getAll: true);
    assert_true($result->isSuccess());
    $values = array_map(fn($s) => $s->value, $result->getValue());
    assert_false(in_array('0400000001', $values, true), 'Phone number should be excluded from getSenderIds');
    assert_true(in_array('MYCHURCH', $values, true), 'Alphabetic sender ID should be included');
});

test('getSenderIds(): invalid JSON → returns empty array (silent failure)', function () {
    $fake = new FakeHttpClient(successResponse('not-json'));
    $provider = makeV5Provider($fake, new FakeSmsCache());

    $result = $provider->getSenderIds(getAll: true);
    assert_true($result->isSuccess(), 'Invalid JSON should not throw; should return success([])');
    assert_eq($result->getValue(), []);
});

test('getSenderIds(): API error in response body → returns empty array (silent failure)', function () {
    $json = json_encode(['error' => 'Unauthorized']);
    $fake = new FakeHttpClient(successResponse($json));
    $provider = makeV5Provider($fake, new FakeSmsCache());

    $result = $provider->getSenderIds(getAll: true);
    assert_true($result->isSuccess());
    assert_eq($result->getValue(), []);
});

// ===========================================================================
// getSenderNumbers()
// ===========================================================================

test('getSenderNumbers(): approved phone-number-like entries (digit-only ≥7, approved) returned', function () {
    $json = json_encode(['senderids' => [
        ['senderid' => '61400000001', 'status' => 'approved'],
        ['senderid' => 'MYCHURCH',    'status' => 'approved'],
        ['senderid' => '61400000002', 'status' => 'pending'],   // not approved
        ['senderid' => '12345',       'status' => 'approved'],  // too short (5 digits)
    ]]);
    $fake = new FakeHttpClient(successResponse($json));
    $provider = makeV5Provider($fake, new FakeSmsCache());

    $result = $provider->getSenderNumbers();
    assert_true($result->isSuccess());
    $numbers = $result->getValue();
    assert_true(count($numbers) === 1, 'Only one entry should pass all filters');
    assert_true($numbers[0] instanceof \Sms\PhoneNumber, 'getSenderNumbers() must return PhoneNumber instances, not SenderID');
    $values = array_map(fn($s) => $s->value, $numbers);
    assert_true(in_array('61400000001', $values, true), 'Approved long phone number should appear in getSenderNumbers');
    assert_false(in_array('MYCHURCH', $values, true), 'Alpha ID should not appear in getSenderNumbers');
    assert_false(in_array('61400000002', $values, true), 'Non-approved number should not appear');
    assert_false(in_array('12345', $values, true), 'Short number (< 7 digits) should not appear');
});

// ===========================================================================
// Cache behaviour
// ===========================================================================

test('getSenderIds(): cache=null works (no caching, always hits API)', function () {
    $json = json_encode(['senderids' => ['ALPHA']]);
    $fake = new FakeHttpClient(successResponse($json));
    // Pass no cache — should not throw, should hit API every time
    $provider = makeV5Provider($fake, null);

    $r1 = $provider->getSenderIds(getAll: true);
    $r2 = $provider->getSenderIds(getAll: true);
    assert_true($r1->isSuccess());
    assert_true($r2->isSuccess());
    // Two calls should have been made (no cache to short-circuit)
    // We can't directly count calls with a single-response fake, but at minimum
    // both results must be success with the value
    assert_eq(count($r1->getValue()), 1);
    assert_eq(count($r2->getValue()), 1);
});
