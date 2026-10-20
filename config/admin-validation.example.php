<?php
declare(strict_types=1);

// Copy only into an app-private directory. Never put real configuration in public/.
// Disabled owner-validation template; mock is distinct from admin_validation.
return [
    'mode' => 'mock',
    'admin_validation_enabled' => false,
    'member_actions_enabled' => false,
    'live_automation_enabled' => false,
    'achievement_notifications_enabled' => false,
    // Local Mock participant flow only. Fixed-owner REST validation rejects true.
    'participant_commands_enabled' => false,
    // Separate explicit owner authorization is required before registering only
    // the fixed radio-check/radio-card/radio-status definitions in the approved guild.
    'command_registration_enabled' => false,
    'runtime_check_enabled' => false,
    'timezone' => 'Asia/Tokyo',
    'guild_id' => '800000000000001001',
    'category_id' => '800000000000001004',
    'text_channel_id' => '800000000000001005',
    'records_channel_id' => '800000000000001006',
    'voice_channel_id' => '800000000000001007',
    // Administrator-review assumption: warnings/instructions appear in the target VC chat.
    'warning_channel_id' => '800000000000001007',
    'entry_notice_channel_id' => '800000000000001007',
    'destination_channel_id' => '800000000000001002', // Identifier only; member actions remain OFF.
    'application_id' => '800000000000001003', // Existing radio_taisou reuse approved.
    'public_key_hex' => null,
    'admin_user_ids' => ['800000000000001021'], // Approved owner-only validation.
    'bot_token' => null, // Only the owner may input; do not request or log it here.
    'token_encryption_key_hex' => null,
    'private_db_path' => dirname(__DIR__) . '/var-private/radio.sqlite',
    // Count-based titles are defined in Core/TitlePolicy.php (1..365, 10 stages).
    // Day-based benefit eligibility is separate: 1/10/30/60/100/300/365 JST dates.
    // These are display labels, not permission grants; real role synchronization stays OFF.
    'role_ids' => [],
    'max_roster_age_seconds' => 5,
    'font_candidates' => [
        '/usr/share/fonts/ipa-gothic/ipag.ttf',
        '/usr/share/fonts/ipa-pgothic/ipagp.ttf',
        '/usr/share/fonts/ja/TrueType/kochi-gothic-subst.ttf',
        '/usr/share/fonts/vlgothic/VL-Gothic-Regular.ttf',
        '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc',
        '/usr/share/fonts/ipaexg/ipaexg.ttf',
        'C:/Windows/Fonts/BIZ-UDGothicR.ttc',
    ],
    'video_url' => 'https://www.youtube.com/watch?v=RNlB93CuwOI',
    'entry_instructions' => '動画を開く→再生→体操開始ボタン→参加ボタン',
];
