<?php

namespace Tests\Unit;

use App\Support\ModuleRegistry;
use Tests\TestCase;

class ModuleRegistryTest extends TestCase
{
    public function test_page_slugs_are_unique_and_views_exist(): void
    {
        $pages = ModuleRegistry::pages();

        $this->assertSame($pages->count(), $pages->pluck('slug')->unique()->count());

        foreach ($pages as $page) {
            $this->assertTrue(view()->exists($page['view']), 'Thiếu view cho '.$page['slug']);
        }
    }

    public function test_all_context_home_pages_exist_in_their_context(): void
    {
        foreach (config('navigation.contexts', []) as $context => $meta) {
            $page = ModuleRegistry::findPage($meta['home']);
            $this->assertNotNull($page, 'Không tồn tại home page của context '.$context);
            $this->assertContains($context, $page['contexts'] ?? []);
        }
    }
}
