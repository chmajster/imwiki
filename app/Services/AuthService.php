<?php
declare(strict_types=1);

namespace ImWiki\Services;

use ImWiki\Repositories\UserRepository;

final class AuthService
{
    private const DUMMY_HASH='$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
    public function __construct(private readonly UserRepository $users){}

    public function credentials(string $login,string $password):?array
    {
        $user=$this->users->findByLogin(trim($login));
        if(!$user||$user['status']!=='active'||!password_verify($password,(string)$user['password_hash'])){password_verify($password,self::DUMMY_HASH);return null;}
        return $user;
    }

    public function attempt(string $login,string $password):bool
    {
        $user=$this->credentials($login,$password);if(!$user)return false;$this->loginUser($user);return true;
    }

    public function loginUser(array $user):void
    {
        session_regenerate_id(true);
        $now=time();
        $_SESSION['user_id']=(int)$user['id'];
        $_SESSION['authenticated_at']=$now;
        $_SESSION['last_seen_at']=$now;
        $_SESSION['session_regenerated_at']=$now;
        unset($_SESSION['pending_2fa_user_id'],$_SESSION['pending_2fa_started_at']);
        $this->users->touchLogin((int)$user['id']);
    }

    public function logout():void
    {
        $_SESSION=[];if(ini_get('session.use_cookies')){$params=session_get_cookie_params();setcookie(session_name(),'',time()-42000,$params['path'],$params['domain']??'',(bool)$params['secure'],(bool)$params['httponly']);}session_destroy();
    }
}
