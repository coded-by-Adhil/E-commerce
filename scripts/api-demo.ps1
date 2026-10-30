# Runs the whole auth flow against the local API and prints each response.
# Usage (server must be running):  powershell -File scripts/api-demo.ps1
param([string]$Base = 'http://localhost:8000')

$email = "demo$(Get-Random)@gmail.com"
$headers = @{ Accept = 'application/json'; Origin = 'http://localhost:5173'; Referer = 'http://localhost:5173' }
$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession

function Call($title, $method, $path, $body = $null) {
    # CSRF ticket: copy the XSRF-TOKEN cookie into the X-XSRF-TOKEN header.
    $cookie = $session.Cookies.GetCookies($Base) | Where-Object Name -eq 'XSRF-TOKEN'
    $h = $headers.Clone()
    if ($cookie) { $h['X-XSRF-TOKEN'] = [uri]::UnescapeDataString($cookie.Value) }

    $params = @{ Uri = "$Base$path"; Method = $method; Headers = $h; WebSession = $session; UseBasicParsing = $true }
    if ($body) { $params.Body = ($body | ConvertTo-Json); $params.ContentType = 'application/json' }

    try { $r = Invoke-WebRequest @params; $code = $r.StatusCode; $text = $r.Content }
    catch {
        $code = [int]$_.Exception.Response.StatusCode
        $text = (New-Object IO.StreamReader($_.Exception.Response.GetResponseStream())).ReadToEnd()
    }
    Write-Host "`n== $title  ->  $code" -ForegroundColor Cyan
    Write-Host $text
}

Call '1. Get CSRF cookie'        GET  '/sanctum/csrf-cookie'
Call '2. Register (bad email)'   POST '/api/v1/register' @{ name = 'Ada'; email = 'example@gm'; password = 'secret-pass-1'; password_confirmation = 'secret-pass-1' }
Call '3. Register'               POST '/api/v1/register' @{ name = 'Ada'; email = $email; password = 'secret-pass-1'; password_confirmation = 'secret-pass-1' }
Call '4. Me (logged in)'         GET  '/api/v1/me'
Call '5. Logout'                 POST '/api/v1/logout'
Call '6. Me (after logout)'      GET  '/api/v1/me'
Call '7. Get CSRF cookie again'  GET  '/sanctum/csrf-cookie'
Call '8. Login (wrong password)' POST '/api/v1/login' @{ email = $email; password = 'wrong-password' }
Call '9. Login'                  POST '/api/v1/login' @{ email = $email; password = 'secret-pass-1' }
