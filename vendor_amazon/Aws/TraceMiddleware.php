<?php

namespace YaySMTP\Aws3\Aws;

use YaySMTP\Aws3\Aws\Exception\AwsException;
use YaySMTP\Aws3\GuzzleHttp\Promise\RejectedPromise;
use YaySMTP\Aws3\Psr\Http\Message\RequestInterface;
use YaySMTP\Aws3\Psr\Http\Message\ResponseInterface;
use YaySMTP\Aws3\Psr\Http\Message\StreamInterface;

/**
 * Traces state changes between middlewares.
 */
class TraceMiddleware {
  private $prevOutput;
  private $prevInput;
  private $config;
  private static $authHeaders = ['X-Amz-Security-Token' => '[TOKEN]'];
  private static $authStrings = [
    // S3Signature
    '/AWSAccessKeyId=[A-Z0-9]{20}&/i' => 'AWSAccessKeyId=[KEY]&',
    // SignatureV4 Signature and S3Signature
    '/Signature=.+/i' => 'Signature=[SIGNATURE]',
    // SignatureV4 access key ID
    '/Credential=[A-Z0-9]{20}\\//i' => 'Credential=[KEY]/',
    // S3 signatures
    '/AWS [A-Z0-9]{20}:.+/' => 'AWS AKI[KEY]:[SIGNATURE]',
    // STS Presigned URLs
    '/X-Amz-Security-Token=[^&]+/i' => 'X-Amz-Security-Token=[TOKEN]',
    // Crypto *Stream Keys
    '/\\["key.{27,36}Stream.{9}\\]=>\\s+.{7}\\d{2}\\) "\\X{16,64}"/U' => '["key":[CONTENT KEY]]',
  ];
  /**
   * Configuration array can contain the following key value pairs.
   *
   * - logfn: (callable) Function that is invoked with log messages. By
   *   default, PHP's "echo" function will be utilized.
   * - stream_size: (int) When the size of a stream is greater than this
   *   number, the stream data will not be logged. Set to "0" to not log any
   *   stream data.
   * - scrub_auth: (bool) Set to false to disable the scrubbing of auth data
   *   from the logged messages.
   * - http: (bool) Set to false to disable the "debug" feature of lower
   *   level HTTP adapters (e.g., verbose curl output).
   * - auth_strings: (array) A mapping of authentication string regular
   *   expressions to scrubbed strings. These mappings are passed directly to
   *   preg_replace (e.g., preg_replace($key, $value, $debugOutput) if
   *   "scrub_auth" is set to true.
   * - auth_headers: (array) A mapping of header names known to contain
   *   sensitive data to what the scrubbed value should be. The value of any
   *   headers contained in this array will be replaced with the if
   *   "scrub_auth" is set to true.
   */
  public function __construct(array $config = []) {
    $this->config = $config + ['logfn' => function ($value) {
      echo $value;
    }, 'stream_size' => 524288, 'scrub_auth' => true, 'http' => true, 'auth_strings' => [], 'auth_headers' => []];
    $this->config['auth_strings'] += self::$authStrings;
    $this->config['auth_headers'] += self::$authHeaders;
  }
  public function __invoke($step, $name) {
    $this->prevOutput = $this->prevInput = [];
    return function (callable $next) use ($step, $name) {
      return function (\YaySMTP\Aws3\Aws\CommandInterface $command, \YaySMTP\Aws3\Psr\Http\Message\RequestInterface $request = null) use ($next, $step, $name) {
        $this->createHttpDebug($command);
        $start = microtime(true);
        $this->stepInput(['step' => $step, 'name' => $name, 'request' => $this->requestArray($request), 'command' => $this->commandArray($command)]);
        return $next($command, $request)->then(function ($value) use ($step, $name, $command, $start) {
          $this->flushHttpDebug($command);
          $this->stepOutput($start, ['step' => $step, 'name' => $name, 'result' => $this->resultArray($value), 'error' => null]);
          return $value;
        }, function ($reason) use ($step, $name, $start, $command) {
          $this->flushHttpDebug($command);
          $this->stepOutput($start, ['step' => $step, 'name' => $name, 'result' => null, 'error' => $this->exceptionArray($reason)]);
          return new \YaySMTP\Aws3\GuzzleHttp\Promise\RejectedPromise($reason);
        });
      };
    };
  }
  private function stepInput($entry) {
    static $keys = ['command', 'request'];
    $this->compareStep($this->prevInput, $entry, '-> Entering', $keys);
    $this->write("\n");
    $this->prevInput = $entry;
  }
  private function stepOutput($start, $entry) {
    static $keys = ['result', 'error'];
    $this->compareStep($this->prevOutput, $entry, '<- Leaving', $keys);
    $totalTime = microtime(true) - $start;
    $this->write("  Inclusive step time: " . $totalTime . "\n\n");
    $this->prevOutput = $entry;
  }
  private function compareStep(array $a, array $b, $title, array $keys) {
    $changes = [];
    foreach ($keys as $key) {
      $av = isset($a[$key]) ? $a[$key] : null;
      $bv = isset($b[$key]) ? $b[$key] : null;
      $this->compareArray($av, $bv, $key, $changes);
    }
    $str = "\n{$title} step {$b['step']}, name '{$b['name']}'";
    $str .= "\n" . str_repeat('-', strlen($str) - 1) . "\n\n  ";
    $str .= $changes ? implode("\n  ", str_replace("\n", "\n  ", $changes)) : 'no changes';
    $this->write($str . "\n");
  }
  private function commandArray(\YaySMTP\Aws3\Aws\CommandInterface $cmd) {
    return ['instance' => spl_object_hash($cmd), 'name' => $cmd->getName(), 'params' => $cmd->toArray()];
  }
  private function requestArray(\YaySMTP\Aws3\Psr\Http\Message\RequestInterface $request = null) {
    return !$request ? [] : array_filter(['instance' => spl_object_hash($request), 'method' => $request->getMethod(), 'headers' => $this->redactHeaders($request->getHeaders()), 'body' => $this->streamStr($request->getBody()), 'scheme' => $request->getUri()->getScheme(), 'port' => $request->getUri()->getPort(), 'path' => $request->getUri()->getPath(), 'query' => $request->getUri()->getQuery()]);
  }
  private function responseArray(\YaySMTP\Aws3\Psr\Http\Message\ResponseInterface $response = null) {
    return !$response ? [] : ['instance' => spl_object_hash($response), 'statusCode' => $response->getStatusCode(), 'headers' => $this->redactHeaders($response->getHeaders()), 'body' => $this->streamStr($response->getBody())];
  }
  private function resultArray($value) {
    return $value instanceof ResultInterface ? ['instance' => spl_object_hash($value), 'data' => $value->toArray()] : $value;
  }
  private function exceptionArray($e) {
    if (!$e instanceof \Exception) {
      return $e;
    }
    $result = ['instance' => spl_object_hash($e), 'class' => get_class($e), 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine(), 'trace' => $e->getTraceAsString()];
    if ($e instanceof AwsException) {
      $result += ['type' => $e->getAwsErrorType(), 'code' => $e->getAwsErrorCode(), 'requestId' => $e->getAwsRequestId(), 'statusCode' => $e->getStatusCode(), 'result' => $this->resultArray($e->getResult()), 'request' => $this->requestArray($e->getRequest()), 'response' => $this->responseArray($e->getResponse())];
    }
    return $result;
  }
  private function compareArray($a, $b, $path, array &$diff) {
    if ($a === $b) {
      return;
    }
    if (is_array($a)) {
      $b = (array) $b;
      $keys = array_unique(array_merge(array_keys($a), array_keys($b)));
      foreach ($keys as $k) {
        if (!array_key_exists($k, $a)) {
          $this->compareArray(null, $b[$k], "{$path}.{$k}", $diff);
        } elseif (!array_key_exists($k, $b)) {
          $this->compareArray($a[$k], null, "{$path}.{$k}", $diff);
        } else {
          $this->compareArray($a[$k], $b[$k], "{$path}.{$k}", $diff);
        }
      }
    } elseif ($a !== null && $b === null) {
      $diff[] = "{$path} was unset";
    } elseif ($a === null && $b !== null) {
      $diff[] = sprintf("%s was set to %s", $path, $this->str($b));
    } else {
      $diff[] = sprintf("%s changed from %s to %s", $path, $this->str($a), $this->str($b));
    }
  }
  private function str($value) {
    if (is_scalar($value)) {
      return (string) $value;
    }
    if ($value instanceof \Exception) {
      $value = $this->exceptionArray($value);
    }
    ob_start();
    var_dump($value);
    return ob_get_clean();
  }
  private function streamStr(\YaySMTP\Aws3\Psr\Http\Message\StreamInterface $body) {
    return $body->getSize() < $this->config['stream_size'] ? (string) $body : 'stream(size=' . $body->getSize() . ')';
  }
  private function createHttpDebug(\YaySMTP\Aws3\Aws\CommandInterface $command) {
    if ($this->config['http'] && !isset($command['@http']['debug'])) {
      $command['@http']['debug'] = fopen('php://temp', 'w+');
    }
  }
  private function flushHttpDebug(\YaySMTP\Aws3\Aws\CommandInterface $command) {
    if ($res = $command['@http']['debug']) {
      rewind($res);
      $this->write(stream_get_contents($res));
      fclose($res);
      $command['@http']['debug'] = null;
    }
  }
  private function write($value) {
    if ($this->config['scrub_auth']) {
      foreach ($this->config['auth_strings'] as $pattern => $replacement) {
        $value = preg_replace($pattern, $replacement, $value);
      }
    }
    call_user_func($this->config['logfn'], $value);
  }
  private function redactHeaders(array $headers) {
    if ($this->config['scrub_auth']) {
      $headers = $this->config['auth_headers'] + $headers;
    }
    return $headers;
  }
}
