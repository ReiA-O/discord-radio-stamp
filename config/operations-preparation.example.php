<?php
declare(strict_types=1);
// Component template only. Not accepted by existing PublicEndpoint/PrivateConfig.
// No credential values, deployed configuration, cron entry or permission change.
return [
    'mode'=>'operations_preparation',
    'enabled'=>false,
    'posts_enabled'=>false,
    'roles_enabled'=>false,
    'member_actions_enabled'=>false,
    'accept_voice_race_risk'=>false,
    'periodic_worker_enabled'=>false,
    'worker_duration_seconds'=>50,
    'worker_poll_seconds'=>1,
    'role_ids'=>[],
    'bot_token'=>null,
    'private_db_path'=>null,
    'private_root'=>null,
    'public_root'=>null,
];
