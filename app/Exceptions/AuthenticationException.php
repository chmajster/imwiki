<?php
declare(strict_types=1);
namespace ImWiki\Exceptions;
final class AuthenticationException extends AppException{public function __construct(string $message='Authentication required.'){parent::__construct($message,401,'authentication_required');}}
