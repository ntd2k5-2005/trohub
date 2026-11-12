<?php
namespace App\Modules\Workspace\Models;
use App\Support\BusinessModel;
final class WorkspaceRestriction extends BusinessModel
{
    protected $table = 'workspace_restrictions';
    protected function casts(): array { return parent::casts() + ['released_at' => 'datetime']; }
    public function workspace() { return $this->belongsTo(Workspace::class); }
}
