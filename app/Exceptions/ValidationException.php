<?php
declare(strict_types=1);
namespace ImWiki\Exceptions;
final class ValidationException extends AppException{public function __construct(string $message='Validation failed.'){parent::__construct($message,422,'validation_failed');}}
