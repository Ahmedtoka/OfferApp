<?php

namespace App\Shopify;

/** Admin API refused a field because the app lacks the access scope for it (extensions.code ACCESS_DENIED). */
class ShopifyAccessDenied extends ShopifyGraphqlException {}
