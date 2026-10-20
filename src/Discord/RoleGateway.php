<?php
declare(strict_types=1);
namespace RadioStamp\Discord;
interface RoleGateway extends Gateway { public function supportsRoleSync():bool; }
