<?php

namespace App\Services\Google;

use RuntimeException;

/**
 * Google is not connected, or the stored credentials no longer work. The
 * message is user-facing and always points at reconnecting.
 */
class GoogleNotConnectedException extends RuntimeException {}
