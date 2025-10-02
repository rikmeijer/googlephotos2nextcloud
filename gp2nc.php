<?php

require __DIR__ . '/vendor/autoload.php';

ini_set('memory_limit', '3G'); // needed when there are ALOT of files remote

use Rikmeijer\Googlephotos2nextcloud\DirectoryTask;
use Rikmeijer\Googlephotos2nextcloud\IO;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

define('WORKING_DIRECTORY', getcwd());
if (WORKING_DIRECTORY === false) {
    exit('Could not determine current working directory');
}
IO::write('Working from ' . WORKING_DIRECTORY);

if (isset($_ENV['NEXTCLOUD_URL']) === false) {
    exit('Nextcloud URL missing, please set in .env or as environment variable');
}
$parsed_url = parse_url($_ENV['NEXTCLOUD_URL']);

$origin = [$parsed_url['scheme'] . '://', $parsed_url['host']];
if (isset($parsed_url['port'])) {
    $origin[] = $parsed_url['port'];
}
define('NEXTCLOUD_URL', implode($origin) . '/');
IO::write('Working on ' . NEXTCLOUD_URL);

define('NEXTCLOUD_USER', $_ENV['NEXTCLOUD_USER'] ?? $parsed_url['user'] ?? null);
IO::write('Identifing as ' . NEXTCLOUD_USER ?? 'anonymous');

define('NEXTCLOUD_PASSWORD', $_ENV['NEXTCLOUD_PASSWORD'] ?? $parsed_url['pass'] ?? null);
if (NEXTCLOUD_PASSWORD !== null) {
    IO::write('Identifing with a password.');
} else {
    IO::write('Not using a password.');
}

define('NEXTCLOUD_UPLOAD_PATH', $parsed_url['path']);

$client = new Sabre\DAV\Client([
    'driver' => 'webdav',
    'baseUri' => NEXTCLOUD_URL,
    'userName' => NEXTCLOUD_USER,
    'password' => NEXTCLOUD_PASSWORD,
    'pathPrefix' => 'remote.php/dav',
    'authType' => 1, //Basic authentication
        ]);


$user_albums = [];
$user_albums_metadata_files = glob(WORKING_DIRECTORY . "/*/metadata.json");
foreach ($user_albums_metadata_files as $user_albums_metadata_file) {
    $user_album_name = basename(dirname($user_albums_metadata_file));
    $user_album_metadata = IO::readJson($user_albums_metadata_file);
    if (isset($user_album_metadata['title']) === false) {
        continue;
    } elseif (empty($user_album_metadata['title']) === false) {
        $user_albums[] = $user_album_metadata['title'];
    } else {
        $user_albums[] = $user_album_name;
    }
}
IO::write('Found ' . count($user_albums) . ' user albums');

$files_base_path = '/remote.php/dav/files/' . NEXTCLOUD_USER;
$albums_base_path = '/remote.php/dav/photos/' . NEXTCLOUD_USER . '/albums';

IO::write('Retrieving remote albums...');
$available_album_resources = $client->propfind($albums_base_path, [
    '{DAV:}displayname',
    '{DAV:}getcontentlength',
        ], 1);
$available_albums = [];
foreach ($available_album_resources as $album_id => $available_album) {
    $available_albums[] = rawurldecode(basename($album_id));
}
IO::write('Found ' . count($available_albums) . ' available albums');

$createable_albums = array_diff($user_albums, $available_albums);
IO::write('Creating ' . count($createable_albums) . ' albums');
foreach ($createable_albums as $creatable_album) {
    IO::write('--> ' . rawurlencode($creatable_album));
    $client->request('MKCOL', $albums_base_path . '/' . rawurlencode($creatable_album));
}

$attempt = new Rikmeijer\Googlephotos2nextcloud\Attempt($client);

IO::write('Retrieving remote files...');
$media_properties = [
    '{DAV:}displayname',
    '{DAV:}getcontentlength',
    '{DAV:}getcontenttype',
    '{DAV:}resourcetype',
    '{http://owncloud.org/ns}checksums',
    '{http://nextcloud.org/ns}creation_time',
    '{http://owncloud.org/ns}fileid'
];
$remote_files = [];
foreach ($attempt('propfind', $files_base_path . NEXTCLOUD_UPLOAD_PATH, $media_properties, 3) as $file_path => $available_media_file) {
    if (isset($available_media_file['{DAV:}getcontentlength']) === false) {
        continue;
    }

    $hash = Rikmeijer\Googlephotos2nextcloud\Hash::retrieve($attempt, $file_path, $available_media_file);
    if (isset($remote_files[$hash])) {
        $remote_files[$hash][$file_path] = $available_media_file;
    } else {
        $remote_files[$hash] = [$file_path => $available_media_file];
    }
}
IO::write('Found ' . count($remote_files) . ' files remotely');

IO::write('Walking directories...');
$task = new DirectoryTask(
        $attempt,
        $files_base_path . NEXTCLOUD_UPLOAD_PATH,
        $albums_base_path,
        $user_albums,
        $remote_files
);

foreach (glob(WORKING_DIRECTORY . '/*') as $path) {
    if (is_dir($path) === false) {
        continue;
    }

    $task($path);
}
