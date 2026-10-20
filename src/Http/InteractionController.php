<?php
declare(strict_types=1);
namespace RadioStamp\Http;

use RadioStamp\Core\Clock;
use RadioStamp\Discord\Gateway;
use RadioStamp\Discord\ParticipantCommand;
use RadioStamp\Security\SignatureVerifier;
use RadioStamp\Operations\SafeDiagnostics;

final class InteractionController
{
    private readonly \RadioStamp\Core\Schedule $schedule;
    public function __construct(private readonly array $config, private readonly InteractionQueue|\Closure|null $queue, private readonly Clock $clock, private readonly bool $postResponseAvailable = false,
        private readonly ?array $participantAllowlist=null,private readonly ?string $queueScope=null,private readonly ?SafeDiagnostics $diagnostics=null)
    {
        if($queueScope!==null&&($queueScope!=='operations'||($participantAllowlist===null&&($config['operations_participant_scope']??null)!=='guild')||$participantAllowlist===[])){throw new \InvalidArgumentException('participant_scope_invalid');}
        $this->schedule=\RadioStamp\Core\Schedule::fromConfig($config);
    }

    /** No network calls, rendering, or core business operations occur in this path. */
    public function handle(string $method, array $headers, string $rawBody): Response
    {
        $started=hrtime(true);$this->diagnostics?->context();
        try{
            $response=$this->handleRequest($method,$headers,$rawBody);
            $this->diagnostics?->record('http_controller',['ok'=>$response->status===200,'status'=>$response->status,
                'code'=>$response->body['error']??'response_prepared','duration_ms'=>(int)((hrtime(true)-$started)/1000000)]);
            return $response;
        }catch(\Throwable $error){$this->diagnostics?->exception('http_controller',$error);throw $error;}
    }
    private function handleRequest(string $method,array $headers,string $rawBody):Response
    {
        if (!PrivateConfig::signatureReady($this->config)) { return new Response(503, ['error' => 'administrator_validation_unavailable']); }
        if ($method !== 'POST') { return new Response(405, ['error' => 'method_not_allowed']); }
        if (strlen($rawBody) > 65536) { return new Response(413, ['error' => 'request_too_large']); }
        $headers = array_change_key_case($headers, CASE_LOWER);
        $timestamp = $headers['x-signature-timestamp'] ?? '';
        $signature = $headers['x-signature-ed25519'] ?? '';
        $now = $this->clock->now()->getTimestamp();
        if (!is_string($timestamp) || !is_string($signature) || !(new SignatureVerifier($this->config['public_key_hex']))->verify($timestamp, $signature, $rawBody, $now)) {
            return new Response(401, ['error' => 'invalid_signature']);
        }
        try { $data = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { return new Response(400, ['error' => 'invalid_json']); }
        if (!is_array($data) || ($data['application_id'] ?? null) !== $this->config['application_id']) {
            return new Response(403, ['error' => 'wrong_application']);
        }
        if (($data['type'] ?? null) === 1) { return new Response(200, ['type' => 1]); }
        $user = $data['member']['user'] ?? null;
        $userId = is_array($user) ? ($user['id'] ?? null) : null;
        $this->diagnostics?->context(is_string($data['id']??null)?$data['id']:null,is_string($userId)?$userId:null);
        if (($data['guild_id'] ?? null) !== $this->config['guild_id'] ||
            !PrivateConfig::snowflake($userId) || ($this->participantAllowlist!==null&&!in_array($userId,$this->participantAllowlist,true))
            || (!PrivateConfig::participantMockScope($this->config) && !in_array($userId, $this->config['admin_user_ids'], true))) {
            return new Response(403, ['error' => 'administrator_scope_required']);
        }
        $channels = array_filter([$this->config['text_channel_id'] ?? null, $this->config['records_channel_id'] ?? null, $this->config['voice_channel_id'] ?? null], PrivateConfig::snowflake(...));
        if (!in_array($data['channel_id'] ?? null, $channels, true)) {
            return new Response(403, ['error' => 'wrong_channel']);
        }
        if (!PrivateConfig::snowflake($data['id'] ?? null) || !is_string($data['token'] ?? null) || strlen($data['token']) < 1 || strlen($data['token']) > 2048) {
            return new Response(400, ['error' => 'invalid_interaction']);
        }
        $action = $this->action($data);
        if ($action === null) { return new Response(400, ['error' => 'unsupported_action']); }
        $this->diagnostics?->context($data['id'],$userId,$action[0]);
        $this->diagnostics?->record('http_action',['ok'=>true,'code'=>'parsed']);
        if (in_array($action[0], ['start', 'attend', 'receipt'], true) && ($data['channel_id'] ?? null) !== $this->config['voice_channel_id']) {
            return new Response(200, ['type' => 4, 'data' => [
                'flags' => 64, 'allowed_mentions' => Gateway::ALLOWED_MENTIONS,
                'content' => '体操VCに接続し、同じVCのチャットにあるボタンから操作してください。',
            ]]);
        }
        if (($this->config['mode'] ?? null) === 'admin_validation' && !$this->postResponseAvailable) {
            // A missing response finisher must never move REST work before ACK.
            return new Response(503, ['error' => 'post_response_unavailable', 'requirement' => 'fastcgi_finish_request']);
        }
        if (!PrivateConfig::ready($this->config) || $this->queue === null) { return new Response(503, ['error' => 'private_worker_configuration_required']); }
        $name = $data['member']['nick'] ?? $user['global_name'] ?? $user['username'] ?? '参加者';
        if (!is_string($name) || strlen($name) > 400) { return new Response(400, ['error' => 'invalid_display_name']); }
        try {
            $queue = $this->queue instanceof \Closure ? ($this->queue)() : $this->queue;
            $scope=$this->queueScope??$this->config['mode'];
            $queue->enqueue(['interaction_id' => $data['id'], 'action' => $action[0], 'target' => $action[1], 'user_id' => $userId, 'display_name' => $name, 'scope_mode' => $scope, 'request_hash' => hash('sha256', $scope . ':' . $this->config['application_id'] . ':' . $this->config['guild_id'] . ':' . $rawBody)], $data['token'], $now);
            $this->diagnostics?->record('http_queue',['ok'=>true,'code'=>'queued']);
        } catch (InteractionConflict $error) {
            $this->diagnostics?->exception('http_queue',$error);
            return new Response(409, ['error' => 'interaction_conflict']);
        } catch (\Throwable $error) {
            $this->diagnostics?->exception('http_queue',$error);
            return new Response(503, ['error' => 'private_queue_unavailable']);
        }
        return new Response(200, ['type' => 5, 'data' => ['flags' => 64]], $data['id']);
    }

    private function action(array $data): ?array
    {
        $input = $data['data'] ?? null;
        if (!is_array($input)) { return null; }
        if (($data['type'] ?? null) === 3 && ($input['component_type'] ?? null) === 2 && is_string($input['custom_id'] ?? null)) {
            if (preg_match('/\Aradio:(start|attend):(.+)\z/D', $input['custom_id'], $m) && $this->schedule->contains($m[2])) {
                if ($m[1] === 'start' && PrivateConfig::participantMockScope($this->config)) { return null; }
                return [$m[1], $m[2]];
            }
            if (preg_match('/\Aradio:card:(\d{4}-\d{2})\z/D', $input['custom_id'], $m) && $this->monthValid($m[1])) { return ['card', $m[1]]; }
            if (preg_match('/\Aradio:memorial:([1-9][0-9]{0,2})\z/D', $input['custom_id'], $m)
                && in_array((int)$m[1], \RadioStamp\Core\ParticipationDays::MILESTONES, true)) { return ['memorial', $m[1]]; }
        }
        if (PrivateConfig::participantMockScope($this->config) && ($data['type'] ?? null) === 2
            && ($input['type'] ?? null) === 1 && ($input['name'] ?? null) === ParticipantCommand::NAME && ($input['options'] ?? []) === []) {
            return ['receipt', 'current'];
        }
        if (($data['type'] ?? null) === 2 && ($input['type'] ?? null) === 1 && ($input['name'] ?? null) === 'radio-card') {
            $options = $input['options'] ?? [];
            if ($options === []) { return ['card', $this->clock->now()->setTimezone(new \DateTimeZone('Asia/Tokyo'))->format('Y-m')]; }
            if (is_array($options) && count($options) === 1 && ($options[0]['name'] ?? null) === 'month' && ($options[0]['type'] ?? null) === 3 && is_string($options[0]['value'] ?? null) && $this->monthValid($options[0]['value'])) {
                return ['card', $options[0]['value']];
            }
        }
        if (($data['type'] ?? null) === 2 && ($input['type'] ?? null) === 1 && ($input['name'] ?? null) === 'radio-check' && ($input['options'] ?? []) === []) {
            if (PrivateConfig::participantMockScope($this->config)) { return null; }
            return ['check', 'fixed_scope'];
        }
        if (($data['type'] ?? null) === 2 && ($input['type'] ?? null) === 1 && ($input['name'] ?? null) === 'radio-status' && ($input['options'] ?? []) === []) {
            return ['status', 'self'];
        }
        return null;
    }

    private function monthValid(string $month): bool
    {
        return preg_match('/\A(20\d{2})-(0[1-9]|1[0-2])\z/D', $month) === 1;
    }

}
