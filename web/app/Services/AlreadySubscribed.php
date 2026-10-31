<?php

namespace App\Services;

/** The shop is already covered (an active subscription, or a partner development store): nothing to subscribe to. */
class AlreadySubscribed extends \RuntimeException {}
