<?php
declare(strict_types=1);
namespace ImWiki\Exceptions;
class AppException extends \RuntimeException{public function __construct(string $message='Operation failed.',public readonly int $status=500,public readonly string $errorCode='internal_error'){parent::__construct($message);}}
