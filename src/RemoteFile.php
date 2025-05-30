<?php


namespace Rikmeijer\Googlephotos2nextcloud;

class RemoteFile {

    static function upload(callable $attempt, string $source, string $target): bool {
        $local_size = filesize($source);

        $response = $attempt('request', 'PUT', $target, fopen($source, 'r+'), [
            'X-OC-MTime' => filemtime($source),
            'X-OC-CTime' => Metadata::takenTime($source)->getTimestamp(),
            'OC-Total-Length' => $local_size
        ]);

        if ($response['statusCode'] < 200 || $response['statusCode'] > 399) {
            throw new \Exception('Upload failed, invalid response code (' . $response['statusCode'] . ') received');
        }

        $file_remote_head_check = $attempt('request', 'HEAD', $target);

        if ($file_remote_head_check['statusCode'] !== 200) {
            throw new \Exception('Could not validate remote file, invalid response code (' . $response['statusCode'] . ') received');
        } elseif (isset($file_remote_head_check['headers']['content-length']) === false) {
            throw new \Exception('Could not validate remote file, missing content-length headers');
        } elseif ($local_size !== (int) $file_remote_head_check['headers']['content-length'][0] ?? 0) {
            throw new \Exception('Could not validate remote file, differ from local size');
        }

        return true;
    }
}
