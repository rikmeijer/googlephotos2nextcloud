<?php

namespace Rikmeijer\Googlephotos2nextcloud;

class Metadata {

    const EXIF_DATE_SOURCES = [
        'exif:DateTimeOriginal',
        'exif:DateTime',
        'exif:DateTimeDigitized'
    ];
    const EXIF_FORMATS = [
        'Y-m-d_G:i:s' => '/\d+\-\d+\-\d+_\d+:\d+:\d+/',
        'Y:m:d G:i:s' => '/\d+\:\d+\:\d+ \d+:\d+:\d+/'
    ];

    static array $cache = [];

    static function takenTime(string $photo_path): \DateTimeImmutable {
        if (isset(self::$cache[$photo_path])) {
            return self::$cache[$photo_path];
        }

        list($ext, $basename) = array_map('strrev', explode('.', strrev($photo_path), 2));

        $photo_filename = basename($photo_path);
        $options = glob($photo_path . '*.json');
        if (preg_match('/([^\(]+)(\(\d+\))$/', $basename, $matches) > 0) {
            $original_filename = rtrim($matches[1]) . '.' . $ext;
            if (file_exists($original_filename) === false) {
                IO::write('[' . $photo_filename . '] - ' . 'Possible duplicate of `' . basename($original_filename) . '` in filename, but original file missing');
            } elseif (filesize($photo_path) === filesize($original_filename)) {
                IO::write('[' . $photo_filename . '] - ' . 'Duplicate of `' . basename($original_filename) . '`, try to find additional metadata files: ' . $original_filename . '.*' . $matches[2] . '.json');
                $options = array_merge($options, glob($matches[1] . '.' . $ext . '*' . $matches[2] . '.json'));
            } else {
                IO::write('[' . $photo_filename . '] - ' . 'Possible duplicate of `' . basename($original_filename) . '` in filename, but filesize mismatch');
                $options = glob($matches[1] . '.' . $ext . '*' . $matches[2] . '.json');
            }
        }

        if (count($options) === 0) {
            IO::write('[' . $photo_filename . '] - ' . 'No metadata files found');
        } else {
            foreach ($options as $option) {
                if (is_file($option) === false) {
                    continue;
                }

                $photo_metadata = IO::readJson($option);
                if (isset($photo_metadata['photoTakenTime'])) {
                    IO::write('[' . $photo_filename . '] - ' . 'Found `photoTakenTime` in metadata');
                    $photo_takentime = $photo_metadata['photoTakenTime']['timestamp'];
                } elseif (isset($photo_metadata['creationTime'])) {
                    IO::write('[' . $photo_filename . '] - ' . 'Found `creationTime` in metadata');
                    $photo_takentime = $photo_metadata['creationTime']['timestamp'];
                } else {
                    IO::write('[' . $photo_filename . '] - ' . 'Found no datetime in metadata');
                    continue;
                }

                return self::$cache[$photo_path] = new \DateTimeImmutable('@' . $photo_takentime);
            }
        }

        $image = new \Imagick($photo_path);
        $exif = $image->getImageProperties("exif:DateTime*");
        foreach (self::EXIF_DATE_SOURCES as $exif_date_source) {
            if (isset($exif[$exif_date_source]) === false) {
                continue;
            }

            $exif_datetime = $exif[$exif_date_source];
            foreach (self::EXIF_FORMATS as $exif_format => $exif_regex) {
                if (preg_match($exif_regex, $exif_datetime) === 1) {
                    IO::write('[' . $photo_filename . '] - ' . 'Found `' . $exif_datetime . '` in ' . $exif_date_source . '');
                    return self::$cache[$photo_path] = \DateTimeImmutable::createFromFormat($exif_format, $exif_datetime);
                }
            }
        }


        IO::write('[' . $photo_filename . '] - ' . 'Found no datetime in exif data nor metadata, falling back to filemtime');
        return self::$cache[$photo_path] = new \DateTimeImmutable('@' . filemtime($photo_path));
    }
}
