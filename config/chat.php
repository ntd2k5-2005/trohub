<?php

use App\Modules\Connection\RentalChatContext;

return [
    'poll_ms' => 3000,
    'page_size' => 40,
    'inbox_size' => 30,
    'contexts' => ['rental_contact' => RentalChatContext::class],
];
