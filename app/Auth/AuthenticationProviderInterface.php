<?php
declare(strict_types=1);

namespace ImWiki\Auth;

interface AuthenticationProviderInterface
{
    public function key():string;
    public function type():string;
    public function displayName():string;
    public function enabled():bool;
    public function mode():string;
    /** @return array<string,mixed>|null */
    public function authenticate(array $credentials):?array;
}
