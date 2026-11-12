param([switch]$Light,[switch]$NoBrowser)
$ErrorActionPreference='Stop'
$deliveryRoot=(Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Set-Location -LiteralPath $deliveryRoot
function Invoke-Docker {
    param([string[]]$DockerArguments)
    $previousPreference=$ErrorActionPreference
    try {
        $ErrorActionPreference='Continue'
        & docker @DockerArguments
        $dockerExitCode=$LASTEXITCODE
    } finally {$ErrorActionPreference=$previousPreference}
    if($dockerExitCode -ne 0){throw "Docker command failed (exit $dockerExitCode)."}
}
if(-not(Get-Command docker -ErrorAction SilentlyContinue)){
    throw 'Please install Docker Desktop, enable Linux containers, then run CHAY.bat again.'
}
$ErrorActionPreference='Continue'
& docker info --format '{{.OSType}}' 2>$null | Out-Null
$engineExitCode=$LASTEXITCODE
$ErrorActionPreference='Stop'
if($engineExitCode -ne 0){throw 'Open Docker Desktop and wait until Engine is running, then run CHAY.bat again.'}
function New-DeliverySecret {
    $bytes=New-Object byte[] 32
    $random=[System.Security.Cryptography.RandomNumberGenerator]::Create()
    try{$random.GetBytes($bytes)}finally{$random.Dispose()}
    return ([BitConverter]::ToString($bytes)).Replace('-','').ToLowerInvariant()
}
if(-not(Test-Path -LiteralPath '.env')){
    $template=[IO.File]::ReadAllText((Join-Path $deliveryRoot '.env.example'))
    $values=@{DB_PASSWORD=(New-DeliverySecret);DB_ROOT_PASSWORD=(New-DeliverySecret);NEO4J_PASSWORD=(New-DeliverySecret);MEILISEARCH_KEY=(New-DeliverySecret);APP_PORT='8088';MAIL_PORT='8025';DEMO_PASSWORD='Abcd@1234'}
    foreach($key in $values.Keys){
        if($template -match "(?m)^$key=.*$"){$template=[regex]::Replace($template,"(?m)^$key=.*$",$key+'='+$values[$key])}
        else{$template+="`n$key=$($values[$key])`n"}
    }
    [IO.File]::WriteAllText((Join-Path $deliveryRoot '.env'),$template,(New-Object Text.UTF8Encoding($false)))
}
$compose=@('compose','-f','compose.yaml')
if(-not $Light){$compose+=@('-f','compose.full.yaml')}
Write-Host 'Building and starting TroHub. First run downloads images and dependencies; please wait.'
Invoke-Docker -DockerArguments ($compose+@('up','-d','--build','--remove-orphans','--wait','--wait-timeout','900'))
$settings=[IO.File]::ReadAllText((Join-Path $deliveryRoot '.env'))
$appPort='8088';$mailPort='8025'
$appPortMatches=[regex]::Matches($settings,'(?m)^APP_PORT=(\d+)\s*$')
$mailPortMatches=[regex]::Matches($settings,'(?m)^MAIL_PORT=(\d+)\s*$')
if($appPortMatches.Count){$appPort=$appPortMatches[$appPortMatches.Count-1].Groups[1].Value}
if($mailPortMatches.Count){$mailPort=$mailPortMatches[$mailPortMatches.Count-1].Groups[1].Value}
Write-Host "TroHub: http://localhost:$appPort"
Write-Host "Email inbox (Mailpit): http://localhost:$mailPort"
if(-not $NoBrowser){Start-Process "http://localhost:$appPort"}
