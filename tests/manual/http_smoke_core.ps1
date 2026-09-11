$ErrorActionPreference = 'Continue'
$results = @()

function Add-Result($id, $step, $expect, $actual, $pass) {
    $script:results += [pscustomobject]@{
        ID = $id; Step = $step; Expect = $expect; Actual = $actual; Pass = $pass
    }
}

# PS-HTTP-001: /api bootstrap payload carries the extension attributes
try {
    $r = Invoke-WebRequest -Uri 'http://localhost/api' -UseBasicParsing -TimeoutSec 10
    $text = if ($r.Content -is [byte[]]) { [Text.Encoding]::UTF8.GetString($r.Content) } else { $r.Content }
    $j = $text | ConvertFrom-Json
    $names = @($j.data.attributes.PSObject.Properties.Name) | Where-Object { $_ -match 'pointSystem' }
    Add-Result 'PS-HTTP-001' 'GET /api' '200 + >=30 pointSystem* attrs' "HTTP $($r.StatusCode), attrs: $($names.Count)" ($r.StatusCode -eq 200 -and $names.Count -ge 30)
} catch { Add-Result 'PS-HTTP-001' 'GET /api' '200 + attrs' "ERR: $($_.Exception.Message)" $false }

# PS-HTTP-002: forum home references extension assets
try {
    $r = Invoke-WebRequest -Uri 'http://localhost/' -UseBasicParsing -TimeoutSec 10
    $hasPoint = ($r.Content -is [byte[]] -and [Text.Encoding]::UTF8.GetString($r.Content) -match 'point-system') -or ($r.Content -is [string] -and $r.Content -match 'point-system')
    Add-Result 'PS-HTTP-002' 'GET /' '200 + asset ref' "HTTP $($r.StatusCode), ref: $hasPoint" ($r.StatusCode -eq 200 -and $hasPoint)
} catch { Add-Result 'PS-HTTP-002' 'GET /' '200' "ERR: $($_.Exception.Message)" $false }

# PS-HTTP-003: shop SPA route renders the shell
try {
    $r = Invoke-WebRequest -Uri 'http://localhost/rewards' -UseBasicParsing -TimeoutSec 10
    Add-Result 'PS-HTTP-003' 'GET /rewards' '200 (SPA shell)' "HTTP $($r.StatusCode)" ($r.StatusCode -eq 200)
} catch { Add-Result 'PS-HTTP-003' 'GET /rewards' '200' "ERR: $($_.Exception.Message)" $false }

# PS-HTTP-004: anonymous mutation rejected (CSRF middleware precedes auth)
try {
    $r = Invoke-WebRequest -Uri 'http://localhost/api/point-system/checkin' -Method POST -ContentType 'application/json' -Body '{"confirm": true}' -UseBasicParsing -TimeoutSec 10
    Add-Result 'PS-HTTP-004' 'POST checkin (anon)' '4xx rejected' "HTTP $($r.StatusCode)" $false
} catch {
    $code = $_.Exception.Response.StatusCode.value__
    Add-Result 'PS-HTTP-004' 'POST checkin (anon)' '4xx rejected' "HTTP $code" ($code -ge 400 -and $code -lt 500)
}

# PS-HTTP-005: anonymous claim rejected
try {
    $r = Invoke-WebRequest -Uri 'http://localhost/api/point-system/claim/1' -Method POST -ContentType 'application/json' -Body '{"type":"avatar_decoration"}' -UseBasicParsing -TimeoutSec 10
    Add-Result 'PS-HTTP-005' 'POST claim (anon)' '4xx rejected' "HTTP $($r.StatusCode)" $false
} catch {
    $code = $_.Exception.Response.StatusCode.value__
    Add-Result 'PS-HTTP-005' 'POST claim (anon)' '4xx rejected' "HTTP $code" ($code -ge 400 -and $code -lt 500)
}

# PS-HTTP-006: anonymous ledger access refused (correct routes)
foreach ($path in @('/api/point-system/admin/transactions', '/api/point-system/users/1/transactions')) {
    try {
        $r = Invoke-WebRequest -Uri "http://localhost$path" -UseBasicParsing -TimeoutSec 10
        Add-Result 'PS-HTTP-006' "GET $path (anon)" '401/403 refused' "HTTP $($r.StatusCode)" $false
    } catch {
        $code = $_.Exception.Response.StatusCode.value__
        Add-Result 'PS-HTTP-006' "GET $path (anon)" '401/403 refused' "HTTP $code" ($code -eq 401 -or $code -eq 403)
    }
}

# PS-HTTP-007/008: unknown + legacy-mistaken ledger paths must 404
foreach ($path in @('/api/point-system/definitely-not-a-route', '/api/point-system/transactions')) {
    try {
        $r = Invoke-WebRequest -Uri "http://localhost$path" -UseBasicParsing -TimeoutSec 10
        Add-Result 'PS-HTTP-007' "GET $path" '404' "HTTP $($r.StatusCode)" ($r.StatusCode -eq 404)
    } catch {
        $code = $_.Exception.Response.StatusCode.value__
        Add-Result 'PS-HTTP-007' "GET $path" '404' "HTTP $code" ($code -eq 404)
    }
}

$results | Format-Table -AutoSize -Wrap
$pass = ($results | Where-Object Pass).Count
Write-Host "SMOKE RESULT: $pass / $($results.Count) PASS"
