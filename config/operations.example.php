<?php
declare(strict_types=1);
// Production participant scope; all operational flags remain OFF until explicitly configured.
$base=require __DIR__.'/admin-validation.example.php';
return array_replace($base,[
    'mode'=>'operations','operations_enabled'=>false,'admin_validation_enabled'=>false,
    'participant_commands_enabled'=>false,'participant_scope'=>'guild','participant_user_ids'=>[],
    // No schedule key uses Core/Schedule::DEFAULT_SPEC. To replace only the event time:
    // Keep the logical slot (and component) when replacing only its time; at uses JST.
    // 'schedule'=>['daily_times'=>[],'one_off_times'=>[['at'=>'2026-10-20 15:00','slot_id'=>'midday']]],
    'owner_user_id'=>'800000000000001021', // Explicitly confirmed owner; used only for the 365-day special award.
    'diagnostics_enabled'=>false,
    'posts_enabled'=>false,'room_permissions_enabled'=>false,'room_baseline'=>null,
    'roles_enabled'=>false,'achievement_notifications_enabled'=>false,'role_ids'=>[],
    'closure_policy'=>'manual','member_actions_enabled'=>false,'accept_voice_race_risk'=>false,
    'periodic_worker_enabled'=>false,'command_registration_enabled'=>false,
    'private_db_path'=>dirname(__DIR__).'/var-private/operations.sqlite',
    'private_root'=>dirname(__DIR__).'/var-private',
    'entry_instructions'=>'体操後に /radio-attend → 共有の参加ボタンを各自で押す',
]);
