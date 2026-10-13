<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

test('private uploads retain minimum permissions even under a permissive process umask', function () {
    $directory = sys_get_temp_dir().'/runtime-upload-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $originalUmask = umask(0022);
    config(['filesystems.disks.local.root' => $directory]);
    Storage::forgetDisk('local');

    try {
        expect(Storage::disk('local')->put('uploads/private.txt', 'private upload'))->toBeTrue();
        expect(fileperms($directory.'/uploads/private.txt') & 0777)->toBe(0600);
        expect(fileperms($directory.'/uploads') & 0777)->toBe(0700);
    } finally {
        umask($originalUmask);
        Storage::forgetDisk('local');
        (new Filesystem)->deleteDirectory($directory);
    }
});
