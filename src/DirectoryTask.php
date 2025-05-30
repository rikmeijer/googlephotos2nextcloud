<?php

namespace Rikmeijer\Googlephotos2nextcloud;

use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use Amp\Cancellation;

readonly class DirectoryTask implements Task {

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

    static function storeException(string $path, \Exception $e): string {
        file_put_contents($path . '/gp2nc-error.log', join(PHP_EOL, [
            'Failed reading metadata: ' . $e->getMessage(),
            $e->getFile() . '@' . $e->getLine(),
            $e->getTraceAsString()
        ]));
        return 'failed: ' . $e->getMessage();
    }

    #[\Override]
    public function run(Channel $channel, Cancellation $cancellation): string {
        if (is_dir($this->path . '/.migrated')) {
            return 'already migrated';
        } elseif (is_file($this->path . '/gp2nc-error.log')) {
            return 'skip to prevent recurring crashes, resolve errors first and delete gp2nc-error.log';
        }

        $attempt = new Attempt(new \Sabre\DAV\Client([
                    'baseUri' => $this->nextcloud_url . '/remote.php/dav',
                    'userName' => $this->nextcloud_user,
                    'password' => $this->nextcloud_password,
                    'authType' => \Sabre\DAV\Client::AUTH_BASIC
        ]));

        $directory_name = basename($this->path);
        $files = array_filter(glob($this->path . '/*'), 'is_file');
        IO::write('Found "' . $directory_name . '", containing ' . count($files) . ' files');

        if ($this->is_album) {
            $album_path = $this->albums_base_path . '/' . rawurlencode($directory_name);
            $album_photos = $attempt('propFind', $album_path, [], 1);
            IO::write('Found album "' . $directory_name . '", containing ' . count($album_photos) . ' photos');
        }

        $json_files = array_filter($files, fn(string $p) => str_ends_with($p, '.json'));
        $metadata_jsons = array_filter($json_files, fn(string $p) => str_ends_with($p, 'metadata.json'));
        $photo_files = array_filter($files, fn(string $p) => str_ends_with($p, '.json') === false);

        $no_photos = count($photo_files);
        if (count($metadata_jsons) === 0) {
            IO::write('No metadata found');
        }

        IO::write('Found ' . $no_photos . ' photo files');
        try {
            foreach (array_values($photo_files) as $photo_index => $photo_path) {
                $photo_filename = basename($photo_path);
                $debug = fn(string $message) => IO::write('[' . $photo_filename . '] - ' . $message);

                $debug('Photo ' . $photo_index . ' of ' . $no_photos);

                $progress = Progress::check($photo_path);
                if ($progress !== null) {
                    $photo_remote_path = $progress[0];
                    $photo_remote_filename = basename($photo_remote_path);
                    $debug('Already uploaded as ' . $photo_remote_path);

                    if (in_array($directory_name, $progress[1])) {
                        $debug('Already added to album "' . $directory_name . '"');
                        continue;
                    }
                } else {
                    $debug('Photo or video taken @ ' . Metadata::takenTime($photo_path)->format('Y-m-d H:i:s'));

                    $directory_remote_path = RemoteDirectory::create($attempt, $this->files_base_path, Metadata::takenTime($photo_path)->format('/Y/m'));

                    $photo_remote_filename = rawurlencode($photo_filename);
                    $file_id = null;
                    try {
                        $file_remote_props = $attempt('propFind', $directory_remote_path . '/' . $photo_remote_filename, ['{http://owncloud.org/ns}fileid', '{http://owncloud.org/ns}size']);
                        if (isset($file_remote_props['{http://owncloud.org/ns}size']) === false) {
                            
                        } elseif ((int) $file_remote_props['{http://owncloud.org/ns}size'] === 0) {

                        } elseif (filesize($photo_path) === (int) $file_remote_props['{http://owncloud.org/ns}size']) {
                            $file_id = $file_remote_props['{http://owncloud.org/ns}fileid'];
                        } else {
                            $debug('Rename remote target, because existing remote file has same name but different, non-zero filesize (so possibly a different photo)');
                            $photo_remote_filename = uniqid() . '-' . $photo_remote_filename;
                        }
                    } catch (\Sabre\HTTP\ClientHttpException $exception) {
                        
                    }

                    $photo_remote_path = $directory_remote_path . '/' . $photo_remote_filename;

                    if (isset($file_id)) {
                        $debug('Remote file already exists and same file size, skipping');
                    } elseif (self::upload($photo_path, $photo_remote_path)) {
                        $debug('Succesfully uploaded to "' . str_replace($this->files_base_path, '', $photo_remote_path) . '"');
                    } else {
                        $debug('Failed');
                        continue;
                    }

                    Progress::update($photo_path, $photo_remote_path, null);
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
                    Progress::update($photo_path, $photo_remote_path, $directory_name);
                }
            }
        } catch (\Exception $e) {
            return self::storeException($this->path, $e);
        }

        mkdir($this->path . '/.migrated');
        return 'done';
    }

    static function upload(callable $attempt, string $source, string $target): bool {
        $local_size = filesize($source);

        $response = $attempt('request', 'PUT', $target, fopen($source, 'r+'), [
            'X-OC-MTime' => filemtime($source),
            'X-OC-CTime' => Metadata::takenTime($source)->getTimestamp(),
            'OC-Total-Length' => $local_size
        ]);

        if ($response['statusCode'] < 200 || $response['statusCode'] > 399) {
            return false;
        }

        $file_remote_head_check = $attempt('request', 'HEAD', $target);

        if ($file_remote_head_check['statusCode'] !== 200) {
            return false;
        } elseif (isset($file_remote_head_check['headers']['content-length']) === false) {
            return false;
        } elseif ($local_size !== (int) $file_remote_head_check['headers']['content-length'][0] ?? 0) {
            return false;
        }

        return true;
    }
}
