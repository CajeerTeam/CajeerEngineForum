<?php
/** Exercise deployed Forum Identity probe validation and real Ed25519 release signatures without Composer. */
declare(strict_types=1);

require dirname(__DIR__).'/core/Identity/IdentityAcceptance.php';
require dirname(__DIR__).'/core/Forum/ForumVersion.php';
require dirname(__DIR__).'/core/Update/OperationFiles.php';
require dirname(__DIR__).'/core/Update/ReleaseSignature.php';

use CajeerEngine\Identity\IdentityAcceptance;
use CajeerEngine\Forum\ForumVersion;
use CajeerEngine\Update\ReleaseSignature;

$root = dirname(__DIR__);
$version = (new ForumVersion($root))->current();
if ($version !== '1.5.2') throw new RuntimeException('Wrong Forum Edition release.');
$checks = 0;
$expect = static function(bool $passed, string $what) use (&$checks): void {
    if (!$passed) throw new RuntimeException('FAIL '.$what);
    ++$checks;
    echo 'PASS '.$what."\n";
};
$headers = [
    'x-cajeerengine-forum-version'=>$version,
    'content-type'=>'text/html; charset=UTF-8',
    'cache-control'=>'private, no-store',
];
$html = '<!doctype html><html><script src="/forum-assets/forum.js?v='.$version.'"></script></html>';
$valid = static fn(int $code, array $h, string $body, string $v): bool => IdentityAcceptance::forumLoginResponseValid($code, $h, $body, $v);
$expect($valid(200, $headers, $html, $version), 'real 1.5.2 Forum login page and live version accepted');
$expect(!$valid(302, $headers, $html, $version), 'redirect is not a successful login page probe');
$expect(!$valid(503, $headers, $html, $version), 'maintenance/error page refused');
$expect(!$valid(200, $headers, $html, '1.0.9'), 'wrong installed Forum release refused');
$expect(!$valid(200, array_diff_key($headers, ['x-cajeerengine-forum-version'=>true]), $html, $version), 'missing edition version refused');
$expect(!$valid(200, ['x-cajeerengine-forum-version'=>'1.0.9']+$headers, $html, $version), 'stale PHP worker release refused');
$expect(!$valid(200, ['cache-control'=>'public,max-age=86400']+$headers, $html, $version), 'publicly cacheable login HTML refused');
$expect(!$valid(200, ['content-type'=>'application/json']+$headers, $html, $version), 'JSON error returned as login page refused');
$expect(!$valid(200, $headers, str_replace('1.5.2','1.1.0',$html), $version), 'stale frontend asset refused');
$expect(!$valid(200, $headers, 'not an HTML page', $version), 'incorrect HTTP body refused');

$tmp = sys_get_temp_dir().'/cajeer-forum-110-'.bin2hex(random_bytes(8));
if (!mkdir($tmp,0700)) throw new RuntimeException('Cannot create test directory');
try {
    $secret=$tmp.'/publisher.secret'; $public=$tmp.'/publisher.pub';
    ReleaseSignature::generate($secret,$public);
    $zip=$tmp.'/cajeerengine-1.5.2-dist.zip';
    file_put_contents($zip, 'signed candidate bytes; not a fabricated production certification');
    $signed=ReleaseSignature::sign($zip,$secret);
    $verified=ReleaseSignature::verify($zip,$zip.'.sig',$public);
    $expect(hash_equals($verified['sha256'],$signed['sha256']), 'real Ed25519 signature and exact bytes verified');
    $otherSecret=$tmp.'/other.secret'; $otherPub=$tmp.'/other.pub';
    ReleaseSignature::generate($otherSecret,$otherPub);
    try { ReleaseSignature::verify($zip,$zip.'.sig',$otherPub); $rejected=false; }
    catch (Throwable) { $rejected=true; }
    $expect($rejected,'independent untrusted signing key refused');
    file_put_contents($zip,'tampered package bytes');
    try { ReleaseSignature::verify($zip,$zip.'.sig',$public); $rejected=false; }
    catch (Throwable) { $rejected=true; }
    $expect($rejected,'modified signed artifact refused');
} finally {
    foreach (glob($tmp.'/*') ?: [] as $file) if (is_file($file)) unlink($file);
    rmdir($tmp);
}
echo "Forum Edition 1.5.2 acceptance/signature: {$checks} PASS\n";
