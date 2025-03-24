<?php

namespace Src\Classes\Exception;

// Preventing direct access to the script, because it must be included by the "include" directive.
defined('BOOTSTRAP') or die('Access denied');

class PaynetException extends \Exception 
{
    private array $context = [];

    public function __construct(
        string $message, 
        array $context = [], 
        int $code = 0, 
        \Throwable $previous = null
    ) {
        $this->context = $context;
        parent::__construct($message, $code, $previous);
    }

    public function getContext(): array 
    {
        return $this->context;
    }
}
