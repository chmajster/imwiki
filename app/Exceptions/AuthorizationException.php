<?php
declare(strict_types=1);
namespace ImWiki\Exceptions;
final class AuthorizationException extends AppException{public function __construct(string $message='Permission denied.'){parent::__construct($message,403,'permission_denied');}}
