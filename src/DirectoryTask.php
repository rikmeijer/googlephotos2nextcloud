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
            private array $user_albums,
            private string $nextcloud_url,
            private string $nextcloud_user,
            private string $nextcloud_password,
            private string $upload_mode
    ) {

    }

    static function getMetadata(string $photo_path): mixed {
        if (is_file($photo_path . '.json') !== false) {
            return IO::readJson($photo_path . '.json');
        } elseif (is_file($photo_path . '.supplemental-metadata.json') !== false) {
            return IO::readJson($photo_path . '.supplemental-metadata.json');
        }
        return null;
    }

    #[\Override]
    public function run(Channel $channel, Cancellation $cancellation): string {
        if (is_dir($this->path . '/.migrated')) {
            return 'already migrated';
        }
        $client = new \Sabre\DAV\Client([
            'baseUri' => $this->nextcloud_url . '/remote.php/dav',
            'userName' => $this->nextcloud_user,
            'password' => $this->nextcloud_password,
            'authType' => \Sabre\DAV\Client::AUTH_BASIC
        ]);

        $directory_name = basename($this->path);
        $files = glob($this->path . '/*');
        IO::write('Found "' . $directory_name . '", containing ' . count($files) . ' files');
        $is_album = in_array($directory_name, $this->user_albums);

        $json_files = array_filter($files, fn(string $p) => str_ends_with($p, '.json'));
        $metadata_jsons = array_filter($json_files, fn(string $p) => str_ends_with($p, 'metadata.json'));
        $photo_files = array_filter($files, fn(string $p) => str_ends_with($p, '.json') === false);

        if (count($metadata_jsons) === 0) {
            IO::write('No metadata found');
        }

        IO::write('Found ' . count($photo_files) . ' photo files');
        foreach ($photo_files as $photo_path) {
            $force_upload = false;
            $photo_filename = basename($photo_path);
            $exif = @exif_read_data($photo_path);
            if (isset($exif['DateTimeOriginal'])) {
                $photo_taken_datetime = $exif['DateTimeOriginal'];
            } elseif ($photo_metadata = self::getMetadata($photo_path)) {
                $photo_takentime_data = $photo_metadata['photoTakenTime'] ?? $photo_metadata['creationTime'];
                $photo_taken_datetime = '@' . $photo_takentime_data['timestamp'];

                IO::write("Using metadata json, updating EXIF DateTimeOriginal");
                $image = new \Imagick();
                try {
                    $image->readImage($photo_path);
                    $image->setImageProperty('Exif.Image.DateTimeOriginal', date('Y:M:D H:i:s', $photo_takentime_data['timestamp']));
                    $image->writeImage();

                    $force_upload = true;
                } catch (\ImagickException $e) {
                   IO::write('Failed updating EXIF data for ' . $photo_path);
                }
            } else {
                $photo_taken_datetime = '@' . filemtime($photo_path);
            }
            try {
                $photo_taken = new \DateTimeImmutable($photo_taken_datetime);
            } catch (\DateMalformedStringException $e) {
                IO::write($photo_filename . ' has a improper' . (isset($exif['DateTimeOriginal']) ? ' exif' : '') . ' taken datetime: ' . $photo_taken_datetime . ', trying as Unix timestamp');
                if (is_numeric($photo_taken_datetime)) {
                    $photo_taken = new \DateTimeImmutable('@' . $photo_taken_datetime);
                } elseif (preg_match('/\d+\-\d+\-\d+_\d+:\d+:\d+/', $photo_taken_datetime) === 1) {
                    $photo_taken = \DateTimeImmutable::createFromFormat('Y-m-d_G:i:s', $photo_taken_datetime);
                } else {
                    exit('Failed ' . $photo_filename . ' please fix take datetime and restart');
                }
            }

            $directory_remote_path = IO::createDirectory($client, $this->files_base_path, $photo_taken->format('/Y/m'));
            if ($directory_remote_path === false) {
                continue;
            }

            $photo_remote_filename = rawurlencode($photo_filename);
            $photo_remote_path = $directory_remote_path . '/' . $photo_remote_filename;

            $upload = true;
            $file_id = null;
            try {
                $file_remote_props = $client->propFind($photo_remote_path, ['{http://owncloud.org/ns}fileid', '{http://owncloud.org/ns}size']);
                if (count($file_remote_props) > 0) {
                    $remote_size = $file_remote_props['{http://owncloud.org/ns}size'] ?? null;
                    $upload = $force_upload || filesize($photo_path) !== (int) $remote_size;
                    $file_id = $file_remote_props['{http://owncloud.org/ns}fileid'];
                }
            } catch (\Sabre\HTTP\ClientHttpException $exception) {
                $upload = $exception->getHttpStatus() === 404;
            }

            if ($upload) {
                IO::write('Uploading "' . $photo_filename . '" to "' . str_replace($this->files_base_path, '', $photo_remote_path) . '"');
                $response = $client->request('PUT', $photo_remote_path, fopen($photo_path, 'r+'));
                
                if ($response['statusCode'] < 200 || $response['statusCode'] > 399) {
                    IO::write('Failed');
                }
                $file_remote_head_check = $client->request('HEAD', $photo_remote_path);
                if ($file_remote_head_check['statusCode'] !== 200) {
                    IO::write('Failed');
                } elseif (isset($file_remote_head_check['headers']['content-length']) === false) {
                    IO::write('Failed');
                } elseif (filesize($photo_path) !== (int) $file_remote_head_check['headers']['content-length'][0] ?? 0) {
                    IO::write('Failed');
                } elseif ($this->upload_mode === 'move') {
                    IO::write('Succesfully uploaded, removing local file');
                    unlink($photo_path);
                } else {
                    IO::write('Succesfully uploaded');
                }
            } elseif ($this->upload_mode === 'move') {
                IO::write('Remote file already exists and same file size, removing local file');
                unlink($photo_path);
            } else {
                IO::write('Remote file already exists and same file size, skipping');
            }

            if ($is_album === false) {
                continue;
            }

            $album_path = $this->albums_base_path . '/' . rawurlencode($directory_name);

            $album_photos = $client->propFind($album_path, [], 1);

            IO::write('Photo must be in album ' . $album_path);
            if (isset($file_id, $album_photos[$album_path . '/' . $file_id . '-' . $photo_remote_filename])) {
                IO::write('Already in album "' . $directory_name . '"');
            } else {
                IO::write('Copying to album "' . $directory_name . '"');
                $client->request('COPY', $photo_remote_path, headers: [
                    'Destination' => $album_path . '/' . $photo_remote_filename
                ]);
            }
        }

        mkdir($this->path . '/.migrated');
        return 'done';
    }
}
