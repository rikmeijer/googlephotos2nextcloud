# Google Photos to Nextcloud

Requires Nextcloud Photos app to be installed, because of albums.

Replace NEXTCLOUD_URL with the following: `https://<USER>:<APP_PASSWORD>@nextcloud.example.org/Photos`,
`/Photos` can be any path in Nextcloud to import photos to

Optionally, setting a NEXTCLOUD_PREFIX allows nextcloud to be in a sub dir.

```dotenv
# nextcloud url: https://web.hos.st/nextcloud
NEXTCLOUD_PREFIX=nextcloud # no leading or trailing /!
```

## Command Line

Run from command line (in `/path/to/Takeout/Google Photos`, where photo directories and jsons are residing)

Note: Imagick extension required (with ffmpeg for video exif data)

```dotenv
NEXTCLOUD_URL=<NEXTCLOUD_URL> # e.g. https://user:s3cr3t@cloud.example.com/Photos
NEXTCLOUD_USER=usernamehere # Optional, overwrites user in NEXTCLOUD_URL
NEXTCLOUD_PASSWORD=secret # Optional, overwrites password in NEXTCLOUD_URL
```

```sh
php gp2nc.php
```

## Run with docker

`/path/to/Takeout/Google Photos` is the path where the general json files (e.g. user-generated-memory-titles.json) of your Takeout reside.

```sh
docker build . -t gp2nc
docker run --rm -v "/path/to/Takeout/Google Photos":/photos -e NEXTCLOUD_URL=<NEXTCLOUD_URL> gp2nc
```

or use pre-built image from GitHub Container Registry (auto-build)

```sh
docker run --rm -v "/path/to/Takeout/Google Photos":/photos -e NEXTCLOUD_URL=<NEXTCLOUD_URL> ghcr.io/rikmeijer/googlephotos2nextcloud:latest
```

## Known errors

Disk cache limit is set to 32GiB, files requiring larger cache will exhaust this and result in an error like:

```text
Failed reading metadata: cache resources exhausted
```

## If you like this tool

Donations are [welcome](https://rikmeijer.github.io)
