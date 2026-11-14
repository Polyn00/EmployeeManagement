$ErrorActionPreference = 'Stop'

$php = 'C:\xampp\php\php.exe'
if (-not (Test-Path -LiteralPath $php)) {
    $command = Get-Command php -ErrorAction SilentlyContinue
    if ($null -eq $command) {
        throw 'PHP CLI was not found. Set the PHP path in this script or add PHP to PATH.'
    }
    $php = $command.Source
}

$username = Read-Host 'New administrator username'
$password = Read-Host 'New administrator password (12–72 bytes)' -AsSecureString
$confirmation = Read-Host 'Confirm administrator password' -AsSecureString
$passwordPointer = [IntPtr]::Zero
$confirmationPointer = [IntPtr]::Zero

try {
    $passwordPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($password)
    $confirmationPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($confirmation)
    $env:PEOPLESUITE_ADMIN_PASSWORD = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($passwordPointer)
    $env:PEOPLESUITE_ADMIN_PASSWORD_CONFIRMATION = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($confirmationPointer)

    & $php (Join-Path $PSScriptRoot 'create-admin.php') $username
    if ($LASTEXITCODE -ne 0) {
        exit $LASTEXITCODE
    }
} finally {
    Remove-Item Env:\PEOPLESUITE_ADMIN_PASSWORD -ErrorAction SilentlyContinue
    Remove-Item Env:\PEOPLESUITE_ADMIN_PASSWORD_CONFIRMATION -ErrorAction SilentlyContinue
    if ($passwordPointer -ne [IntPtr]::Zero) {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($passwordPointer)
    }
    if ($confirmationPointer -ne [IntPtr]::Zero) {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($confirmationPointer)
    }
    if ($null -ne $password) {
        $password.Dispose()
    }
    if ($null -ne $confirmation) {
        $confirmation.Dispose()
    }
}
