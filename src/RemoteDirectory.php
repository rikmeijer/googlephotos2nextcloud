<?php

namespace Rikmeijer\Googlephotos2nextcloud;

class RemoteDirectory {

    static function mkdir(callable $attempt, string $remote_base, string $remote_path): bool {
        $directory_remote_head = $attempt('request', 'HEAD', $remote_base . $remote_path);
        if ($directory_remote_head['statusCode'] !== 404) {
            IO::write('Directory "' . $remote_path . '" already exists remotely');
            return true;
        }

        $response = $attempt('request', 'MKCOL', $remote_base . $remote_path);
        if ($response['statusCode'] < 200 || $response['statusCode'] > 399) {
            return false;
        }
        IO::write('Directory "' . $remote_path . '" created remotely');
        return true;
    }

    /**
     *
     * @staticvar array $cache
     * @param callable $attempt
     * @param string $remote_base
     * @param string $remote_path
     * @return string
     * @throws \Exception
     */
    static function create(callable $attempt, string $remote_base, string $remote_path): string {
        static $cache = [];
        if (isset($cache[$remote_base . $remote_path])) {
            return $cache[$remote_base . $remote_path] ? $remote_base . $remote_path : false;
        }

        $creating = '';
        foreach (explode('/', ltrim($remote_path, '/')) as $remote_path_part) {
            $creating .= '/' . $remote_path_part;
            if (self::mkdir($attempt, $remote_base, $creating) === false) {
                throw new \Exception('Failed creating "' . $remote_path . '" remotely "' . $response['statusCode'] . '"');
            }
        }
        $cache[$remote_base . $remote_path] = true;
        return $remote_base . $remote_path;
    }
}
