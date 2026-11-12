<?php

return ['id' => 'chat', 'enabled' => true, 'pages' => array_values(array_filter(config('screens'), fn ($p) => $p['_module'] === 'chat'))];
