<?php

namespace SuperbAddons\Data\Utils;

defined('ABSPATH') || exit();

use Exception;
use SuperbAddons\Data\Controllers\LogController;

class BaseException extends Exception
{
    /**
     * @param string         $message
     * @param bool           $ignore_log
     * @param int            $code
     * @param Exception|null $previous Left untyped: the PHP 7.0 floor has no nullable type syntax, and an
     *                                 implicitly nullable typed parameter is deprecated from PHP 8.4.
     */
    public function __construct($message, $ignore_log = false, $code = 0, $previous = null)
    {
        parent::__construct($message, $code, $previous);

        if ($ignore_log) return;

        LogController::HandleException($this);
    }
}
