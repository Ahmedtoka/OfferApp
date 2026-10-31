<?php

namespace App\Shopify;

/** Admin API answered 401: the shop's stored offline token is no longer valid. */
class TokenRevoked extends ShopifyGraphqlException {}
