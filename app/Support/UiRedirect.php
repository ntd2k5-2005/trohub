<?php
namespace App\Support;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;

final class UiRedirect
{
    public static function success(Request $request,string $message):RedirectResponse
    {
        $previous=url()->previous();$parts=parse_url($previous);$query=[];parse_str($parts['query']??'',$query);
        $origin=($parts['scheme']??'').'://'.($parts['host']??'').(isset($parts['port'])?':'.$parts['port']:'');
        if($origin===$request->getSchemeAndHttpHost()&&str_starts_with($parts['path']??'','/ui/')&&isset($query['item'])){
            unset($query['item']);return redirect()->to($origin.$parts['path'].($query?'?'.http_build_query($query):''))->with('status',$message);
        }
        return back()->with('status',$message);
    }
}
