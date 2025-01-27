<?php

require __DIR__ . '/vendor/autoload.php';

function write(string $line): void {
    print PHP_EOL . $line;
}

function readJson(string $path): mixed {
    return json_decode(file_get_contents($path), true);
}

define('WORKING_DIRECTORY', getcwd());
if (WORKING_DIRECTORY === false) {
    exit('Could not determine current working directory');
}
write('Working from ' . WORKING_DIRECTORY);

if ($_SERVER['argc'] < 2) {
    exit('Nextcloud URL missing');
}
$parsed_url = parse_url($_SERVER['argv'][1]);

$origin = [$parsed_url['scheme'] . '://', $parsed_url['host']];
if (isset($parsed_url['port'])) {
    $origin[] = $parsed_url['port'];
}
define('NEXTCLOUD_URL', implode($origin));
write('Working on ' . NEXTCLOUD_URL);

define('NEXTCLOUD_USER', $parsed_url['user']);
write('Working as ' . NEXTCLOUD_USER);

define('NEXTCLOUD_PASSWORD', $parsed_url['pass'] ?? null);
if (NEXTCLOUD_PASSWORD !== null) {
    write('Identifing with a password.');
}

define('NEXTCLOUD_UPLOAD_PATH', $parsed_url['path']);
write('Uploading photos to ' . NEXTCLOUD_UPLOAD_PATH);

$client = new Sabre\DAV\Client([
    'baseUri' => NEXTCLOUD_URL . '/remote.php/dav',
    'userName' => NEXTCLOUD_USER,
    'password' => NEXTCLOUD_PASSWORD
        ]);


$user_albums = readJson(WORKING_DIRECTORY . "/user-generated-memory-titles.json")['title'];
write('Found ' . count($user_albums) . ' user albums');

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
write('Found ' . count($available_albums) . ' available albums');

$createable_albums = array_diff($user_albums, $available_albums);
write('Creating ' . count($createable_albums) . ' albums');
foreach ($createable_albums as $creatable_album) {
    write('--> ' . rawurlencode($creatable_album));
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

//remote.php/dav/files/rik/Photos/Zweden 2024 /
write('Walking directories...');
foreach (glob(WORKING_DIRECTORY . '/*') as $path) {
    if (is_dir($path) === false) {
        continue;
    }

    $directory_name = basename($path);
    $files = glob($path . '/*');
    write('Found "' . $directory_name . '", containing ' . count($files) . ' files');
    $is_album = in_array($directory_name, $user_albums);

    $json_files = array_filter($files, fn(string $p) => str_ends_with($p, '.json'));
    $metadata_jsons = array_filter($json_files, fn(string $p) => str_ends_with($p, 'metadata.json'));
    $photo_jsons = array_filter($json_files, fn(string $p) => str_ends_with($p, 'metadata.json') === false);

    if (count($metadata_jsons) === 0) {
        write('No metadata found');
    } else {
        $directory_metadata = readJson(array_shift($metadata_jsons));
    }


    write('Found ' . count($photo_jsons) . ' photo data files');
    foreach ($photo_jsons as $photo_json) {
        $photo_metadata = readJson($photo_json);
        $photo_taken = new DateTimeImmutable('@' . $photo_metadata['photoTakenTime']['timestamp']);

        $remote_name = $files_base_path . NEXTCLOUD_UPLOAD_PATH;

        $remote_path = $photo_taken->format('Y/m');
        $directory_remote_head = $client->request('HEAD', $remote_name . '/' . $remote_path);
        if ($directory_remote_head['statusCode'] === 404) {
            foreach (explode('/', $remote_path) as $remote_path_part) {
                $remote_name .= '/' . $remote_path_part;
                write('Creating directory "' . str_replace($files_base_path, '', $remote_name) . '" remotely');
                $client->request('MKCOL', $remote_name);
            }
        } else {
            write('Directory "/' . $remote_path . '" already exists');
        }


        $photo_filename = basename($photo_json, ".json");
        $photo_path = $path . '/' . $photo_filename;
        $photo_remote_filename = $remote_name . '/' . rawurlencode($photo_filename);

        $file_remote_head = $client->request('HEAD', $photo_remote_filename);
        $upload = true;
        if ($file_remote_head['statusCode'] === 200) {
            write('Remote file already exists');
            $remote_size = $file_remote_head['headers']['content-length'][0];
            $upload = filesize($photo_path) !== (int) $remote_size;
        }

        if ($upload === false) {
            write('Same file size, skipping');
        } else {
            write('Uploading "' . $photo_filename . '" to "' . str_replace($files_base_path, '', $photo_remote_filename) . '"');
            $response = $client->request('PUT', $photo_remote_filename, file_get_contents($photo_path));

            if ($response['statusCode'] < 200 || $response['statusCode'] > 399) {
                write('Failed');
            }
        }

        if ($is_album) {
            write('Copying to album "' . $directory_name . '"');
            $client->request('COPY', $photo_remote_filename, headers: [
                'Destination' => $albums_base_path . '/' . $directory_name . '/' . $photo_filename
            ]);
        }
    }
}