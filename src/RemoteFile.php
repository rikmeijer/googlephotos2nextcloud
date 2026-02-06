<?php


namespace Rikmeijer\Googlephotos2nextcloud;

class RemoteFile {

    static function upload(callable $attempt, string $source, string $target): bool {
        $local_size = filesize($source);
        
        // Use streaming with rewind fix
        $file_handle = fopen($source, 'rb');
        if ($file_handle === false) {
            throw new \Exception('Could not open source file');
        }
        
        // Ensure we're at the beginning of the file
        rewind($file_handle);

        $response = $attempt('request', 'PUT', $target, $file_handle, [
            'X-OC-MTime' => filemtime($source),
            'X-OC-CTime' => Metadata::takenTime($source)->getTimestamp(),
            'OC-Total-Length' => $local_size
        ]);
        
        fclose($file_handle);

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

    static function move(callable $attempt): callable {
        return function (string $file_path, string $destination) use ($attempt): bool {
            $result = $attempt('request', 'MOVE', $file_path, headers: [
                'Destination' => $destination,
                'Overwrite' => 'F'
            ]);

            switch ($result['statusCode']) {
                case 409:
                    IO::write('destination is missing');
                    $result = $attempt('request', 'MKCOL', dirname($destination));
                    $move = self::move($attempt);
                    return $result['statusCode'] === 201 ? $move($file_path, $destination) : false;

                case 412:
                    IO::write('destination already exists');
                    return false;

                case 415:
                    IO::write('destination is not a collection');
                    return false;

                case 201:
                    return true;

                default:
                    IO::write($result['statusCode']);
                    return false;
            }
        };
    }

    static function existsTest(callable $attempt, string $directory, array $media_properties): callable {
        return fn(string $available_filename) => $attempt('propfind', $directory . '/' . urlencode($available_filename), $media_properties);
    }

    static function findAvailable(callable $test, string $orig_filename) {
        $lastdotpos = strrpos($orig_filename, '.');
        $filename = substr($orig_filename, 0, $lastdotpos);
        $extension = substr($orig_filename, $lastdotpos + 1);

        if (preg_match('/\(\d+\)$/', $filename, $increment_counter_match) === 1) {
            $filename = substr($filename, 0, 0 - strlen($increment_counter_match[0]));
        }

        $available_filename = $filename . '.' . $extension;
        $tries = 0;
        do {
            try {
                IO::write('Trying ' . $available_filename);
                $existing_file = $test($available_filename);
                $available_filename = $filename . '(' . ++$tries . ').' . $extension;
            } catch (Sabre\HTTP\ClientHttpException $e) {
                $existing_file = null;
            }
        } while (isset($existing_file));

        return $available_filename;
    }
}
