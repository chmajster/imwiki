<?php
declare(strict_types=1);

namespace ImWiki\Plugins;

use ImWiki\Macros\MacroRegistry;
use ImWiki\Support\EventDispatcher;

final class PluginContext
{
    public function __construct(public readonly string $pluginId,public readonly array $grantedPermissions,public readonly MacroRegistry $macros,public readonly EventDispatcher $events){}
    public function granted(string $permission):bool{return in_array($permission,$this->grantedPermissions,true);}
}
