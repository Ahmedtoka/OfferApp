<?php

namespace App\Shopify;

use RuntimeException;

/** A Shopify session token failed verification. The message never contains the token. */
class InvalidSessionToken extends RuntimeException {}
