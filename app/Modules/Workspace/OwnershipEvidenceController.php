<?php
namespace App\Modules\Workspace;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
final class OwnershipEvidenceController extends Controller {
    public function __invoke(Request$r,int$case,int$evidence){$file=app(OwnerTransferService::class)->file($r->user(),$case,$evidence);return Storage::disk('local')->download($file->path,$file->filename,['Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']);}
}
