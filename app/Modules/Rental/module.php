<?php

return ['id' => 'rental', 'enabled' => true, 'pages' => array_values(array_filter(config('screens'), fn ($page) => $page['_module'] === 'rental'))];
