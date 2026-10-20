<?php
declare(strict_types=1);
namespace RadioStamp\Discord;

/** No redirects, proxy inheritance, cookies, verbose output, or raw error text. */
final class CurlHttpAdapter implements HttpAdapter
{
    public function exchange(HttpRequest $request): HttpResponse
    {
        if (!extension_loaded('curl')) { return new HttpResponse(0, [], '', 'transport_unavailable'); }
        if ($request->asyncDnsRequired && (((curl_version()['features'] ?? 0) & CURL_VERSION_ASYNCHDNS) === 0)) { return new HttpResponse(0, [], '', 'transport_async_dns_required'); }
        $handle = null;
        try {
            $handle = curl_init($request->url());
            if ($handle === false) { return new HttpResponse(0, [], '', 'transport_unavailable'); }
            $headers = []; $body = ''; $headerBytes = 0; $tooLarge = false;
            $options = [
                CURLOPT_CUSTOMREQUEST => $request->method(), CURLOPT_HTTPHEADER => $request->headers(),
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_TIMEOUT_MS => $request->timeoutMs, CURLOPT_CONNECTTIMEOUT_MS => $request->connectTimeoutMs,
                CURLOPT_PROXY => '', CURLOPT_NETRC => CURL_NETRC_IGNORED, CURLOPT_VERBOSE => false,
                CURLOPT_HEADER => false, CURLOPT_RETURNTRANSFER => false,
                CURLOPT_WRITEFUNCTION => static function ($unused, string $chunk) use (&$body, &$tooLarge, $request): int {
                    if (strlen($body) + strlen($chunk) > $request->maxResponseBytes) { $tooLarge = true; return 0; }
                    $body .= $chunk; return strlen($chunk);
                },
                CURLOPT_HEADERFUNCTION => static function ($unused, string $line) use (&$headers, &$headerBytes, &$tooLarge): int {
                    $headerBytes += strlen($line);
                    if ($headerBytes > 16384) { $tooLarge = true; return 0; }
                    if (str_starts_with($line, 'HTTP/')) { $headers = []; }
                    if (str_contains($line, ':')) {
                        [$name, $value] = explode(':', $line, 2); $name = strtolower(trim($name));
                        if (in_array($name, ['retry-after', 'x-ratelimit-limit', 'x-ratelimit-remaining', 'x-ratelimit-reset-after', 'x-ratelimit-bucket', 'x-ratelimit-global', 'x-ratelimit-scope'], true)) {
                            $headers[$name] = substr(trim($value), 0, 256);
                        }
                    }
                    return strlen($line);
                },
            ];
            if ($request->method() !== 'GET') { $options[CURLOPT_POSTFIELDS] = $request->body(); }
            if (!curl_setopt_array($handle, $options)) { return new HttpResponse(0, [], '', 'transport_unavailable'); }
            $success = curl_exec($handle);
            if ($success === false) {
                $error = $tooLarge ? 'response_too_large' : (curl_errno($handle) === CURLE_OPERATION_TIMEDOUT ? 'transport_timeout' : 'transport_failed');
                return new HttpResponse(0, [], '', $error);
            }
            return new HttpResponse((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $headers, $body);
        } catch (\Throwable) { return new HttpResponse(0, [], '', 'transport_failed'); }
        finally { if ($handle !== null && $handle !== false) { curl_close($handle); } }
    }
}
