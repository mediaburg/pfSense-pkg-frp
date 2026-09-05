<?php
/* Check the actual archive without installing it or executing package hooks. */
declare(strict_types=1);
if ($argc !== 4) {
    fwrite(STDERR, "Usage: php verify-package.php archive.pkg version ABI\n");
    exit(1);
}
[$script, $archive, $version, $abi] = $argv;
$root = dirname(__DIR__);
function archive_file(string $name): string {
    global $archive;
    $process = proc_open(['tar', '-xOf', $archive, $name], [1 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Cannot read package'); }
    $data = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    if (proc_close($process) !== 0 || $data === false) { throw new RuntimeException("Missing archive file: $name"); }
    return $data;
}
function require_valid(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
try {
    $manifest = json_decode(archive_file('+MANIFEST'), true, 512, JSON_THROW_ON_ERROR);
    foreach (['name' => 'pfSense-pkg-frp', 'origin' => 'net/pfSense-pkg-frp', 'version' => $version, 'abi' => $abi, 'prefix' => '/usr/local'] as $key => $expected) {
        require_valid(($manifest[$key] ?? null) === $expected, "Unexpected package $key");
    }
    require_valid(($manifest['deps']['frp']['origin'] ?? '') === 'net/frp', 'Missing FRP dependency');
    require_valid(version_compare(explode('_', $manifest['deps']['frp']['version'])[0], '0.52.0', '>='), 'FRP dependency too old');
    require_valid(in_array('APACHE20', $manifest['licenses'] ?? [], true), 'Missing package license');
    require_valid(empty($manifest['shlibs_required']), 'Unexpected native shared-library dependency');
    // NO_ARCH packages contain interpreted code/assets and need no build-kernel floor.
    require_valid(!isset($manifest['annotations']['FreeBSD_version']), 'Unexpected kernel-specific annotation on NO_ARCH package');
    foreach (['install' => 'pkg-install.in', 'deinstall' => 'pkg-deinstall.in'] as $hook => $source) {
        $expected = str_replace('%%PORTNAME%%', 'pfSense-pkg-frp', file_get_contents("$root/files/$source"));
        require_valid(rtrim($manifest['scripts'][$hook] ?? '', "\n") === rtrim($expected, "\n"), "Incorrect $hook hook");
    }
    $expectedFiles = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/files", FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile() || in_array($file->getFilename(), ['pkg-install.in', 'pkg-deinstall.in'], true)) { continue; }
        $path = substr($file->getPathname(), strlen("$root/files"));
        $expectedFiles[] = $path;
        require_valid(isset($manifest['files'][$path]), "Not in manifest: $path");
        $source = str_replace('%%PKGVERSION%%', $version, file_get_contents($file->getPathname()));
        require_valid(!str_starts_with($source, "\x7fELF"), "Native code cannot be packaged as NO_ARCH: $path");
        require_valid(archive_file($path) === $source, "Archive differs from source: $path");
    }
    $extra = array_diff(array_keys($manifest['files']), $expectedFiles);
    foreach ($extra as $path) {
        require_valid(str_starts_with($path, "/usr/local/share/licenses/pfSense-pkg-frp-$version/"), "Unexpected packaged file: $path");
    }
    printf("PASS: package version, ABI, dependencies, hooks, license and %d source files\n", count($expectedFiles));
} catch (Throwable $error) {
    fwrite(STDERR, 'Package verification failed: ' . $error->getMessage() . "\n");
    exit(1);
}
