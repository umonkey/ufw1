<?php

/**
 * Upload files using S3.
 **/

declare(strict_types=1);

namespace Ufw1\Services;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class S3
{
    /**
     * @var array
     **/
    protected $settings;

    /**
     * @var LoggerInterface
     **/
    protected $logger;

    /**
     * FIXME!  Does not belong here.
     * @var NodeRepository
     **/
    protected $node;

    /**
     * @var TaskQueue
     **/
    protected $taskq;

    public function __construct(array $settings, LoggerInterface $logger, NodeRepository $node, TaskQueue $taskq)
    {
        if (empty($settings)) {
            throw new \RuntimeException("S3 not configured, at all");
        }

        $keys = ['bucket', 'bucket_region', 'acl', 'secret_key', 'access_key', 'endpoint'];
        foreach ($keys as $key) {
            if (empty($settings[$key])) {
                throw new \RuntimeException("S3 not configured: {$key} not set");
            }
        }

        $settings = array_replace([
            'service' => 's3',
            'debug' => true,
        ], $settings);

        $this->settings = $settings;

        $this->logger = $logger;

        $this->node = $node;

        $this->taskq = $taskq;
    }

    /**
     * Returns a list of all files in the bucket.
     **/
    public function getFileList()
    {
        $files = [];

        $time = time();
        $bucket = $this->settings["bucket"];
        $endpoint = $this->settings["endpoint"];

        $url = "https://" . $endpoint . "/" . $bucket;
        $res = $this->doRequest("GET", $url);

        $xml = new \SimpleXMLIterator($res[1]);
        $data = json_decode(json_encode($xml), true);

        if (!empty($data['Code'])) {
            $this->logger->error('S3 error: {data}', [
                'data' => $data,
            ]);

            if ($data["Code"] == "AccessDenied") {
                throw new \Ufw1\Errors\S3AccessDenied();
            } else {
                throw new \RuntimeException("Cloud storage error: " . $data["Message"]);
            }
        }

        if (empty($data['Contents'])) {
            return [];
        }

        $contents = $data['Contents'];
        if (isset($contents['Key'])) {
            $contents = [$contents];
        }

        $files = array_map(function ($em) use ($endpoint, $bucket) {
            return [
                "name" => $em["Key"],
                "size" => $em["Size"],
                "date" => $em["LastModified"],
                "url" => $em["Key"][-1] == "/" ? null : "https://{$endpoint}/{$bucket}/{$em["Key"]}",
                "etag" => $em["ETag"],
            ];
        }, $contents);

        return $files;
    }

    /**
     * Upload a file.
     *
     * @param string $name Remote name.
     * @param string $src Local file path.
     * @return string Remote URL.
     **/
    public function putObject($dst, $src, $props = array())
    {
        if (!is_readable($src)) {
            throw new \RuntimeException("source file not readable: {$src}");
        }

        return $this->putObjectBody($dst, file_get_contents($src), $props);
    }

    public function putObjectBody($dst, $data, $props = [])
    {
        if ($dst[0] != "/") {
            throw new \RuntimeException("remote path must be absolute");
        }

        $dst = $this->fixUnicodeTarget($dst);

        $props = array_merge(array(
            "type" => null,
            "acl" => $this->settings["acl"],
            "storage_class" => "STANDARD",
            ), $props);

        if ($props["type"] === null) {
            $props["type"] = "application/octet-stream";
        }

        $time = time();

        $headers = array(
            "Content-Length" => strlen($data),
            "Content-MD5" => base64_encode(md5($data, true)),
            "Content-Type" => $props["type"],
            "Date" => gmdate('r', $time),
            "x-amz-acl" => $props["acl"],
            "x-amz-content-sha256" => hash("sha256", $data),
            "x-amz-date" => gmdate("Ymd", $time) . "T" . gmdate("His", $time) . "Z",
            "x-amz-storage-class" => $props["storage_class"],
            );

        $res = $this->doRequest("PUT", $_url = "https://{$this->settings["bucket"]}.{$this->settings["endpoint"]}{$dst}", $headers, $data);

        if ($res[0]['status'] != 200) {
            $_url = null;
        }

        return [$res[0], $res[1], $_url];
    }

    protected function doRequest($method, $url, array $headers = [], $payload = "")
    {
        $time = time();
        if (!isset($headers["Date"])) {
            $headers["Date"] = gmdate('r', $time);
        }
        if (!isset($headers["x-amz-date"])) {
            $headers["x-amz-date"] = gmdate("Ymd", $time) . "T" . gmdate("His", $time) . "Z";
        }
        if (!isset($headers["x-amz-content-sha256"])) {
            $headers["x-amz-content-sha256"] = hash("sha256", $payload ? $payload : "");
        }

        $props = $this->prepare($method, $url, $headers, $payload);
        $headers["Authorization"] = $props["Authorization"];

        if (function_exists("curl_init")) {
            $res = $this->doRequestCurl($method, $url, $payload, $headers);
        } else {
            $res = $this->doRequestStream($method, $url, $payload, $headers);
        }

        return $res;
    }

    protected function doRequestCurl($method, $url, $payload, array $headers)
    {
        $h = array();
        foreach ($headers as $k => $v) {
            $h[] = $k . ": " . $v;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

        if ($this->settings['debug']) {
            $this->logger->debug("S3: performing {method} request to {url}, headers: {headers}", [
                "method" => $method,
                "url" => $url,
                "headers" => $headers,
            ]);
        }

        // Track incoming headers.
        $resHeaders = array();
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$resHeaders) {
            if (preg_match('@^HTTP/[0-9.]+ (\d+) .+@', $header, $m)) {
                $resHeaders["status"] = $m[1];

                if ($this->settings['debug']) {
                    $this->logger->debug("S3: response status: {status}", [
                        "status" => $m[1],
                    ]);
                }
            } elseif (2 == count($parts = explode(":", $header, 2))) {
                $k = strtolower($parts[0]);
                $v = trim($parts[1]);
                $resHeaders[$k] = $v;

                if ($this->settings['debug']) {
                    $this->logger->debug("S3: response header: {k} = {v}", [
                        "k" => $k,
                        "v" => $v,
                    ]);
                }
            } elseif (false) {
                if ($this->settings['debug']) {
                    $this->logger->debug("S3: unrecognized header: {header}", [
                        "header" => trim($header),
                    ]);
                }
            }

            return strlen($header);
        });

        // Track and log progress.
        $reqts = microtime(true);
        $ulast = microtime(true);
        $total = null;
        curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function ($ch, $dlt, $dl, $ult, $ul) use ($reqts, &$ulast, &$total) {
            // error_log(sprintf("S3: ul=%u ulast=%u", $ul, $ulast));

            $now = microtime(true);
            if (($now - $ulast) >= 5) {
                $ulast = $now;

                $dur = $now - $reqts;
                $rate = $ul / $dur;
                $percent = $ult ? $ul * 100 / $ult : 0;

                $total = $ult;

                if ($this->settings['debug']) {
                    $this->logger->debug("S3: sent {sent} of {total} KB ({percent}%), rate: {rate} KB/sec.", [
                        "sent" => round($ul / 1024),
                        "total" => round($ult / 1024),
                        "percent" => round($percent),
                        "rate" => sprintf("%.2f", $rate / 1024),
                    ]);
                }
            }
        });

        $res = curl_exec($ch);
        curl_close($ch);

        /*
        $now = microtime(true);
        $dur = $now - $reqts;
        $rate = $total / $dur;

        if ($this->settings['debug']) {
            $this->logger->debug("S3: file {name} sent ({size} KB), overall rate: {rate} KB/sec.", [
                "name" => basename($url),
                "size" => round($total / 1024),
                "rate" => sprintf("%.2f", $rate / 1024),
            ]);
        }
        */

        return array($resHeaders, $res);
    }

    protected function doRequestStream($method, $url, $payload, array $headers)
    {
        $h = "";
        foreach ($headers as $k => $v) {
            $h .= "{$k}: {$v}\r\n";
        }

        $context = stream_context_create($ctx = array(
            "http" => array(
                "method" => $method,
                "header" => $h,
                "content" => $payload,
                "ignore_errors" => true,
                "follow_location" => false,
                ),
            ));

        $res = @file_get_contents($url, false, $context);

        $rhead = array();
        foreach ($http_response_header as $idx => $h) {
            if ($idx == 0) {
                $parts = explode(" ", $h);
                $rhead["status"] = $h[1];
            } else {
                $parts = explode(":", $h, 2);
                $k = strtolower($parts[0]);
                $v = trim($parts[1]);
                $rhead[$k] = $v;
            }
        }

        return array($rhead, $res);
    }

    public function prepare($method, $url, array $headers, $payload)
    {
        if (false === strpos($url, "://")) {
            throw new RuntimeException("url must be fully qualified");
        }

        $parts = explode("/", $url, 4);
        $headers["Host"] = $parts[2];
        $url = "/" . $parts[3];

        $res = array();
        $res["CanonicalRequest"] = $this->getCanonicalRequest($method, $url, $headers, $payload);
        $res["CanonicalRequestHash"] = hash("sha256", $res["CanonicalRequest"]);
        $res["StringToSign"] = $this->getStringToSign($headers, $res);

        $kSigning = $this->getSigningKey($headers);
        $res["SigningKey"] = bin2hex($kSigning);

        $res["Signature"] = $this->hmac($kSigning, $res["StringToSign"], false);

        $res["Authorization"] = $this->getAuthorization($headers, $res["Signature"]);

        return $res;
    }

    protected function getCanonicalRequest($method, $url, array $headers, $payload)
    {
        $tmp = explode("?", $url, 2);

        $CanonicalURI = $tmp[0];

        $CanonicalQueryString = count($tmp) == 2 ? $tmp[1] : "";

        ksort($headers);
        $CanonicalHeaders = "";
        foreach ($headers as $k => $v) {
            $CanonicalHeaders .= strtolower($k) . ":" . trim($v) . "\n";
        }

        $SignedHeaders = array();
        foreach ($headers as $k => $v) {
            $SignedHeaders[] = strtolower($k);
        }
        $SignedHeaders = implode(";", $SignedHeaders);

        $HashedPayload = hash("sha256", $payload);

        $CanonicalRequest = "{$method}\n"
                          . "{$CanonicalURI}\n"
                          . "{$CanonicalQueryString}\n"
                          . "{$CanonicalHeaders}\n"
                          . "{$SignedHeaders}\n"
                          . "{$HashedPayload}";

        return $CanonicalRequest;
    }

    protected function getStringToSign(array $headers, array $res)
    {
        $date = strftime("%Y%m%d");
        foreach ($headers as $k => $v) {
            if (strtolower($k) == "x-amz-date") {
                $date = $v;
            }
        }

        $ymd = substr($date, 0, 8);

        $sts = "AWS4-HMAC-SHA256\n"
             . "{$date}\n"
             . "{$ymd}/{$this->settings["bucket_region"]}/{$this->settings["service"]}/aws4_request\n"
             . hash("sha256", $res["CanonicalRequest"]);

        return $sts;
    }

    /**
     * Prepare the signing key.
     *
     * Tested, works well.
     * https://docs.aws.amazon.com/general/latest/gr/signature-v4-examples.html#signature-v4-examples-python
     **/
    protected function getSigningKey(array $headers)
    {
        $kSecret = $this->settings["secret_key"];
        $Date = substr($this->getDate($headers), 0, 8);
        $Region = $this->settings["bucket_region"];
        $Service = $this->settings["service"];

        $kDate = $this->hmac("AWS4" . $kSecret, $Date);
        $kRegion = $this->hmac($kDate, $Region);
        $kService = $this->hmac($kRegion, $Service);
        $kSigning = $this->hmac($kService, "aws4_request");

        return $kSigning;
    }

    protected function getAuthorization(array $headers, $signature)
    {
        $date = substr($this->getDate($headers), 0, 8);
        $Credential = "{$this->settings["access_key"]}/{$date}/{$this->settings["bucket_region"]}/{$this->settings["service"]}/aws4_request";

        $sh = array();
        foreach ($headers as $k => $v) {
            $sh[] = strtolower($k);
        }
        sort($sh);
        $SignedHeaders = implode(";", $sh);

        $auth = "AWS4-HMAC-SHA256 Credential={$Credential}, SignedHeaders={$SignedHeaders}, Signature={$signature}";
        return $auth;
    }

    public function test()
    {
        $this->test1();

        $this->config = array(
            "service" => "service",
            "bucket_region" => "us-east-1",
            "access_key" => "AKIDEXAMPLE",
            "secret_key" => "wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY",
            );

        $res = $this->prepare("GET", "http://example.amazonaws.com/?Param1=value1&Param2=value2", array(
            "X-Amz-Date" => "20150830T123600Z",
            ), null);

        foreach ($res as $k => $v) {
            $lines = explode("\n", $v);
            foreach ($lines as $line) {
                printf("%s: %s\n", $k, $line);
            }
        }

        $this->check("CanonicalRequestHash", "816cd5b414d056048ba4f7c5386d6e0533120fb1fcfa93762cf0fc39e2cf19e0", $res["CanonicalRequestHash"]);
        $this->check("StringToSign", "AWS4-HMAC-SHA256\n20150830T123600Z\n20150830/us-east-1/service/aws4_request\n816cd5b414d056048ba4f7c5386d6e0533120fb1fcfa93762cf0fc39e2cf19e0", $res["StringToSign"]);
        $this->check("Signature", "b97d918cfa904a5beff61c982a1b6f458b799221646efd99d3219ec94cdf2500", $res["Signature"]);

        printf("Works well!\n");
    }

    public function test1()
    {
        $this->settings["secret_key"] = "wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY";
        $this->settings["bucket_region"] = "us-east-1";
        $this->settings["service"] = "iam";

        $kSigning = $this->getSigningKey(array(
            "X-Amz-Date" => "20150830T123600Z",
            ));

        $this->check("kSigning", "c4afb1cc5771d871763a393e44b703571b55cc28424d1a5e86da6ed3c154a4b9", bin2hex($kSigning));
    }

    protected function hmac($key, $data, $raw = true)
    {
        return hash_hmac("sha256", $data, $key, $raw);
    }

    protected function getDate(array $headers)
    {
        foreach ($headers as $k => $v) {
            if (strtolower($k) == "x-amz-date") {
                return $v;
            }
        }
        return strftime("%Y%m%d");
    }

    protected function check($var, $wanted, $got)
    {
        if ($wanted != $got) {
            printf("%s is wrong.\n", $var);
            printf("%s wanted: %s\n", $var, $wanted);
            printf("%s got:    %s\n", $var, $got);
            exit(1);
        }
    }

    protected function fixUnicodeTarget($dst)
    {
        $parts = explode("/", $dst);
        foreach ($parts as $k => $v) {
            $parts[$k] = rawurlencode($v);
        }
        return implode("/", $parts);
    }

    /**
     * Upload node files to remote storage.
     **/
    public function uploadNodeFiles(array $node)
    {
        if (!array_key_exists("type", $node)) {
            return $node;
        }

        if ($node["type"] != "file") {
            return $node;
        }

        if (empty($node["files"]) or !is_array($node["files"])) {
            return $node;
        }

        $lstorage = $this->settings["files"]["path"] ?? $_SERVER['DOCUMENT_ROOT'] . '/../data/files';

        $unlink = [];

        foreach ($node["files"] as $part => &$file) {
            if (isset($file["storage"]) and $file["storage"] == "local") {
                $src = $lstorage . "/" . $file["path"];
                if (!is_readable($src)) {
                    $this->logger->warning("s3: source file {src} is not readable, cannot upload node/{id}/{part}.", [
                        "src" => $src,
                        "id" => $node["id"],
                        "part" => $part,
                    ]);
                    continue;
                }

                $this->logger->debug("s3: uploading node/{id}/{part} to S3 as {path}, {len} bytes.", [
                    "id" => $node["id"],
                    "part" => $part,
                    "len" => $file["length"],
                    "path" => $file["path"],
                ]);

                $rpath = "/" . $file["path"];
                if ($part == "original") {
                    // $rpath .= "/" . urlencode($node["name"]);  // has problems with unicode
                    $rpath .= "/original";
                } elseif ($node["kind"] == "photo") {
                    $rpath .= "/image.jpg";
                }

                $res = $this->putObject($rpath, $src, [
                    "type" => $file["type"],
                    "acl" => "public-read",
                ]);

                $this->logger->debug("s3 response: {res}", [
                    "res" => $res,
                ]);

                if ($res[0]["status"] != 200) {
                    $this->logger->error("s3: error uploading file {path}", ["path" => $rpath]);
                } else {
                    $this->logger->info("s3: node {id}: file {path} uploaded to S3.", [
                        "id" => $node["id"],
                        "path" => $file["path"],
                    ]);

                    $file["storage"] = "s3";
                    $file["url"] = "https://{$this->settings['bucket']}.{$this->settings['endpoint']}{$rpath}";

                    $unlink[] = $src;
                }
            }
        }

        if (!empty($unlink)) {
            $this->node->save($node);

            $this->taskq->add('S3.unlinkFilesTask', [
                'files' => $unlink,
            ]);
        }

        return $node;
    }

    public function unlinkFilesTask(array $payload)
    {
        if (!empty($payload['files'])) {
            foreach ($payload['files'] as $src) {
                unlink($src);
                $this->logger->info('s3: deleted local file {0}', [$src]);
            }
        }
    }

    /**
     * Upload a node by id.
     *
     * This is a taskq handler.
     **/
    public function uploadNodeTask(array $payload)
    {
        $id = $payload['id'];

        $force = $payload['force'] ?? false;
        if (!$force) {
            $st = $this->settings['auto_upload'];
        }

        $node = $this->node->get($id);
        if (!empty($node)) {
            $this->uploadNodeFiles($node);
        }
    }

    public function autoUploadNode(array $node, $force = false)
    {
        $auto = $this->settings['auto_upload'] ?? false;

        if ($auto or $force) {
            $this->taskq->add('S3.uploadNodeTask', [
                'id' => $node['id'],
            ]);
        } else {
            $this->logger->info('S3: refusing to auto-upload node {id}: disabled.', [
                'id' => $node['id'],
            ]);
        }
    }
}
