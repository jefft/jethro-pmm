<?php

/**
 * Unit tests for filesystem attachment helpers and value merging.
 * These avoid the database; staged-upload, metadata and file-cleanup
 * integration is not covered by an automated test.
 */

if (!defined('JETHRO_ROOT')) define('JETHRO_ROOT', dirname(__DIR__, 2));
require_once __DIR__ . '/../../include/general.php';
require_once __DIR__ . '/../../include/attachments_manager.class.php';
require_once __DIR__ . '/../../db_objects/custom_field.class.php';

use function Test\assert_true;
use function Test\assert_false;
use function Test\assert_eq;
use function Test\assert_contains;
use function Test\test;

test('attachment filename sanitizer removes path components', function () {
    assert_eq(Attachments_Manager::sanitizeFileName('../../etc/passwd.pdf'), 'passwd.pdf');
});

test('attachment filename sanitizer strips leading dots and control characters', function () {
    assert_eq(Attachments_Manager::sanitizeFileName(".hidden\x01 report.pdf"), 'hidden report.pdf');
});

test('attachment filename sanitizer keeps unicode letters', function () {
    assert_eq(Attachments_Manager::sanitizeFileName('Baptism – José.pdf'), 'Baptism – José.pdf');
});

test('attachment extensions parse commas, dots, whitespace and reject junk', function () {
    assert_eq(
        Attachments_Manager::getAllowedExtensions(Array('allowed_extensions' => 'PDF, .docx, bad!')),
        Array('pdf', 'docx')
    );
});

test('attachment extensions fall back to the default list', function () {
    $exts = Attachments_Manager::getAllowedExtensions(Array());
    assert_true(in_array('pdf', $exts, TRUE));
    assert_true(in_array('docx', $exts, TRUE));
});

test('attachment storage tokens accept random hex plus a short extension', function () {
    assert_true(Attachments_Manager::isValidToken(str_repeat('a', 32).'.pdf'));
    assert_true(Attachments_Manager::isValidToken(str_repeat('0123456789abcdef', 2)));
});

test('attachment storage tokens reject traversal and uppercase values', function () {
    assert_false(Attachments_Manager::isValidToken('../'.str_repeat('a', 32)));
    assert_false(Attachments_Manager::isValidToken(strtoupper(str_repeat('a', 32))));
    assert_false(Attachments_Manager::isValidToken(str_repeat('a', 31)));
});

test('attachment paths are built below the configured root', function () {
    $token = str_repeat('b', 32).'.pdf';
    $path = Attachments_Manager::getPath(12, 34, $token);
    assert_eq($path, Attachments_Manager::getRootPath().'/person/12/field/34/'.$token);
});

test('attachment paths reject invalid ids and tokens', function () {
    assert_eq(Attachments_Manager::getPath(0, 34, str_repeat('b', 32)), NULL);
    assert_eq(Attachments_Manager::getPath(12, 0, str_repeat('b', 32)), NULL);
    assert_eq(Attachments_Manager::getPath(12, 34, '../bad'), NULL);
});

test('attachment max size is positive and no larger than the default', function () {
    $max = Attachments_Manager::getMaxBytes(Array());
    assert_true($max > 0);
    assert_true($max <= Attachments_Manager::DEFAULT_MAX_MB * 1024 * 1024);
    assert_contains(Attachments_Manager::getMaxSizeLabel(Array()), 'MB');
});

test('attachment accept attribute contains dot-prefixed extensions', function () {
    $accept = Attachments_Manager::getAcceptAttribute(Array('allowed_extensions' => 'pdf,docx'));
    assert_eq($accept, '.pdf,.docx');
});


test('a single-value attachment field keeps old files until a replacement is uploaded', function () {
    assert_eq(Custom_Field::mergeAttachmentValues(Array('1','2'), Array(), FALSE), Array('1','2'));
    assert_eq(Custom_Field::mergeAttachmentValues(Array('1','2'), Array('new:a'), FALSE), Array('new:a'));
});

test('a multi-value attachment field keeps old files and appends uploads', function () {
    assert_eq(Custom_Field::mergeAttachmentValues(Array('1'), Array('new:a','new:b'), TRUE), Array('1','new:a','new:b'));
});
