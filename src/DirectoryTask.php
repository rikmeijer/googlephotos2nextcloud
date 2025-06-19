<?php

namespace Rikmeijer\Googlephotos2nextcloud;

readonly class DirectoryTask {

    public function __construct(
            private string $path,
            private string $files_base_path,
            private ?string $album_path
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

    public function __invoke(callable $attempt): string {
        if (is_dir($this->path . '/.migrated')) {
            return 'already migrated';
        } elseif (is_file($this->path . '/gp2nc-error.log')) {
            return 'skip to prevent recurring crashes, resolve errors first and delete gp2nc-error.log';
        }

        $directory_name = basename($this->path);
        $files = array_filter(glob($this->path . '/*'), 'is_file');
        IO::write('Found "' . $directory_name . '", containing ' . count($files) . ' files');

        if (isset($this->album_path)) {
            $album_photos = $attempt('propFind', $this->album_path, [], 1);
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
                $debug = fn(string $message) => IO::write('[' . basename($photo_path) . '] - ' . $message);

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

                    $photo_remote_filename = rawurlencode(basename($photo_path));
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
                    } else {
                        RemoteFile::upload($attempt, $photo_path, $photo_remote_path);
                        $debug('Succesfully uploaded to "' . str_replace($this->files_base_path, '', $photo_remote_path) . '"');
                    }

                    Progress::update($photo_path, $photo_remote_path, null);
                }

                if (isset($this->album_path) === false) {
                    continue;
                }

                $debug('Photo must be in album ' . $this->album_path);
                if (isset($file_id, $album_photos[$this->album_path . '/' . $file_id . '-' . $photo_remote_filename])) {
                    $debug('Already in album "' . $directory_name . '"');
                } else {
                    $debug('Copying to album "' . $directory_name . '"');
                    $attempt('request', 'COPY', $photo_remote_path, headers: [
                        'Destination' => $this->album_path . '/' . $photo_remote_filename
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
}
