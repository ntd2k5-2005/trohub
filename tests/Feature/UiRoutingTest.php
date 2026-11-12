<?php

namespace Tests\Feature;

use Tests\TestCase;

class UiRoutingTest extends TestCase
{
    public function test_guest_home_redirects_to_public_search(): void
    {
        $this->get('/')->assertRedirect('/ui/marketplace-search?context=public');
    }

    public function test_unknown_screen_is_not_found(): void
    {
        $this->get('/ui/not-a-page?context=public')->assertNotFound();
    }

    public function test_profiles_cannot_grant_guest_workspace_access(): void
    {
        config(['ui_access.preview_profile' => 'landlord', 'ui_access.allow_query_profile_switch' => true]);
        $this->get('/ui/properties?context=workspace&_profile=landlord')->assertForbidden();
    }
}
