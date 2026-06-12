<?php

use App\Support\DmsAttachBlobFileWriter;

it('writes blob content to a uuid-only file atomically', function () {
    $basePath = sys_get_temp_dir().'/dms_attach_test_'.uniqid();
    config(['filesystems.blob_migration_base_path' => $basePath]);

    $writer = new DmsAttachBlobFileWriter;
    $uuid = '550e8400-e29b-41d4-a716-446655440000';
    $content = '%PDF-1.4 test content';

    $result = $writer->write($uuid, $content);

    expect($result->path())->toBe($basePath.DIRECTORY_SEPARATOR.$uuid)
        ->and($result->alreadyExists())->toBeFalse()
        ->and($result->size())->toBe(strlen($content))
        ->and($result->sha256())->toBe(hash('sha256', $content))
        ->and(file_get_contents($result->path()))->toBe($content);

    $secondResult = $writer->write($uuid, 'different content');

    expect($secondResult->alreadyExists())->toBeTrue()
        ->and($secondResult->sha256())->toBe(hash('sha256', $content));

    @unlink($result->path());
    @rmdir($basePath);
});

it('resolves deterministic paths from uuid', function () {
    config(['filesystems.blob_migration_base_path' => '/var/migrations']);

    $writer = new DmsAttachBlobFileWriter;

    expect($writer->resolvePath('abc-123'))->toBe('/var/migrations'.DIRECTORY_SEPARATOR.'abc-123');
});
