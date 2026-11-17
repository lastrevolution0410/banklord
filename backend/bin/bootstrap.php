<?php
declare(strict_types=1);
// CLI only! Usage: php bin/bootstrap.php guild "Guild Name" Everlook
//         php bin/bootstrap.php user admin@example.com "Display Name"   (password from STDIN)
//         php bin/bootstrap.php member GUILD_ID USER_ID admin
if (PHP_SAPI !== 'cli') { http_response_code(403);exit; }
require __DIR__.'/../src/Config.php';
\Banklord\Config::load();
require __DIR__.'/../src/Database.php';
$db=Banklord\Database::connect();
$action=$argv[1]??'';
if ($action==='guild' && count($argv)===4) {
    $s=$db->prepare("INSERT INTO guilds (name,realm,region,wow_flavor) VALUES (?,?,'EU','mop_classic')");$s->execute([$argv[2],$argv[3]]);echo 'guild_id='.$db->lastInsertId().PHP_EOL;
} elseif ($action==='user' && count($argv)===4) {
    fwrite(STDERR, "Password on STDIN (no CLI argument): " );
    $secret = trim((string)fgets(STDIN), "\r\n");
    if(strlen($secret)<14) {fwrite(STDERR,"Password must be 14+ chars\n");exit(2);}
    $s=$db->prepare('INSERT INTO users (email,display_name,password_hash) VALUES (?,?,?)');$s->execute([strtolower($argv[2]),$argv[3],password_hash($secret,PASSWORD_DEFAULT)]);unset($secret);echo 'user_id='.$db->lastInsertId().PHP_EOL;
} elseif ($action==='member' && count($argv)===5) {
    $role=$argv[4];if(!in_array($role,['member','officer','bank_manager','admin'],true))exit(2);
    $s=$db->prepare('INSERT INTO guild_memberships (guild_id,user_id,role) VALUES (?,?,?)');$s->execute([(int)$argv[2],(int)$argv[3],$role]);echo "ok\n";
} else {fwrite(STDERR,"Usage: php bin/bootstrap.php guild NAME REALM | user EMAIL DISPLAY_NAME | member GUILD_ID USER_ID ROLE\n");exit(2);}
