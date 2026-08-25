$ErrorActionPreference = 'Stop'

$repository = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '../../..')).Path

function Get-ClassIdentity {
	param([Parameter(Mandatory = $true)][string]$Content)

	$namespace = [regex]::Match($Content, '(?m)^namespace\s+([^;]+);').Groups[1].Value.Trim()
	$class = [regex]::Match($Content, '(?m)^(?:(?:final|abstract)\s+)?(?:class|interface)\s+(\w+)').Groups[1].Value
	if (-not $namespace -or -not $class) {
		throw 'Could not parse a production class identity.'
	}
	return $namespace + '\' + $class
}

Push-Location $repository
try {
	$baselinePaths = @(git -c safe.directory=E:/development/dreamax-license-manager ls-tree -r --name-only main src | Where-Object { $_ -like '*.php' })
	$currentPaths = @(Get-ChildItem -LiteralPath src -Recurse -File -Filter *.php | ForEach-Object { $_.FullName.Substring($repository.Length + 1).Replace('\', '/') })
	$baselineMap = @{}
	$currentMap = @{}

	foreach ($path in $baselinePaths) {
		$identity = Get-ClassIdentity -Content ((git -c safe.directory=E:/development/dreamax-license-manager show ('main:' + $path)) -join "`n")
		if ($baselineMap.ContainsKey($identity)) {
			throw 'Duplicate class in main baseline: ' + $identity
		}
		$baselineMap[$identity] = $path
	}

	foreach ($path in $currentPaths) {
		$identity = Get-ClassIdentity -Content (Get-Content -Raw -LiteralPath $path)
		if ($currentMap.ContainsKey($identity)) {
			throw 'Duplicate current class: ' + $identity
		}
		$currentMap[$identity] = $path
	}

	$expectedAdded = @(
		'Dreamax\LicenseManager\CustomerPortal\GuestClaimPolicy',
		'Dreamax\LicenseManager\CustomerPortal\GuestClaimService',
		'Dreamax\LicenseManager\CustomerPortal\GuestClaimToken',
		'Dreamax\LicenseManager\Credentials\CredentialPolicy',
		'Dreamax\LicenseManager\Credentials\CredentialToken',
		'Dreamax\LicenseManager\Events\AuditEventCatalog',
		'Dreamax\LicenseManager\Events\AuditMetadata',
		'Dreamax\LicenseManager\Integrations\WooCommerce\OrderAccessPolicy',
		'Dreamax\LicenseManager\Encryption\KdfSalt',
		'Dreamax\LicenseManager\Encryption\KdfSaltRepository',
		'Dreamax\LicenseManager\Encryption\WordPressKdfSaltRepository'
	)
	$missing = @($baselineMap.Keys | Where-Object { -not $currentMap.ContainsKey($_) })
	$added = @($currentMap.Keys | Where-Object { -not $baselineMap.ContainsKey($_) })
	$unexpectedAdded = @($added | Where-Object { $_ -notin $expectedAdded })
	$missingAdded = @($expectedAdded | Where-Object { $_ -notin $added })
	if (41 -ne $baselineMap.Count -or 52 -ne $currentMap.Count -or $missing.Count -or $unexpectedAdded.Count -or $missingAdded.Count) {
		throw "Class identity mismatch: baseline=$($baselineMap.Count), current=$($currentMap.Count), missing=$($missing -join ','), unexpected_added=$($unexpectedAdded -join ','), missing_added=$($missingAdded -join ',')"
	}

	$oldPaths = @($baselinePaths)
	if (41 -ne $oldPaths.Count) {
		throw "Expected 41 renamed source paths; found $($oldPaths.Count)."
	}
	$hits = @()
	foreach ($oldPath in $oldPaths) {
		$oldName = Split-Path -Leaf $oldPath
		foreach ($needle in @($oldPath, $oldName)) {
			$result = & rg -n -F --glob '!vendor/**' --glob '!.git/**' --glob '!build/**' -- $needle . 2>$null
			if (0 -eq $LASTEXITCODE) {
				$hits += $result
			} elseif (1 -ne $LASTEXITCODE) {
				throw "Reference scan failed for $needle."
			}
		}
	}
	if ($hits.Count) {
		throw 'Old source-path references remain: ' + (($hits | Sort-Object -Unique) -join '; ')
	}

	Write-Output 'PASS: all 41 main-baseline identities and 11 intentional additions are present; no missing or duplicate class/interface; no old production PHP path or filename reference remains.'
} finally {
	Pop-Location
}
