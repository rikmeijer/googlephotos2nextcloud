<?php

namespace Rikmeijer\Googlephotos2nextcloud;

readonly class DirectoryTask {

    public function __construct(
            private mixed $attempt,
            private string $files_base_path,
            private string $albums_base_path,
            private array $user_albums,
            private array $remote_files
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

    static function filenameContainsIncrement(string $filename): bool {
        return preg_match('/\([\w\d\s]+\)/', $filename) === 1;
    }

    static function filenamesWithouthIncrementAreIdentical(string $filename1, string $filename2): bool {
        $filename1_without_increment = preg_replace('/\([\w\d\s]+\)/', '', $filename1);
        $filename2_without_increment = preg_replace('/\([\w\d\s]+\)/', '', $filename2);
        return $filename1_without_increment === $filename2_without_increment;
    }

    public function __invoke(string $origin_path): string {
        $directory_name = basename($origin_path);

        $album_path = in_array($directory_name, $this->user_albums) ? $this->albums_base_path . '/' . rawurlencode($directory_name) : null;
        $attempt = $this->attempt;

        $move = RemoteFile::move($this->attempt);

        if (is_file($origin_path . '/gp2nc-error.log')) {
            return 'skip to prevent recurring crashes, resolve errors first and delete gp2nc-error.log';
        }

        $files = array_filter(glob($origin_path . '/*'), 'is_file');
        IO::write('Found "' . $directory_name . '", containing ' . count($files) . ' files');

        if (isset($album_path)) {
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
                $local_filename = basename($photo_path);
                $debug = fn(string $message) => IO::write('[' . $local_filename . '] - ' . $message);
                $local_hash = md5_file($photo_path);

                $debug('Photo ' . $photo_index . ' of ' . $no_photos);

                if (isset($this->remote_files[$local_hash])) {
                    $debug('Same file already exists remotely as: ');
                    foreach ($this->remote_files[$local_hash] as $remote_duplicate_path => $remote_duplicate) {
                        $debug('-> ' . $remote_duplicate_path);
                        $remote_duplicate_filename = basename(urldecode($remote_duplicate_path));

                        if ($local_filename === $remote_duplicate_filename) {
                            $debug('Identical filename, already uploaded');
                        } elseif (self::filenameContainsIncrement($local_filename)) {
                            $debug('Local file name contains file incrementer, e.g.: (1)');
                        } elseif (self::filenameContainsIncrement($remote_duplicate_filename) === false) {
                            $debug('Remote file name does not contain file incrementer, e.g.: (1)');
                        } elseif (self::filenamesWithouthIncrementAreIdentical($local_filename, $remote_duplicate_filename)) {
                            $debug('Locally there is no incrementer, remotely there is. Without the incrementer, files are identical');
                        } else {
                            $existsTest = RemoteFile::existsTest($attempt, dirname($remote_duplicate_path), [
                                    '{DAV:}displayname',
                                    '{DAV:}getcontentlength',
                                    '{DAV:}getcontenttype',
                                    '{DAV:}resourcetype',
                                    '{http://owncloud.org/ns}checksums',
                                    '{http://nextcloud.org/ns}creation_time',
                                    '{http://owncloud.org/ns}fileid'
                            ]);

                            $available_filename = \Rikmeijer\NCMediaCleaner\RemoteFile::findAvailable($existsTest, $local_filename);
                            $move($remote_duplicate_path, dirname($remote_duplicate_path) . '/' . urlencode($available_filename));
                            $debug('Remote has a very different filename, but contents are identical. Renamed remote file (' . $remote_duplicate_filename . '-->' . $available_filename . ')');
                        }

                        if (isset($album_path)) {
                            $album_identifier_path = $album_path . '/' . $remote_duplicate['{http://owncloud.org/ns}fileid'] . '-' . $remote_duplicate_filename;
                            $debug('Photo must be in album ' . $album_path);
                            if (isset($album_photos[$album_identifier_path]) === false) {
                                $attempt('request', 'COPY', $remote_duplicate_path, headers: [
                                    'Destination' => $album_path . '/' . $remote_duplicate_filename
                                ]);
                                $album_photos[$album_identifier_path] = [];
                                $debug('Copied');
                            }
                        }

                    }
                    continue;
                }

                $debug('Photo or video taken @ ' . Metadata::takenTime($photo_path)->format('Y-m-d H:i:s'));

                $directory_remote_path = RemoteDirectory::create($attempt, $this->files_base_path, Metadata::takenTime($photo_path)->format('/Y/m'));

                $photo_remote_filename = rawurlencode($local_filename);

                $photo_remote_path = $directory_remote_path . '/' . $photo_remote_filename;

                RemoteFile::upload($attempt, $photo_path, $photo_remote_path);
                $debug('Succesfully uploaded to "' . str_replace($this->files_base_path, '', $photo_remote_path) . '"');

                if (isset($album_path)) {
                    $debug('Copying to album "' . $directory_name . '"');
                    $attempt('request', 'COPY', $photo_remote_path, headers: [
                        'Destination' => $album_path . '/' . $photo_remote_filename
                    ]);
                }
            }
        } catch (\Exception $e) {
            return self::storeException($origin_path, $e);
        }

        return 'done';
    }
}
