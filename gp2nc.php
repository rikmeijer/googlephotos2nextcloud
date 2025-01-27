<?php

require __DIR__ . '/vendor/autoload.php';

use Rikmeijer\Googlephotos2nextcloud\DirectoryTask;
use Rikmeijer\Googlephotos2nextcloud\IO;
use Amp\Future;
use Amp\Parallel\Worker;
use Amp\Parallel\Worker\ContextWorkerPool;

define('WORKING_DIRECTORY', getcwd());
if (WORKING_DIRECTORY === false) {
    exit('Could not determine current working directory');
}
IO::write('Working from ' . WORKING_DIRECTORY);

if ($_SERVER['argc'] < 2) {
    exit('Nextcloud URL missing');
}
$parsed_url = parse_url($_SERVER['argv'][1]);

$origin = [$parsed_url['scheme'] . '://', $parsed_url['host']];
if (isset($parsed_url['port'])) {
    $origin[] = $parsed_url['port'];
}
define('NEXTCLOUD_URL', implode($origin));
IO::write('Working on ' . NEXTCLOUD_URL);

define('NEXTCLOUD_USER', $parsed_url['user']);
IO::write('Working as ' . NEXTCLOUD_USER);

define('NEXTCLOUD_PASSWORD', $parsed_url['pass'] ?? null);
if (NEXTCLOUD_PASSWORD !== null) {
    IO::write('Identifing with a password.');
}

define('NEXTCLOUD_UPLOAD_PATH', $parsed_url['path']);
IO::write('Uploading photos to ' . NEXTCLOUD_UPLOAD_PATH);

$client = new Sabre\DAV\Client([
    'baseUri' => NEXTCLOUD_URL . '/remote.php/dav',
    'userName' => NEXTCLOUD_USER,
    'password' => NEXTCLOUD_PASSWORD
        ]);


$user_albums = IO::readJson(WORKING_DIRECTORY . "/user-generated-memory-titles.json")['title'];
IO::write('Found ' . count($user_albums) . ' user albums');

$files_base_path = '/remote.php/dav/files/' . NEXTCLOUD_USER;
$albums_base_path = '/remote.php/dav/photos/' . NEXTCLOUD_USER . '/albums';

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


$available_directory_resources = $client->propfind($files_base_path . NEXTCLOUD_UPLOAD_PATH, [
    '{DAV:}displayname',
    '{DAV:}getcontentlength',
        ], 1);
$available_directories = [];
foreach ($available_directory_resources as $directory_id => $available_directory_resource) {
    $available_directories[] = rawurldecode(basename($directory_id));
}

IO::write('Walking directories...');

$pool = Worker\workerPool(new ContextWorkerPool(5));

$executions = [];
foreach (glob(WORKING_DIRECTORY . '/*') as $path) {
    if (is_dir($path) === false) {
        continue;
    }

    // FetchTask is just an example, you'll have to implement
    // the Task interface for your task.
    $executions[$path] = Worker\submit(new DirectoryTask(
                    $path,
                    $files_base_path . NEXTCLOUD_UPLOAD_PATH,
                    $albums_base_path,
                    $user_albums,
                    NEXTCLOUD_URL,
                    NEXTCLOUD_USER,
                    NEXTCLOUD_PASSWORD,
            ));
}


// Each submission returns an Execution instance to allow two-way
// communication with a task. Here we're only interested in the
// task result, so we use the Future from Execution::getFuture()
$responses = Future\await(array_map(
                fn(Worker\Execution $e) => $e->getFuture(),
                $executions,
        ));

foreach ($responses as $url => $response) {
    \printf("Read %d bytes from %s\n", \strlen($response), $url);
}