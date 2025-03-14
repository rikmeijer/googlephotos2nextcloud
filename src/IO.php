<?php

namespace Rikmeijer\Googlephotos2nextcloud;

class IO {

    static function write(string $line): void {
        print PHP_EOL . '[' . date('Y-m-d H:i:s') . '] - ' . $line;
    }

    static function readJson(string $path): mixed {
        return json_decode(file_get_contents($path), true);
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
