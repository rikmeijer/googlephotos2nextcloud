<?php

namespace Rikmeijer\Googlephotos2nextcloud;

use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use Amp\Cancellation;

readonly class DirectoryTask implements Task {

    const EXIF_DATE_SOURCES = [
        'exif:DateTimeOriginal',
        'exif:DateTime',
        'exif:DateTimeDigitized'
    ];
    const EXIF_FORMATS = [
        'Y-m-d_G:i:s' => '/\d+\-\d+\-\d+_\d+:\d+:\d+/',
        'Y:m:d G:i:s' => '/\d+\:\d+\:\d+ \d+:\d+:\d+/'
    ];

    public function __construct(
            private string $path,
            private string $files_base_path,
            private string $albums_base_path,
            private bool $is_album,
            private string $nextcloud_url,
            private string $nextcloud_user,
            private string $nextcloud_password
    ) {

    }

    static function getTakenTimeFromMetaData(string $photo_path, callable $debug): \DateTimeImmutable {
        list($ext, $basename) = array_map('strrev', explode('.', strrev($photo_path), 2));

        $options = glob($photo_path . '*.json');
        if (preg_match('/([^\(]+)(\(\d+\))$/', $basename, $matches) > 0) {
            $original_filename = rtrim($matches[1]) . '.' . $ext;
            if (file_exists($original_filename) === false) {
                $debug('Possible duplicate of `' . basename($original_filename) . '` in filename, but original file missing');
            } elseif (filesize($photo_path) === filesize($original_filename)) {
                $debug('Duplicate of `' . basename($original_filename) . '`, try to find additional metadata files: ' . $original_filename . '.*' . $matches[2] . '.json');
                $options = array_merge($options, glob($matches[1] . '.' . $ext . '*' . $matches[2] . '.json'));
            } else {
                $debug('Possible duplicate of `' . basename($original_filename) . '` in filename, but filesize mismatch');
                $options = glob($matches[1] . '.' . $ext . '*' . $matches[2] . '.json');
            }
        }

        if (count($options) === 0) {
            $debug('No metadata files found');
        } else {
            foreach ($options as $option) {
                if (is_file($option) === false) {
                    continue;
                }

                $photo_metadata = IO::readJson($option);
                if (isset($photo_metadata['photoTakenTime'])) {
                    $debug('Found `photoTakenTime` in metadata');
                    $photo_takentime = $photo_metadata['photoTakenTime']['timestamp'];
                } elseif (isset($photo_metadata['creationTime'])) {
                    $debug('Found `creationTime` in metadata');
                    $photo_takentime = $photo_metadata['creationTime']['timestamp'];
                } else {
                    $debug('Found no datetime in metadata');
                    continue;
                }

                return new \DateTimeImmutable('@' . $photo_takentime);
            }
        }

        $image = new \Imagick($photo_path);
        $exif = $image->getImageProperties("exif:DateTime*");
        foreach (self::EXIF_DATE_SOURCES as $exif_date_source) {
            if (isset($exif[$exif_date_source]) === false) {
                continue;
            }

            $exif_datetime = $exif[$exif_date_source];
            foreach (self::EXIF_FORMATS as $exif_format => $exif_regex) {
                if (preg_match($exif_regex, $exif_datetime) === 1) {
                    $debug('Found `' . $exif_datetime . '` in ' . $exif_date_source . '');
                    return \DateTimeImmutable::createFromFormat($exif_format, $exif_datetime);
                }
            }
        }


        $debug('Found no datetime in exif data nor metadata, falling back to filemtime');
        return new \DateTimeImmutable('@' . filemtime($photo_path));
    }

    static function storeException(string $path, \Exception $e): string {
        file_put_contents($path . '/gp2nc-error.log', join(PHP_EOL, [
            'Failed reading metadata: ' . $e->getMessage(),
            $e->getFile() . '@' . $e->getLine(),
            $e->getTraceAsString()
        ]));
        return 'failed: ' . $e->getMessage();
    }

    static function attempt(callable $debug, \Sabre\DAV\Client $client, string $method, mixed ...$args): mixed {
        $attempts = 0;
        do {
            $attempts++;
            $debug('attempt #' . $attempts . ' to ' . $method);
            try {
                return $client->$method(...$args);
            } catch (Sabre\HTTP\ClientException $e) {
                if ($attempts === 5) {
                    throw $e;
                } else {
                    $debug('attempt failed, retrying in 10 seconds...');
                    sleep(10);
                }
            }
        } while ($attempts < 6);
    }

    #[\Override]
    public function run(Channel $channel, Cancellation $cancellation): string {
        if (is_dir($this->path . '/.migrated')) {
            return 'already migrated';
        } elseif (is_file($this->path . '/gp2nc-error.log')) {
            return 'skip to prevent recurring crashes, resolve errors first and delete gp2nc-error.log';
        }

        $client = new \Sabre\DAV\Client([
            'baseUri' => $this->nextcloud_url . '/remote.php/dav',
            'userName' => $this->nextcloud_user,
            'password' => $this->nextcloud_password,
            'authType' => \Sabre\DAV\Client::AUTH_BASIC
        ]);
        $directory_debug = fn(string $message) => IO::write($message);
        $attempt = fn(string $method, mixed ...$args) => self::attempt($directory_debug, $client, $method, ...$args);

        $directory_name = basename($this->path);
        $files = array_filter(glob($this->path . '/*'), 'is_file');
        IO::write('Found "' . $directory_name . '", containing ' . count($files) . ' files');

        if ($this->is_album) {
            $album_path = $this->albums_base_path . '/' . rawurlencode($directory_name);
            $album_photos = $attempt('propFind', $album_path, [], 1);
        }

        $json_files = array_filter($files, fn(string $p) => str_ends_with($p, '.json'));
        $metadata_jsons = array_filter($json_files, fn(string $p) => str_ends_with($p, 'metadata.json'));
        $photo_files = array_filter($files, fn(string $p) => str_ends_with($p, '.json') === false);

        $no_photos = count($photo_files);
        if (count($metadata_jsons) === 0) {
            IO::write('No metadata found');
        }

        IO::write('Found ' . $no_photos . ' photo files');
        $progress_directory = $this->path . '/.progress';

        $read_progress = fn(string $md5_fingerprint) => IO::checkProgress(dirname($this->path), $md5_fingerprint);
        $write_progress = fn(string $md5_fingerprint, string $photo_remote_path, ?string $album) => IO::updateProgress(dirname($this->path), $md5_fingerprint, $photo_remote_path, $album);

        try {
            foreach (array_values($photo_files) as $photo_index => $photo_path) {
                $fingerprint = md5_file($photo_path);
                $photo_filename = basename($photo_path);
                $debug = fn(string $message) => $directory_debug('[' . $photo_filename . '] - ' . $message);

                $debug('Photo ' . $photo_index . ' of ' . $no_photos);

                if (is_dir($progress_directory)) {
                    $progress_filename = $progress_directory . DIRECTORY_SEPARATOR . $photo_filename . '.txt';
                    if (is_file($progress_filename)) {
                        $write_progress($fingerprint, file_get_contents($progress_filename));
                        unlink($progress_filename);
                        $debug('Old progress file, moved to global progress directory.');
                    }
                }

                $progress = $read_progress($fingerprint);
                if ($progress !== null) {
                    $photo_remote_path = $progress[0];
                    $photo_remote_filename = basename($photo_remote_path);
                    $debug('Already uploaded as ' . $photo_remote_path . ' - ' . $fingerprint);

                    if (in_array($directory_name, $progress[1])) {
                        $debug('Already added to album "' . $directory_name . '"');
                        continue;
                    }
                } else {

                    $photo_taken = self::getTakenTimeFromMetaData($photo_path, $debug);

                    $debug('Photo or video taken @ ' . $photo_taken->format('Y-m-d H:i:s'));

                    $directory_remote_path = IO::createDirectory($client, $this->files_base_path, $photo_taken->format('/Y/m'));
                    if ($directory_remote_path === false) {
                        continue;
                    }

                    $photo_remote_filename = rawurlencode($photo_filename);
                    $upload = true;
                    $file_id = null;
                    $local_size = filesize($photo_path);
                    try {

                        $file_remote_props = $attempt('propFind', $directory_remote_path . '/' . $photo_remote_filename, ['{http://owncloud.org/ns}fileid', '{http://owncloud.org/ns}size']);
                        if (count($file_remote_props) > 0) {
                            $remote_size = (int) $file_remote_props['{http://owncloud.org/ns}size'] ?? null;
                            if ($remote_size === 0) {
                                
                            } elseif ($local_size === $remote_size) {
                                $upload = false;
                            } else {
                                $debug('Rename remote target, because existing remote file has same name but different, non-zero filesize (so possibly a different photo)');
                                $photo_remote_filename = uniqid() . '-' . $photo_remote_filename;
                            }
                            $file_id = $file_remote_props['{http://owncloud.org/ns}fileid'];
                        }
                    } catch (\Sabre\HTTP\ClientHttpException $exception) {
                        $upload = $exception->getHttpStatus() === 404;
                    }

                    $photo_remote_path = $directory_remote_path . '/' . $photo_remote_filename;

                    if ($upload === false) {
                        $debug('Remote file already exists and same file size, skipping');
                    } else {
                        $debug('Uploading to "' . str_replace($this->files_base_path, '', $photo_remote_path) . '"');

                        $response = $attempt('request', 'PUT', $photo_remote_path, fopen($photo_path, 'r+'), [
                            'X-OC-MTime' => filemtime($photo_path),
                            'X-OC-CTime' => $photo_taken->getTimestamp(),
                            'OC-Total-Length' => $local_size
                        ]);

                        if ($response['statusCode'] < 200 || $response['statusCode'] > 399) {
                            $debug('Failed');
                            continue;
                        }

                        $file_remote_head_check = $attempt('request', 'HEAD', $photo_remote_path);

                        if ($file_remote_head_check['statusCode'] !== 200) {
                            $debug('Failed');
                            continue;
                        } elseif (isset($file_remote_head_check['headers']['content-length']) === false) {
                            $debug('Failed');
                            continue;
                        } elseif (filesize($photo_path) !== (int) $file_remote_head_check['headers']['content-length'][0] ?? 0) {
                            $debug('Failed');
                            continue;
                        } else {
                            $debug('Succesfully uploaded');
                        }
                    }

                    $write_progress($fingerprint, $photo_remote_path, null);
                }

                if (isset($album_path) === false) {
                    continue;
                }

                $debug('Photo must be in album ' . $album_path);
                if (isset($file_id, $album_photos[$album_path . '/' . $file_id . '-' . $photo_remote_filename])) {
                    $debug('Already in album "' . $directory_name . '"');
                } else {
                    $debug('Copying to album "' . $directory_name . '"');
                    $attempt('request', 'COPY', $photo_remote_path, headers: [
                        'Destination' => $album_path . '/' . $photo_remote_filename
                    ]);
                    $write_progress($fingerprint, $photo_remote_path, $directory_name);
                }
            }
        } catch (\Exception $e) {
            return self::storeException($this->path, $e);
        }

        if (count(glob($progress_directory . '/*.txt')) === 0) {
            rmdir($progress_directory);
            $debug('Removing old progress directory');
        }

        mkdir($this->path . '/.migrated');
        return 'done';
    }
}
