<?php
declare(strict_types=1);

namespace ImWiki\Auth;

use ImWiki\Services\AuthService;

final class LocalAuthenticationProvider implements AuthenticationProviderInterface
{
    public function __construct(private readonly AuthService $auth){}
    public function key():string{return'local';}
    public function type():string{return'local';}
    public function displayName():string{return'Local account';}
    public function enabled():bool{return true;}
    public function mode():string{return'password';}
    public function authenticate(array $credentials):?array
    {
        $login=trim((string)($credentials['login']??''));$password=(string)($credentials['password']??'');if($login===''||$password==='')return null;return$this->auth->credentials($login,$password);
    }
}
