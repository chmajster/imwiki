<?php
declare(strict_types=1);
namespace ImWiki\Exceptions;
final class ConflictException extends AppException{public function __construct(string $message='Resource conflict.'){parent::__construct($message,409,'conflict');}}
