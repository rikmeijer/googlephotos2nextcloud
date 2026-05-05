<?php

namespace Rikmeijer\Googlephotos2nextcloud;

class Metadata {

    const EXIF_DATE_SOURCES = [
        'exif:DateTimeOriginal',
        'exif:DateTime',
        'exif:DateTimeDigitized'
    ];
    const EXIF_FORMATS = [
        'Y-m-d_G:i:s' => '/^\d+\-\d+\-\d+_\d+:\d+:\d+$/',
        'Y:m:d G:i:s' => '/^\d+\:\d+\:\d+ \d+:\d+:\d+$/',
        'Y:m:d G:i:s.v' => '/^\d+\:\d+\:\d+ \d+:\d+:\d+\.\d+$/'
    ];
    const MOTION_VIDEO_EXTENSIONS = ['MP4', 'MOV'];
    const COMPANION_IMAGE_EXTENSIONS = ['JPG', 'JPEG', 'HEIC', 'HEIF'];

    static array $cache = [];

    static function metadataTimestamp(string $path, string $filename_for_logging): ?int {
        $path_info = pathinfo($path);
        $ext = $path_info['extension'] ?? '';
        $basename = ($path_info['dirname'] ?? '.') . '/' . ($path_info['filename'] ?? basename($path));

        $options = glob($path . '*.json');
        if (preg_match('/([^\(]+)(\(\d+\))$/', $basename, $matches) > 0) {
            $original_filename = rtrim($matches[1]) . '.' . $ext;
            if (file_exists($original_filename) === false) {
                IO::write('[' . $filename_for_logging . '] - ' . 'Possible duplicate of `' . basename($original_filename) . '` in filename, but original file missing');
            } elseif (filesize($path) === filesize($original_filename)) {
                IO::write('[' . $filename_for_logging . '] - ' . 'Duplicate of `' . basename($original_filename) . '`, try to find additional metadata files: ' . $original_filename . '.*' . $matches[2] . '.json');
                $options = array_merge($options, glob($matches[1] . '.' . $ext . '*' . $matches[2] . '.json'));
            } else {
                IO::write('[' . $filename_for_logging . '] - ' . 'Possible duplicate of `' . basename($original_filename) . '` in filename, but filesize mismatch');
                $options = glob($matches[1] . '.' . $ext . '*' . $matches[2] . '.json');
            }
        }

        if (count($options) === 0 && strtolower($ext) === 'mp4') {
            // Live Photo: MP4 motion clip shares metadata with its HEIC/JPG companion
            foreach (['HEIC', 'heic', 'JPG', 'jpg', 'JPEG', 'jpeg'] as $companion_ext) {
                $companion_options = glob($basename . '.' . $companion_ext . '*.json') ?: [];
                if (count($companion_options) > 0) {
                    $options = $companion_options;
                    break;
                }
            }
        }

        if (count($options) === 0) {
            IO::write('[' . $filename_for_logging . '] - ' . 'No metadata files found');
            return null;
        }

        foreach ($options as $option) {
            if (is_file($option) === false) {
                continue;
            }

            $photo_metadata = IO::readJson($option);
            if (isset($photo_metadata['photoTakenTime'])) {
                IO::write('[' . $filename_for_logging . '] - ' . 'Found `photoTakenTime` in metadata');
                return (int) $photo_metadata['photoTakenTime']['timestamp'];
            }

            if (isset($photo_metadata['creationTime'])) {
                IO::write('[' . $filename_for_logging . '] - ' . 'Found `creationTime` in metadata');
                return (int) $photo_metadata['creationTime']['timestamp'];
            }

            IO::write('[' . $filename_for_logging . '] - ' . 'Found no datetime in metadata');
        }

        return null;
    }

    static function takenTimeFromCompanionImageMetadata(string $video_path, string $video_filename): ?\DateTimeImmutable {
        $path_info = pathinfo($video_path);
        $dirname = $path_info['dirname'] ?? '.';
        $filename = $path_info['filename'] ?? basename($video_path);

        $companion_image_paths = [];
        foreach (self::COMPANION_IMAGE_EXTENSIONS as $companion_ext) {
            foreach ([$companion_ext, strtolower($companion_ext)] as $ext_option) {
                $companion_path = $dirname . '/' . $filename . '.' . $ext_option;
                if (is_file($companion_path)) {
                    $companion_image_paths[] = $companion_path;
                }
            }
        }

        $companion_image_paths = array_values(array_unique($companion_image_paths));
        foreach ($companion_image_paths as $companion_image_path) {
            $companion_metadata_timestamp = self::metadataTimestamp($companion_image_path, $video_filename);
            if ($companion_metadata_timestamp === null) {
                continue;
            }

            IO::write('[' . $video_filename . '] - ' . 'Using metadata from companion image `' . basename($companion_image_path) . '`');
            return new \DateTimeImmutable('@' . $companion_metadata_timestamp);
        }

        return null;
    }

    static function takenTime(string $photo_path): \DateTimeImmutable {
        if (isset(self::$cache[$photo_path])) {
            return self::$cache[$photo_path];
        }

        $photo_filename = basename($photo_path);
        $photo_metadata_timestamp = self::metadataTimestamp($photo_path, $photo_filename);
        if ($photo_metadata_timestamp !== null) {
            return self::$cache[$photo_path] = new \DateTimeImmutable('@' . $photo_metadata_timestamp);
        }

        $photo_extension = strtoupper(pathinfo($photo_path, PATHINFO_EXTENSION));
        if (in_array($photo_extension, self::MOTION_VIDEO_EXTENSIONS, true)) {
            $companion_taken_time = self::takenTimeFromCompanionImageMetadata($photo_path, $photo_filename);
            if ($companion_taken_time !== null) {
                return self::$cache[$photo_path] = $companion_taken_time;
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
