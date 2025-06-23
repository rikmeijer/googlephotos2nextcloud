<?php

namespace Rikmeijer\Googlephotos2nextcloud;

class Hash {
    static function retrieve(callable $attempt, string $file_path, array $file_properties): ?string {
        if (array_key_exists('{http://owncloud.org/ns}checksums', $file_properties)) {
            foreach ($file_properties['{http://owncloud.org/ns}checksums'] as $checksum) {
                list($algo, $possible_hash) = explode(':', $checksum['value'], 2);
                if (strcasecmp($algo, 'md5') === 0) {
                    return $possible_hash;
                }
            }
        }

        $attempt('request', 'PATCH', $file_path, headers: [
            'X-Recalculate-Hash' => 'md5'
        ]);
        $file = $attempt('request', 'HEAD', $file_path, headers: [
            'X-Hash' => 'md5'
        ]);
        foreach ($file['headers']['oc-checksum'] as $checksum) {
            list($algo, $hash) = explode(':', $checksum, 2);
            if (strcasecmp($algo, 'md5') === 0) {
                return $hash;
            }
        }
        return null;
    }
}
