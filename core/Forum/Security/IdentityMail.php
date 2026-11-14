<?php
declare(strict_types=1);
namespace CajeerEngine\Forum\Security;

/** SMTP transport; TLS peer and hostname validation are mandatory outside local test delivery. */
final class IdentityMail
{
    public function send(string $email, string $purpose, string $token): void
    {
        $url = rtrim((string)getenv('APP_URL'), '/');
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || !in_array($parts['scheme'] ?? '', ['https','http'], true)
            || (($parts['scheme'] ?? '') !== 'https' && !in_array($parts['host'], ['localhost','127.0.0.1','[::1]'], true))) throw new \RuntimeException('Invalid APP_URL for identity mail');
        $from = (string)getenv('FORUM_SMTP_FROM');
        if (!filter_var($from,FILTER_VALIDATE_EMAIL) || !filter_var($email,FILTER_VALIDATE_EMAIL)) throw new \RuntimeException('Invalid SMTP address');
        $host = (string)getenv('FORUM_SMTP_HOST'); $mode = getenv('FORUM_SMTP_SECURITY') ?: 'starttls';
        $port = (int)(getenv('FORUM_SMTP_PORT') ?: ($mode === 'tls' ? 465 : 587));
        if (!preg_match('/^[a-zA-Z0-9.-]+$/D',$host) || $port<1 || $port>65535 || !in_array($mode,['tls','starttls','plain'],true)
            || ($mode === 'plain' && !in_array($host,['localhost','127.0.0.1'],true))) throw new \RuntimeException('Invalid SMTP configuration');
        $context=stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>$host,'allow_self_signed'=>false]]);
        $socket=@stream_socket_client(($mode==='tls'?'tls://':'tcp://').$host.':'.$port,$errno,$errstr,10,STREAM_CLIENT_CONNECT,$context);
        if (!$socket) throw new \RuntimeException('SMTP connection failed');
        stream_set_timeout($socket,10);
        try {
            $this->reply($socket,[220]); $this->command($socket,'EHLO localhost',[250]);
            if ($mode==='starttls') {
                $this->command($socket,'STARTTLS',[220]);
                if (@stream_socket_enable_crypto($socket,true,STREAM_CRYPTO_METHOD_TLS_CLIENT)!==true) throw new \RuntimeException('SMTP TLS failed');
                $this->command($socket,'EHLO localhost',[250]);
            }
            $username=(string)getenv('FORUM_SMTP_USERNAME'); $password=(string)getenv('FORUM_SMTP_PASSWORD');
            if ($username!=='' || $password!=='') {
                if ($mode==='plain' || $username==='') throw new \RuntimeException('SMTP authentication requires TLS');
                $this->command($socket,'AUTH PLAIN '.base64_encode("\0".$username."\0".$password),[235]);
            }
            $this->command($socket,'MAIL FROM:<'.$from.'>',[250]); $this->command($socket,'RCPT TO:<'.$email.'>',[250,251]); $this->command($socket,'DATA',[354]);
            $path=$purpose==='verify'?'verify-email':'reset-password';
            $subject=$purpose==='verify'?'Confirm your forum email':'Reset your forum password';
            $body="Open the following link to continue:\r\n".$url.'/forum/'.$path.'#token='.$token."\r\n\r\nIf you did not request this, ignore this message.\r\n";
            $headers="From: <$from>\r\nTo: <$email>\r\nSubject: $subject\r\nDate: ".gmdate('D, d M Y H:i:s').' +0000'."\r\nMessage-ID: <".bin2hex(random_bytes(16))."@localhost>\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
            $this->write($socket,$headers.chunk_split(base64_encode($body)).".\r\n"); $this->reply($socket,[250]);
            // Delivery accepted; QUIT failure must not invalidate accepted mail.
            @fwrite($socket,"QUIT\r\n");
        } finally { fclose($socket); }
    }
    private function write($socket,string $data): void
    {
        while ($data!=='') { $n=@fwrite($socket,$data); if (!$n) throw new \RuntimeException('SMTP write failed'); $data=substr($data,$n); }
    }
    private function command($socket,string $command,array $codes): void { $this->write($socket,$command."\r\n"); $this->reply($socket,$codes); }
    private function reply($socket,array $codes): void
    {
        for ($i=0;$i<100;$i++) {
            $line=fgets($socket,2048);
            if ($line===false || !preg_match('/^(\d{3})([ -])/',$line,$m) || !in_array((int)$m[1],$codes,true)) throw new \RuntimeException('SMTP command failed');
            if ($m[2]===' ') return;
        }
        throw new \RuntimeException('SMTP response limit exceeded');
    }
}
