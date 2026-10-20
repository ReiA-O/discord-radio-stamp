<?php
declare(strict_types=1);
require_once __DIR__.'/artifact_directory.php';
require_once dirname(__DIR__) . '/bootstrap.php';

use RadioStamp\Core\FrozenClock;
use RadioStamp\Discord\DiscordRestClient;
use RadioStamp\Discord\HttpAdapter;
use RadioStamp\Discord\HttpRequest;
use RadioStamp\Discord\HttpResponse;
use RadioStamp\Discord\PresenceDiagnostic;
use RadioStamp\Discord\RestGateway;
use RadioStamp\Discord\RestResult;
use RadioStamp\Http\PrivateConfig as Scope;

$pdChecks = 0; $pdDescription = '';
function pdCheck(bool $value, string $description): void { global $pdChecks, $pdDescription; ++$pdChecks; $pdDescription = $description; if (!$value) { throw new RuntimeException('offline assertion failed'); } }
function pdSame(mixed $expected, mixed $actual, string $description): void { pdCheck($expected === $actual, $description); }
final class PdHttp implements HttpAdapter
{
    public int $calls = 0;
    public function __construct(private readonly HttpResponse $response) {}
    public function exchange(HttpRequest $request): HttpResponse
    {
        ++$this->calls;
        pdSame('GET', $request->method(), 'one existing GET only');
        pdSame('https://discord.com/api/v10/guilds/' . Scope::GUILD_ID . '/voice-states/' . Scope::OWNER_ID, $request->url(), 'fixed owner route unchanged');
        return $this->response;
    }
}
$pdFailed = false;
try {
    $clock = new FrozenClock('2026-10-19T09:00:00+09:00');
    $valid = ['guild_id' => Scope::GUILD_ID, 'user_id' => Scope::OWNER_ID, 'channel_id' => Scope::VOICE_ID,
        'self_mute' => true, 'self_deaf' => true, 'private_extra' => 'FICTIONAL_RESPONSE_BODY_NOT_TO_BE_RETAINED'];
    $json = static fn(array $value): string => json_encode($value, JSON_THROW_ON_ERROR);
    $cases = [
        ['present', new HttpResponse(200, [], $json($valid)), 200, 'ok', true],
        ['known-outside', new HttpResponse(200, [], $json(['channel_id' => null] + array_diff_key($valid, ['channel_id' => true]))), 200, 'ok', true],
        ['not-found', new HttpResponse(404, [], $json(['code' => 10065, 'message' => 'FICTIONAL_RESPONSE_BODY_NOT_TO_BE_RETAINED'])), 404, 'not_found', false],
        ['forbidden', new HttpResponse(403, [], $json(['message' => 'FICTIONAL_RESPONSE_BODY_NOT_TO_BE_RETAINED'])), 403, 'forbidden', false],
        ['unauthorized', new HttpResponse(401, [], '{}'), 401, 'unauthorized', false],
        ['rate-limit', new HttpResponse(429, [], '{"retry_after":1}'), 429, 'rate_limited', false],
        ['upstream', new HttpResponse(503, [], '{}'), 503, 'discord_unavailable', false],
        ['redirect', new HttpResponse(302, [], '{}'), 302, 'redirect_refused', false],
        ['bad-json', new HttpResponse(200, [], '{"FICTIONAL_RESPONSE_BODY_NOT_TO_BE_RETAINED":'), 200, 'response_invalid', false],
        ['bad-schema', new HttpResponse(200, [], $json(['user_id' => '900000000000007777', 'channel_id' => Scope::VOICE_ID])), 200, 'response_invalid', false],
        ['empty-schema', new HttpResponse(200), 200, 'response_invalid', false],
        ['timeout', new HttpResponse(0, [], '', 'transport_timeout'), null, 'transport_timeout', false],
        ['unavailable', new HttpResponse(0, [], '', 'transport_unavailable'), null, 'transport_unavailable', false],
        ['untrusted-error', new HttpResponse(0, [], '', 'FICTIONAL_RESPONSE_BODY_NOT_TO_BE_RETAINED'), null, 'transport_failed', false],
    ];
    foreach ($cases as [$label, $response, $status, $classification, $known]) {
        $http = new PdHttp($response); $observations = [];
        $client = new DiscordRestClient($http, Scope::APPLICATION_ID, 'FICTIONAL_DIAGNOSTIC_BOT_AUTH_ONLY', ['enabled' => true]);
        $gateway = new RestGateway($client, $clock, static function (PresenceDiagnostic $diagnostic) use (&$observations): void { $observations[] = $diagnostic; });
        $state = $gateway->voiceState(Scope::GUILD_ID, Scope::OWNER_ID);
        pdSame(1, $http->calls, $label . ': no extra diagnostic request'); pdSame($known, $state->known, $label . ': conservative state preserved');
        pdSame(1, count($observations), $label . ': exactly one observation');
        $diagnostic = $observations[0];
        pdSame(['http_status' => $status, 'classification' => $classification], $diagnostic->toArray(), $label . ': bounded status and fixed classification');
        pdSame(['httpStatus', 'classification'], array_keys(get_object_vars($diagnostic)), $label . ': DTO contains exactly two scalar properties');
        $encoded = json_encode($diagnostic->toArray(), JSON_THROW_ON_ERROR);
        foreach (['FICTIONAL', Scope::OWNER_ID, Scope::GUILD_ID, Scope::VOICE_ID, '10065', 'message', 'user_id', 'private_extra', 'Authorization', 'response_body'] as $forbidden) {
            pdCheck(!str_contains($encoded, $forbidden), $label . ': no body, error text, numeric Discord code or identity retained');
        }
        if ($label === 'not-found') { pdSame(false, $state->known, '404 remains unknown rather than normal absence'); pdSame(null, $state->channelId, '404 does not fabricate a known channel'); }
    }
    $unknown = PresenceDiagnostic::fromResult(new RestResult(false, 'FICTIONAL_RESPONSE_BODY_NOT_TO_BE_RETAINED', 403, ['private_extra' => 'FICTIONAL_RESPONSE_BODY_NOT_TO_BE_RETAINED']));
    pdSame(['http_status' => 403, 'classification' => 'unclassified'], $unknown->toArray(), 'unknown text cannot enter a classification');
    foreach ([0, -1, 600, 10000] as $invalidStatus) {
        pdSame(null, PresenceDiagnostic::fromResult(new RestResult(false, 'transport_failed', $invalidStatus))->httpStatus, 'non-HTTP sentinel is null');
        pdSame('unclassified', PresenceDiagnostic::fromResult(new RestResult(true, 'ok', $invalidStatus))->classification, 'invalid status cannot be labeled successful');
    }
    pdSame(['http_status' => null, 'classification' => 'local_rate_limited'], PresenceDiagnostic::fromResult(new RestResult(false, 'rate_limited', 429, [], 1.0, true))->toArray(), 'local rate stop is not a received HTTP429');
    pdSame(['http_status' => null, 'classification' => 'local_auth_stop'], PresenceDiagnostic::fromResult(new RestResult(false, 'unauthorized', 401, [], 0.0, true))->toArray(), 'local auth stop is not another HTTP401');
    foreach ([[401,'unauthorized','local_auth_stop'],[429,'rate_limited','local_rate_limited']] as [$httpStatus,$firstClass,$secondClass]) {
        $http = new PdHttp(new HttpResponse($httpStatus, [], $httpStatus === 429 ? '{"retry_after":60}' : '{}')); $captured = [];
        $client = new DiscordRestClient($http, Scope::APPLICATION_ID, 'FICTIONAL_DIAGNOSTIC_BOT_AUTH_ONLY', ['enabled' => true, 'now' => static fn(): float => 1000000000.0]);
        $gateway = new RestGateway($client,$clock,static function(PresenceDiagnostic $diagnostic) use (&$captured): void {$captured[]=$diagnostic->toArray();});
        $gateway->voiceState(Scope::GUILD_ID,Scope::OWNER_ID); $gateway->voiceState(Scope::GUILD_ID,Scope::OWNER_ID);
        pdSame(1,$http->calls,'cached local stop makes no additional HTTP call');
        pdSame(['http_status'=>$httpStatus,'classification'=>$firstClass],$captured[0],'first real response retains its status');
        pdSame(['http_status'=>null,'classification'=>$secondClass],$captured[1],'second local stop is explicitly distinguished');
    }
    $http = new PdHttp(new HttpResponse(200, [], $json($valid))); $called = 0;
    $client = new DiscordRestClient($http, Scope::APPLICATION_ID, 'FICTIONAL_DIAGNOSTIC_BOT_AUTH_ONLY', ['enabled' => true]);
    $gateway = new RestGateway($client, $clock, static function (PresenceDiagnostic $diagnostic) use (&$called): void { ++$called; });
    pdSame(false, $gateway->voiceState(Scope::GUILD_ID, '900000000000007777')->known, 'other user still rejected');
    pdSame(0, $http->calls, 'other user sends no request'); pdSame(0, $called, 'no fabricated diagnostic without a probe');
    ob_start();
    $gateway = new RestGateway($client, $clock, static function (PresenceDiagnostic $diagnostic): void { throw new RuntimeException('FICTIONAL_OBSERVER_EXCEPTION_NOT_TO_BE_RETAINED'); });
    try { $state = $gateway->voiceState(Scope::GUILD_ID, Scope::OWNER_ID); } finally { $output = ob_get_clean(); }
    pdSame(false, $state->known, 'failed observation fails closed'); pdSame('', $output, 'failed observation emits no exception text'); pdSame(1, $http->calls, 'failed observation does not retry HTTP');
    pdSame(true, (new RestGateway($client, $clock))->voiceState(Scope::GUILD_ID, Scope::OWNER_ID)->known, 'ordinary gateway without observer is unchanged');
} catch (Throwable) { $pdFailed = true; }
if ($pdFailed) { fwrite(STDERR, 'Presence diagnostics: failed at bounded check ' . $pdChecks . ' (' . $pdDescription . ").\n"); exit(1); }
echo 'Presence diagnostics: ' . $pdChecks . " assertions passed (fictional HTTP only; no live calls).\n";
