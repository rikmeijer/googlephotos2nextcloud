<?php

namespace Rikmeijer\Googlephotos2nextcloud;

class IO {

    static function write(string $line): void {
        print PHP_EOL . '[' . date('Y-m-d H:i:s') . '] - ' . $line;
    }

    static function readJson(string $path): mixed {
        return json_decode(file_get_contents($path), true);
    }

    static function progressPath(string $album_path, string $md5_fingerprint): string {
        $progress_path = dirname($album_path) . DIRECTORY_SEPARATOR . '.progress';
        is_dir($progress_path) || mkdir($progress_path);
        return $progress_path . DIRECTORY_SEPARATOR . $md5_fingerprint;
    }

    static function checkProgress(string $album_path, string $md5_fingerprint): ?array {
        $progress_filename = self::progressPath($album_path, $md5_fingerprint);
        if (is_file($progress_filename . '.txt')) {
            return [file_get_contents($progress_filename . '.txt'), []];
        } elseif (is_file($progress_filename . '.json')) {
            return json_decode(file_get_contents($progress_filename . '.json'), true);
        }
        return null;
    }

    static function updateProgress(string $album_path, string $md5_fingerprint, string $photo_remote_path, ?string $album): void {
        $progress_filename = self::progressPath($album_path, $md5_fingerprint);

        $progress = self::checkProgress($base_path, $md5_fingerprint);
        if ($progress === null) {
            $albums = [];
        } else {
            $albums = $progress[1];
        }

        if ($album !== null) {
            $albums[] = $album;
        }

        if (is_file($progress_filename . '.txt')) {
            unlink($progress_filename . '.txt');
        }

        file_put_contents($progress_filename . '.json', json_encode([$photo_remote_path, array_unique($albums)]));
    }

    static function mkdir(\Sabre\DAV\Client $client, string $remote_base, string $remote_path): bool {
        $directory_remote_head = $client->request('HEAD', $remote_base . $remote_path);
        if ($directory_remote_head['statusCode'] !== 404) {
            IO::write('Directory "' . $remote_path . '" already exists remotely');
            return true;
        }

        $response = $client->request('MKCOL', $remote_base . $remote_path);
        if ($response['statusCode'] < 200 || $response['statusCode'] > 399) {
            IO::write('Failed creating "' . $remote_path . '" remotely "' . $response['statusCode'] . '"');
            return false;
        }
        IO::write('Directory "' . $remote_path . '" created remotely');
        return true;
    }

    static function createDirectory(\Sabre\DAV\Client $client, string $remote_base, string $remote_path): string|bool {
        static $cache = [];
        if (isset($cache[$remote_base . $remote_path])) {
            return $cache[$remote_base . $remote_path] ? $remote_base . $remote_path : false;
        }

        $creating = '';
        foreach (explode('/', ltrim($remote_path, '/')) as $remote_path_part) {
            $creating .= '/' . $remote_path_part;
            if (self::mkdir($client, $remote_base, $creating) === false) {
                $cache[$remote_base . $creating] = false;
                return false;
            }
        }
        $cache[$remote_base . $remote_path] = true;
        return $remote_base . $remote_path;
    }
}
