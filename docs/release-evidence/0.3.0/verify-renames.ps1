$ErrorActionPreference = 'Stop'

$repository = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '../../..')).Path

function Get-ClassIdentity {
	param([Parameter(Mandatory = $true)][string]$Content)

	$namespace = [regex]::Match($Content, '(?m)^namespace\s+([^;]+);').Groups[1].Value.Trim()
	$class = [regex]::Match($Content, '(?m)^(?:final\s+)?class\s+(\w+)').Groups[1].Value
	if (-not $namespace -or -not $class) {
		throw 'Could not parse a production class identity.'
	}
	return $namespace + '\' + $class
}

Push-Location $repository
try {
	$headPaths = @(git -c safe.directory=E:/development/dreamax-license-manager ls-tree -r --name-only HEAD src | Where-Object { $_ -like '*.php' })
	$currentPaths = @(Get-ChildItem -LiteralPath src -Recurse -File -Filter *.php | ForEach-Object { $_.FullName.Substring($repository.Length + 1).Replace('\', '/') })
	$headMap = @{}
	$currentMap = @{}

	foreach ($path in $headPaths) {
		$identity = Get-ClassIdentity -Content ((git -c safe.directory=E:/development/dreamax-license-manager show ('HEAD:' + $path)) -join "`n")
		if ($headMap.ContainsKey($identity)) {
			throw 'Duplicate class in HEAD: ' + $identity
		}
		$headMap[$identity] = $path
	}

	foreach ($path in $currentPaths) {
		$identity = Get-ClassIdentity -Content (Get-Content -Raw -LiteralPath $path)
		if ($currentMap.ContainsKey($identity)) {
			throw 'Duplicate current class: ' + $identity
		}
		$currentMap[$identity] = $path
	}

	$missing = @($headMap.Keys | Where-Object { -not $currentMap.ContainsKey($_) })
	$added = @($currentMap.Keys | Where-Object { -not $headMap.ContainsKey($_) })
	if (41 -ne $headMap.Count -or 41 -ne $currentMap.Count -or $missing.Count -or $added.Count) {
		throw "Class identity mismatch: HEAD=$($headMap.Count), current=$($currentMap.Count), missing=$($missing -join ','), added=$($added -join ',')"
	}

	$oldPaths = @(git -c safe.directory=E:/development/dreamax-license-manager diff --diff-filter=D --name-only -- src | Where-Object { $_ -like '*.php' })
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

	Write-Output 'PASS: all 41 HEAD/current class identities match exactly; no missing or duplicate class; no old production PHP path or filename reference remains.'
} finally {
	Pop-Location
}
