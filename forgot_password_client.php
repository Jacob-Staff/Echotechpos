<?php
declare(strict_types=1);
session_start();
date_default_timezone_set('Africa/Lusaka');
require_once __DIR__ . '/includes/conn.php';
require_once __DIR__ . '/includes/password_reset.php';
echotech_reset_bootstrap($conn);
$message='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    echotech_reset_check_csrf();
    $email=trim((string)($_POST['email']??''));
    if($email===''||!filter_var($email,FILTER_VALIDATE_EMAIL)){$message='Enter your registered email address.';}
    else{
        $account=echotech_reset_find_account($conn,'client',$email);
        if($account){
            $token=echotech_reset_issue($conn,'client',$account,echotech_reset_ip());
            if($token){
                $url=echotech_reset_base_url().'/reset_password.php?type=client&token='.rawurlencode($token);
                $name=trim((string)($account['full_name']??'Client'))?:'Client';
                $html='<div style="font-family:Arial,sans-serif;max-width:600px"><h2>EchoTech Client Portal Password Reset</h2><p>Hello '.echotech_reset_h($name).',</p><p>We received a request to reset your client portal password.</p><p><a href="'.echotech_reset_h($url).'" style="display:inline-block;padding:12px 18px;background:#003339;color:#fff;text-decoration:none;border-radius:8px">Reset Password</a></p><p>This link expires in 30 minutes and can only be used once.</p><p>If you did not request this, you can safely ignore this email.</p></div>';
                $text="EchoTech Client Portal Password Reset\n\nHello {$name},\n\nReset your password here:\n{$url}\n\nThis link expires in 30 minutes and can only be used once.\n";
                echotech_reset_send_mail((string)$account['email'],$name,'EchoTech Client Portal Password Reset',$html,$text);
            }
        }
        $message='If an account exists for that email address, a password reset link has been sent.';
    }
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Forgot Password | Client Portal</title><style>body{margin:0;background:#f4f6f8;font-family:Arial,sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}.card{width:min(430px,100%);background:#fff;border-top:4px solid #003339;border-radius:14px;padding:32px;box-shadow:0 12px 35px rgba(0,0,0,.1)}h1{color:#003339;font-size:25px}.muted{color:#64748b;line-height:1.6;font-size:14px}.input{width:100%;box-sizing:border-box;padding:13px;border:1px solid #d5dce2;border-radius:9px;margin:8px 0 16px}.btn{width:100%;padding:13px;background:#003339;color:#fff;border:0;border-radius:9px;font-weight:800}.alert{padding:12px;background:#e8f7f0;color:#087443;border-radius:9px;font-size:13px}.back{display:block;text-align:center;margin-top:18px;color:#64748b;text-decoration:none;font-size:13px}</style></head><body><main class="card"><h1>Forgot your password?</h1><p class="muted">Enter the email address registered to your EchoTech client account.</p><?php if($message):?><div class="alert"><?=echotech_reset_h($message)?></div><?php endif;?><form method="post"><input type="hidden" name="csrf_token" value="<?=echotech_reset_h(echotech_reset_csrf())?>"><input class="input" type="email" name="email" placeholder="name@example.com" autocomplete="email" required><button class="btn" type="submit">SEND RESET LINK</button></form><a class="back" href="/api/login_client.php">← Back to Client Login</a></main></body></html>
