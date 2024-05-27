<?php

namespace Root\Protocols\Hls;


use Hls\Configure;
use Hls\ErrNoPublisher;
use Hls\Http;
use Hls\Info;


const DURATION = 3000;
const CROSS_DOMAIN_XML = '<?xml version="1.0"?>
<cross-domain-policy>
    <allow-access-from domain="*" />
    <allow-http-request-headers-from domain="*" headers="*"/>
</cross-domain-policy>';

class Server {
    protected $listener;
    protected $conns;

    public function __construct() {
        $this->conns = new \SplObjectStorage();
    }

    public function serve($listener) {
        $mux = new \Http\ServeMux();
        $mux->map('/', function(Http\Request $request, Http\Response $response) {
            $this->handle($response, $request);
        });

        $this->listener = $listener;

        if (Configure::$config['use_hls_https']) {
            $http->serveTLS($listener, $mux, 'erver.crt','server.key');
        } else {
            $http->serve($listener, $mux);
        }
    }

    public function getWriter(Info $info) {
        if (!$this->conns->contains($info->key)) {
            $source = new Source($info);
            $this->conns->attach($info->key, $source);
        } else {
            $source = $this->conns->get($info->key);
        }

        return $source;
    }

    public function getConn($key) {
        return $this->conns->get($key);
    }

    public function checkStop() {
        while (true) {
            sleep(5);

            $this->conns->uasort(function($a, $b) {
                $sourceA = $a->getValue();
                $sourceB = $b->getValue();

                if (!$sourceA->alive() &&!Configure::$config['hls_keep_after_end']) {
                    $this->conns->detach($a->getKey());
                }
            });
        }
    }

    public function handle(Http\Response $response, Http\Request $request) {
        if (basename($request->getPathInfo()) == 'crossdomain.xml') {
            $response->headers->set('Content-Type', 'application/xml');
            $response->write(CROSS_DOMAIN_XML);
            return;
        }

        $ext = pathinfo($request->getPathInfo(), PATHINFO_EXTENSION);
        switch ($ext) {
            case'm3u8':
                list($key, $err) = $this->parseM3u8($request->getPathInfo());
                if ($err) {
                    http_response_code(400);
                    $response->write($err->getMessage());
                    return;
                }

                $conn = $this->getConn($key);
                if (!$conn) {
                    http_response_code(403);
                    $response->write(ErrNoPublisher::getMessage());
                    return;
                }

                $tsCache = $conn->getCacheInc();
                if (!$tsCache) {
                    http_response_code(403);
                    $response->write(ErrNoPublisher::getMessage());
                    return;
                }

                $body = $tsCache->genM3U8PlayList();
                if ($body === false) {
                    http_response_code(400);
                    $response->write(ErrNoPublisher::getMessage());
                    return;
                }

                $response->headers->set('Access-Control-Allow-Origin', '*');
                $response->headers->set('Cache-Control', 'no-cache');
                $response->headers->set('Content-Type', 'application/x-mpegURL');
                $response->headers->set('Content-Length', strlen($body));
                $response->write($body);
                break;
            case 'ts':
                list($key, $err) = $this->parseTs($request->getPathInfo());
                if ($err) {
                    http_response_code(400);
                    $response->write($err->getMessage());
                    return;
                }

                $conn = $this->getConn($key);
                if (!$conn) {
                    http_response_code(403);
                    $response->write(ErrNoPublisher::getMessage());
                    return;
                }

                $tsCache = $conn->getCacheInc();
                $item = $tsCache->getItem($request->getPathInfo());
                if (!$item) {
                    http_response_code(400);
                    $response->write(ErrNoPublisher::getMessage());
                    return;
                }

                $response->headers->set('Access-Control-Allow-Origin', '*');
                $response->headers->set('Content-Type', 'video/mp2ts');
                $response->headers->set('Content-Length', strlen($item['data']));
                $response->write($item['data']);
        }
    }

    public function parseM3u8($pathstr) {
        $pathstr = ltrim($pathstr, '/');
        $key = explode('/', $pathstr)[0];
        return [$key, null];
    }

    public function parseTs($pathstr) {
        $pathstr = ltrim($pathstr, '/');
        $paths = explode('/', $pathstr, 3);
        if (count($paths)!= 3) {
            return [null, new \Exception('Invalid path='. $pathstr)];
        }
        $key = $paths[0]. '/'. $paths[1];
        return [$key, null];
    }
}
?>