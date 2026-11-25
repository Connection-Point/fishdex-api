<?php

namespace Tests\Feature;

use App\Domain\Admin\Models\Admin;
use App\Domain\Review\Models\ReviewItem;
use App\Domain\Settings\Services\SettingsService;
use App\Filament\Pages\AppSettings;
use App\Filament\Resources\ReviewItems\Pages\ListReviewItems;
use Livewire\Livewire;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    private function admin(string $role): Admin
    {
        return Admin::create(['name' => $role, 'email' => "{$role}@fishdex.test", 'password' => 'a-long-password-123', 'role' => $role]);
    }

    public function test_app_users_cannot_reach_the_admin_panel(): void
    {
        $this->actingAs($this->angler())->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_super_admin_pages_render(): void
    {
        $this->actingAs($this->admin('super_admin'), 'admin');
        $species = $this->species('Channa micropeltes');

        foreach ([
            '/admin', '/admin/fish-species', "/admin/fish-species/{$species->public_id}/edit", '/admin/fish-species/create',
            '/admin/taxon-families', '/admin/card-sets', '/admin/review-items', '/admin/users', '/admin/admins', '/admin/app-settings',
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_roles_limit_access(): void
    {
        $this->actingAs($this->admin('reviewer'), 'admin');
        $species = $this->species('Channa micropeltes');

        $this->get('/admin/review-items')->assertOk();
        $this->get('/admin/fish-species')->assertOk();
        $this->get("/admin/fish-species/{$species->public_id}/edit")->assertForbidden();
        $this->get('/admin/app-settings')->assertForbidden();
        $this->get('/admin/admins')->assertForbidden();
    }

    public function test_settings_page_saves_runtime_overrides_and_audits(): void
    {
        $admin = $this->admin('super_admin');
        $this->actingAs($admin, 'admin');

        Livewire::test(AppSettings::class)
            ->set('data.kill_switch', true)
            ->set('data.free_rpd', 500)
            ->call('save')
            ->assertHasNoErrors();

        $settings = app(SettingsService::class);
        $this->assertTrue($settings->get('ai.kill_switch'));
        $this->assertSame(500, $settings->get('ai.free_tier.rpd'));
        $this->assertDatabaseHas('admin_activity_log', ['admin_id' => $admin->id, 'subject_type' => 'AppSetting']);
    }

    public function test_reviewer_can_confirm_from_queue(): void
    {
        $user = $this->angler();
        $haruan = $this->species('Channa striata');
        $this->aiSays('Channa striata', 0.5, [['scientific_name' => 'Channa micropeltes', 'confidence' => 0.45]]);
        $scanId = $this->scan($user)->json('data.scan_id');
        $tilapia = $this->species('Oreochromis niloticus');
        $this->confirm($user, $scanId, 'species', $tilapia->public_id); // off-list → review

        $item = ReviewItem::query()->firstOrFail();
        $this->actingAs($this->admin('reviewer'), 'admin');

        Livewire::test(ListReviewItems::class)
            ->callTableAction('correct', $item, ['species_id' => $haruan->id, 'notes' => 'Clearly haruan'])
            ->assertHasNoTableActionErrors();

        $this->assertSame('corrected', $item->fresh()->status);
        $this->assertSame($haruan->id, $item->fresh()->encounter->species_id);
    }
}
