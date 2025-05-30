<?php

namespace Rikmeijer\Googlephotos2nextcloud;

class IO {

    static function write(string $line): void {
        print PHP_EOL . '[' . date('Y-m-d H:i:s') . '] - ' . $line;
    }

    static function readJson(string $path): mixed {
        return json_decode(file_get_contents($path), true);
    }

    static function progressPath(string $photo_path): string {
        $album_path = dirname($photo_path);
        $progress_path = dirname($album_path) . DIRECTORY_SEPARATOR . '.progress';
        is_dir($progress_path) || mkdir($progress_path);
        return $progress_path . DIRECTORY_SEPARATOR . md5_file($photo_path);
    }

    static function checkProgress(string $photo_path): ?array {
        $progress_directory = dirname($photo_path) . '/.progress';
        if (is_dir($progress_directory)) {
            $photo_filename = basename($photo_path);
            $progress_filename = $progress_directory . DIRECTORY_SEPARATOR . $photo_filename . '.txt';
            if (is_file($progress_filename)) {
                self::updateProgress($photo_path, file_get_contents($progress_filename), null);
                unlink($progress_filename);
                self::write('[' . $photo_filename . '] - Old progress file, moved to global progress directory.');
            }

            if (count(glob($progress_directory . '/*.txt')) === 0) {
                rmdir($progress_directory);
                self::write('Removing old progress directory');
            }
        }

        $progress_filename = self::progressPath($photo_path);
        if (is_file($progress_filename . '.txt')) {
            return [file_get_contents($progress_filename . '.txt'), []];
        } elseif (is_file($progress_filename . '.json')) {
            return json_decode(file_get_contents($progress_filename . '.json'), true);
        }
        return null;
    }

    static function updateProgress(string $photo_path, string $photo_remote_path, ?string $album): void {
        $progress_filename = self::progressPath($photo_path);

        $progress = self::checkProgress($photo_path);
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
