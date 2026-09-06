<?php
declare(strict_types=1);
namespace ImWiki\Exceptions;
final class NotFoundException extends AppException{public function __construct(string $message='Resource not found.'){parent::__construct($message,404,'not_found');}}
