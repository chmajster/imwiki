<?php
declare(strict_types=1);
namespace ImWiki\Exceptions;
final class StorageException extends AppException{public function __construct(string $message='Storage operation failed.'){parent::__construct($message,500,'storage_error');}}
