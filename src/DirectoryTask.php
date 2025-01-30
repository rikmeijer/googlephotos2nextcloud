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
            private string $nextcloud_password
    ) {

    }

    #[\Override]
    public function run(Channel $channel, Cancellation $cancellation): string {

        $client = new \Sabre\DAV\Client([
            'baseUri' => $this->nextcloud_url . '/remote.php/dav',
            'userName' => $this->nextcloud_user,
            'password' => $this->nextcloud_password
        ]);

        $directory_name = basename($this->path);
        $files = glob($this->path . '/*');
        IO::write('Found "' . $directory_name . '", containing ' . count($files) . ' files');
        $is_album = in_array($directory_name, $this->user_albums);

        $json_files = array_filter($files, fn(string $p) => str_ends_with($p, '.json'));
        $metadata_jsons = array_filter($json_files, fn(string $p) => str_ends_with($p, 'metadata.json'));
        $photo_jsons = array_filter($json_files, fn(string $p) => str_ends_with($p, 'metadata.json') === false);
        $photo_files = array_filter($files, fn(string $p) => str_ends_with($p, '.json') === false);

        if (count($metadata_jsons) === 0) {
            IO::write('No metadata found');
        } else {
            $directory_metadata = IO::readJson(array_shift($metadata_jsons));
        }


        IO::write('Found ' . count($photo_jsons) . ' photo files');
        foreach ($photo_files as $photo_path) {
            $photo_filename = basename($photo_path);
            $exif = @exif_read_data($photo_path);
            if (isset($exif['DateTimeOriginal'])) {
                $photo_taken_datetime = $exif['DateTimeOriginal'];
            } elseif (is_file($photo_path . '.json') !== false) {
                $photo_metadata = IO::readJson($photo_path . '.json');
                $photo_takentime_data = $photo_metadata['photoTakenTime'] ?? $photo_metadata['creationTime'];
                $photo_taken_datetime = '@' . $photo_takentime_data['timestamp'];
            } else {
                $photo_taken_datetime = '@' . filemtime($photo_path);
            }
            try {
                $photo_taken = new \DateTimeImmutable($photo_taken_datetime);
            } catch (\DateMalformedStringException $e) {
                IO::write($photo_filename . ' has a improper' . (isset($exif['DateTimeOriginal']) ? ' exif' : '') . ' taken datetime: ' . $photo_taken_datetime . ', trying as Unix timestamp');
                if (is_numeric($photo_taken_datetime) === false) {
                    exit('Failed ' . $photo_filename, ' please fix take datetime and restart');
                }
                $photo_taken = new \DateTimeImmutable('@' . $photo_taken_datetime);
            }

            $directory_remote_path = IO::createDirectory($client, $this->files_base_path, $photo_taken->format('/Y/m'));
            if ($directory_remote_path === false) {
                continue;
            }

            $photo_remote_filename = rawurlencode($photo_filename);
            $photo_remote_path = $directory_remote_path . '/' . $photo_remote_filename;

            $file_remote_head = $client->request('HEAD', $photo_remote_path);
            $upload = true;
            if ($file_remote_head['statusCode'] === 200) {
                IO::write('Remote file already exists');
                $remote_size = $file_remote_head['headers']['content-length'][0] ?? null;
                $upload = filesize($photo_path) !== (int) $remote_size;
            }

            if ($upload === false) {
                IO::write('Same file size, skipping');
            } else {
                IO::write('Uploading "' . $photo_filename . '" to "' . str_replace($this->files_base_path, '', $photo_remote_path) . '"');
                $response = $client->request('PUT', $photo_remote_path, fopen($photo_path, 'w+'));

                if ($response['statusCode'] < 200 || $response['statusCode'] > 399) {
                    IO::write('Failed');
                }
            }

            if ($is_album) {
                $album_path = $this->albums_base_path . '/' . rawurlencode($directory_name);
                IO::write('Photo must be in album ' . $album_path);
                if ($client->request('HEAD', $album_path . '/' . $photo_remote_filename)['statusCode'] === 404) {
                    IO::write('Copying to album "' . $directory_name . '"');
                    $client->request('COPY', $photo_remote_path, headers: [
                        'Destination' => $album_path . '/' . $photo_remote_filename
                    ]);
                } else {
                    IO::write('Already in album "' . $directory_name . '"');
                }
            }
        }

        mkdir($this->path . '/.migrated');
        return 'done';
    }
}
