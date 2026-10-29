<?php

namespace App\Services;

use RuntimeException;

/** The metafield read back from Shopify differs from what we just wrote. */
class SaveMismatch extends RuntimeException {}
