<?php

namespace App\Shopify;

use RuntimeException;

/** Admin GraphQL call failed (HTTP != 200 or top-level `errors`). */
class ShopifyGraphqlException extends RuntimeException {}
