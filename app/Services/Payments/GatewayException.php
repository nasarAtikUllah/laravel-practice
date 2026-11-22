<?php

namespace App\Services\Payments;

use RuntimeException;

/** The provider could not be reached or refused the request. The message is safe to log. */
class GatewayException extends RuntimeException {}
