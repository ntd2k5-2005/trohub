<?php

return ['id' => 'listing', 'enabled' => true, 'pages' => array_values(array_filter(config('screens'), fn ($page) => $page['_module'] === 'listing'))];
