<?php

namespace Rikmeijer\Googlephotos2nextcloud;

class Progress {

    static function path(string $photo_path): string {
        $album_path = dirname($photo_path);
        $progress_path = dirname($album_path) . DIRECTORY_SEPARATOR . '.progress';
        is_dir($progress_path) || mkdir($progress_path);
        return $progress_path . DIRECTORY_SEPARATOR . md5_file($photo_path);
    }

    static function check(string $photo_path): ?array {
        $progress_directory = dirname($photo_path) . '/.progress';
        if (is_dir($progress_directory)) {
            $photo_filename = basename($photo_path);
            $progress_filename = $progress_directory . DIRECTORY_SEPARATOR . $photo_filename . '.txt';
            if (is_file($progress_filename)) {
                self::update($photo_path, file_get_contents($progress_filename), null);
                unlink($progress_filename);
                IO::write('[' . $photo_filename . '] - Old progress file, moved to global progress directory.');
            }

            if (count(glob($progress_directory . '/*.txt')) === 0) {
                rmdir($progress_directory);
                IO::write('Removing old progress directory');
            }
        }

        $progress_filename = self::path($photo_path);
        if (is_file($progress_filename . '.txt')) {
            return [file_get_contents($progress_filename . '.txt'), []];
        } elseif (is_file($progress_filename . '.json')) {
            return json_decode(file_get_contents($progress_filename . '.json'), true);
        }
        return null;
    }

    static function update(string $photo_path, string $photo_remote_path, ?string $album): void {
        $progress_filename = self::path($photo_path);

        $progress = self::check($photo_path);
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
}
