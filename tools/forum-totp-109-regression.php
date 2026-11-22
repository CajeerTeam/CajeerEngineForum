<?php
/** Actual RFC 6238 step matching used by forum 2FA enrollment, login and step-up. */
declare(strict_types=1);
require dirname(__DIR__).'/core/Security/TwoFactorTotp.php';
$totp=new \CajeerEngine\Security\TwoFactorTotp();
$secret=$totp->generateSecret();
$now=time();$step=intdiv($now,30);
$code=$totp->code($secret,$step*30);
$n=0;
$check=static function(bool $ok,string $desc)use(&$n):void {
    if (!$ok)throw new RuntimeException('FAIL '.$desc);
    echo 'PASS '.$desc."\n";$n++;
};
$check($totp->matchingStep($secret,$code)===$step,'current valid TOTP yields the matching 30-second step');
$check($totp->verify($secret,$code),'legacy boolean verification uses the same matching step');
$check($totp->matchingStep(strtolower($secret),$code)===$step,'lowercase base32 key is decoded consistently with validation');
$check($totp->matchingStep($secret,'111')===null,'short values rejected');
$check($totp->matchingStep($secret,"123456\n")===null,'control characters rejected');
$check($totp->matchingStep('invalid-!', $code)===null,'malformed key rejected');
$check($totp->matchingStep($secret,$code,3)===null,'unbounded verification window rejected');
$check($totp->matchingStep($secret,$totp->code($secret,($step-3)*30))===null,'expired TOTP outside allowed window rejected');
$check($totp->matchingStep($secret,$totp->code($secret,($step-1)*30))===$step-1,'window-1 code maps to its original step, not current step');
echo "Forum 1.0.9 real TOTP step validation: {$n} PASS\n";
