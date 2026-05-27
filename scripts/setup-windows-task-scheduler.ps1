param(
    [string]$TaskName = "Hotel Website - Cache Clearing",
    [string]$PhpPath = ""
)

$ErrorActionPreference = 'Stop'

if (-not $PhpPath) {
    $phpCmd = Get-Command php -ErrorAction SilentlyContinue
    if (-not $phpCmd) {
        throw "PHP executable was not found in PATH. Provide -PhpPath explicitly."
    }
    $PhpPath = $phpCmd.Source
}

$projectRoot = Split-Path -Parent $PSScriptRoot
$scriptPath = Join-Path $projectRoot "scripts\scheduled-cache-clear.php"

if (-not (Test-Path $scriptPath)) {
    throw "Scheduled cache script not found: $scriptPath"
}

$action = New-ScheduledTaskAction -Execute $PhpPath -Argument ('"' + $scriptPath + '" --quiet') -WorkingDirectory $projectRoot
$trigger = New-ScheduledTaskTrigger -Daily -At 00:00
$trigger.RepetitionInterval = (New-TimeSpan -Minutes 1)
$trigger.RepetitionDuration = (New-TimeSpan -Days 1)

$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable
$principal = New-ScheduledTaskPrincipal -UserId "SYSTEM" -LogonType ServiceAccount -RunLevel Highest

Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings -Principal $principal -Description "Runs scheduled-cache-clear.php every minute" -Force | Out-Null

Write-Host "Task registered/updated successfully."
Write-Host "Task Name: $TaskName"
Write-Host "PHP Path : $PhpPath"
Write-Host "Script   : $scriptPath"
Write-Host "Schedule : Daily trigger, repeating every 1 minute"
