<?php

return ['id' => 'workspace', 'enabled' => true, 'pages' => array_values(array_filter(config('screens'), fn ($page) => $page['_module'] === 'workspace'))];
