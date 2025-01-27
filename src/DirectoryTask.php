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

        if (count($metadata_jsons) === 0) {
            IO::write('No metadata found');
        } else {
            $directory_metadata = IO::readJson(array_shift($metadata_jsons));
        }


        IO::write('Found ' . count($photo_jsons) . ' photo data files');
        foreach ($photo_jsons as $photo_json) {
            $photo_metadata = IO::readJson($photo_json);
            $photo_taken = new \DateTimeImmutable('@' . $photo_metadata['photoTakenTime']['timestamp']);

            $remote_name = $this->files_base_path;

            $remote_path = $photo_taken->format('Y/m');
            $directory_remote_head = $client->request('HEAD', $remote_name . '/' . $remote_path);
            if ($directory_remote_head['statusCode'] === 404) {
                foreach (explode('/', $remote_path) as $remote_path_part) {
                    $remote_name .= '/' . $remote_path_part;
                    IO::write('Creating directory "' . str_replace($this->files_base_path, '', $remote_name) . '" remotely');
                    $client->request('MKCOL', $remote_name);
                }
            } else {
                IO::write('Directory "/' . $remote_path . '" already exists');
            }


            $photo_filename = basename($photo_json, ".json");
            $photo_path = $this->path . '/' . $photo_filename;
            $photo_remote_filename = $remote_name . '/' . rawurlencode($photo_filename);

            $file_remote_head = $client->request('HEAD', $photo_remote_filename);
            $upload = true;
            if ($file_remote_head['statusCode'] === 200) {
                IO::write('Remote file already exists');
                if (!isset($file_remote_head['headers']['content-length'][0])) {
                    var_dump($file_remote_head);
                }
                $remote_size = $file_remote_head['headers']['content-length'][0];
                $upload = filesize($photo_path) !== (int) $remote_size;
            }

            if ($upload === false) {
                IO::write('Same file size, skipping');
            } else {
                IO::write('Uploading "' . $photo_filename . '" to "' . str_replace($this->files_base_path, '', $photo_remote_filename) . '"');
                $response = $client->request('PUT', $photo_remote_filename, file_get_contents($photo_path));

                if ($response['statusCode'] < 200 || $response['statusCode'] > 399) {
                    IO::write('Failed');
                }
            }

            if ($is_album) {
                IO::write('Copying to album "' . $directory_name . '"');
                $client->request('COPY', $photo_remote_filename, headers: [
                    'Destination' => $this->albums_base_path . '/' . $directory_name . '/' . $photo_filename
                ]);
            }
        }

        return 'done';
    }
}
