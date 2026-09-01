<?php
declare(strict_types=1);
/** Browser entry for ZRA Smart Invoice Integration. */
if(session_status()===PHP_SESSION_NONE)session_start();
require_once __DIR__.'/actions/zra.php';
