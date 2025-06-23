<?php



namespace Rikmeijer\Googlephotos2nextcloud;


class Attempt {


    public function __construct(private \Sabre\DAV\Client $client) {
        
    }

    public function __invoke(string $method, mixed ...$args): mixed {
        $attempts = 0;
        do {
            $attempts++;
            try {
                return $this->client->$method(...$args);
            } catch (Sabre\HTTP\ClientException $e) {
                if ($attempts === 5) {
                    throw $e;
                } else {
                    IO::write('attempt failed, retrying in 10 seconds...');
                    sleep(10);
                }
            }
        } while ($attempts < 6);
    }
}
